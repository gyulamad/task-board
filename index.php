<?php
declare(strict_types=1);
define('TASK_BOARD_BOOTSTRAPPED', true);

// Harden the session cookie before the session starts: HttpOnly keeps the
// session id away from JavaScript, SameSite=Lax mitigates cross-site request
// timing/CSRF exposure, Secure is enabled whenever the request arrived via HTTPS.
$tbHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || strcasecmp((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 'https') === 0;
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $tbHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('Missing config.php. Copy config.example.php to config.php and configure the database.');
}
$config = require $configFile;

try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database connection failed. Check config.php.');
}

// Deployment guard: a partial upload, or an update installed before the src/
// directory existed, used to die here as an opaque HTTP 500 with an empty
// body. Fail with an actionable message instead.
if (!is_file(__DIR__ . '/src/helpers.php') || !is_file(__DIR__ . '/src/crud.php')) {
    http_response_code(500);
    exit('Application files are incomplete: the src/ directory (helpers.php, crud.php) was not found on the server. Upload the full repository, including the src/ directory, and try again.');
}
require __DIR__ . '/src/helpers.php';
require __DIR__ . '/src/crud.php';
ensure_users_theme_column($pdo);

// The render layer's request handle (#108): every datetime render goes through
// tb_dt()/tb_ago()/tb_user_timezone(), which need the connection to read the viewer's
// timezone preference. Since #108 the stamps themselves are epoch seconds
// written with PHP's time(), so the old database-clock capture (#14, the
// "dot is always red" fix) is structurally obsolete - one clock, no skew.
$GLOBALS['TB_PDO'] = $pdo;
$GLOBALS['TB_CONFIG'] = $config;   // the render/migration layers read settings from here too (#108)

// Basic response hardening for every page (including CSRF/403 rejections).
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; font-src 'self' https://cdn.jsdelivr.net; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
if (user()) {
    // Authenticated pages hold private data; keep browsers (and bfcache) from storing them.
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
}

// Idempotent schema self-heal (#18 scheduler, #12 archive, #24 projects):
// existing databases upgrade in place on the first request after a deployment,
// so shipping an update never breaks the board with a missing column or table.
try {
    tb_migrate_schema($pdo);
} catch (Throwable $e) {
    // Ignore: pages report clear errors if a schema part is still missing and
    // the SQL for a manual migration is documented in README.md.
}

// Version-marked SQL migrations (#59/#68): every migrations/<version>-<slug>.sql
// file that has not run yet is applied automatically on the first request that
// sees it - whether it arrived through a git deployment, an upgrade pack or a
// manual upload - so a schema change never needs hand-run SQL. A failing
// migration is reported on the Software updates page; it never breaks the
// request and simply retries on the next one.
try {
    tb_apply_pending_migrations($pdo);
} catch (Throwable $e) {
    // Ignore: the same philosophy as the schema self-heal above.
}

// Activity status periods (#34): load the administrator's configured period
// list once per request so every dot on every page (navbar, admin table,
// task/creator/assignee renderings) ages stamps against the same periods. A
// board whose app_settings table is not there yet simply runs on the
// built-in periods; the self-heal above recreates the table.
try {
    $GLOBALS['TB_ACTIVITY_LEVELS'] = tb_load_activity_levels($pdo);
} catch (Throwable $e) {
    // tb_load_activity_levels already falls back to the defaults internally;
    // this guard only keeps an unexpected storage failure from fataling.
}

// The logged-out indicator (#106): loaded once per request like the activity
// periods, so every signed-out user's dot renders with the same configured
// shape (grey empty circle by default, an administrator-chosen colour or
// character, or the disabled state that keeps the plain activity dot). The
// same not-there-yet tolerance applies: a board whose app_settings table is
// not created yet runs on the built-in default.
try {
    $GLOBALS['TB_LOGGED_OUT_INDICATOR'] = tb_load_logged_out_indicator($pdo);
} catch (Throwable $e) {
    // tb_load_logged_out_indicator already falls back to the default
    // internally; this guard only keeps an unexpected storage failure from
    // fataling.
    if (!array_key_exists('TB_LOGGED_OUT_INDICATOR', $GLOBALS)) {
        $GLOBALS['TB_LOGGED_OUT_INDICATOR'] = tb_logged_out_indicator_default();
    }
}

// Last-activity tracking (#30): every authenticated request refreshes the
// user's last_seen_at (the write is throttled inside the helper), so the
// admin page can tell active users from inactive ones. Runs after the schema
// self-heal, so the column it writes exists by the time it fires.
if (user()) {
    tb_touch_last_seen($pdo);
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
verify_csrf();

$page = $_GET['page'] ?? 'tasks';
$error = '';

// The list view memory (#113): capture the current selection, replay the
// stored one when arriving from another page, honour the explicit clear -
// before anything reads $_GET, so every reader sees the same view. The
// connection mirrors every capture into the user_list_views table (#120),
// so the stored views survive logout/login.
tb_list_view_boot($page, $pdo);

// Sign-out is POST-only and CSRF-protected, so a crafted <img src="?page=logout">
// link or other cross-site GET request can no longer force users to log out.
if ($page === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Logged-out status (#106): mark the explicit sign-out on the user row
        // BEFORE the session dies, so every dot the board renders shows the
        // configured logged-out indicator instead of the activity periods
        // until the next successful sign-in clears it. A session that dies
        // silently (cookie expiry, browser close) never runs this, so only a
        // real sign-out changes what the dot shows. The write must not block
        // the sign-out: a storage failure logs out anyway and the flag stays
        // at its previous value.
        try {
            $pdo->prepare('UPDATE users SET logged_out=1 WHERE id=?')->execute([(int)user()['id']]);
        } catch (Throwable $e) {
            error_log('Task board: could not mark the sign-out: ' . $e->getMessage());
        }
        tb_session_destroy();
        redirect('?page=login');
    }
    redirect(user() ? '?' : '?page=login');
}

if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Brute-force throttle: bucket failed attempts per client address for 10
    // minutes. Behind proxies the forwarded-for header identifies the client;
    // the delay below keeps guessing slow even when that header is spoofed.
    $tbIp = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
    if ($tbIp === '') $tbIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (tb_login_throttled($tbIp)) {
        $error = 'Too many failed sign-in attempts. Please try again in a few minutes.';
    } else {
        // A static dummy hash keeps password_verify() running even when the
        // username does not exist, so response timing cannot reveal valid usernames.
        $tbDummyHash = '$2y$10$K8xQ6eL9vXJ2rYc5wO1sPuG7tN3mB4aD0fHjR2iC6eV8kS1lZ3TqW';
        $tbOk = false;
        $u = null;
        try {
            $s = $pdo->prepare('SELECT * FROM users WHERE username=?');
            $s->execute([trim($_POST['username'] ?? '')]);
            $u = $s->fetch();
            $tbHash = is_array($u) ? (string)$u['password_hash'] : $tbDummyHash;
            $tbOk = is_array($u) && password_verify($_POST['password'] ?? '', $tbHash);
        } catch (Throwable $e) {
            error_log('Task board: sign-in error: ' . $e->getMessage());
        }
        if ($tbOk && is_array($u)) {
            tb_login_throttle_clear($tbIp);
            session_regenerate_id(true);
            // Logged-out status (#106): a successful sign-in clears the
            // logged-out marker the previous sign-out left, so the user's dot
            // returns to the activity periods from this very request on.
            $pdo->prepare('UPDATE users SET logged_out=0 WHERE id=?')->execute([(int)$u['id']]);
            $u['logged_out'] = 0;
            $_SESSION['user'] = tb_session_user($u);
            redirect('?');
        }
        tb_login_throttle_fail($tbIp);
        usleep(random_int(200000, 500000));
        $error = 'Invalid username or password.';
    }
}

if ($page === 'install') {
    // Disable the installer completely after the first account exists.
    // This protects direct GET requests and POST submissions alike.
    if (user_count($pdo) > 0) {
        http_response_code(404);
        exit('Not found.');
    }
}

if ($page === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Run schema.sql manually first. This creates the first administrator.
    if (user_count($pdo) > 0) $error = 'An account already exists. Sign in instead.';
    elseif (strlen($_POST['password'] ?? '') < 10) $error = 'Use a password with at least 10 characters.';
    elseif (tb_char_length(trim((string)($_POST['username'] ?? ''))) > 80) $error = 'Username must be 80 characters or fewer.';
    else {
        try {
            $s = $pdo->prepare("INSERT INTO users(username,password_hash,display_name,role,created_at) VALUES(?,?,?,'admin',?)");
            $s->execute([trim($_POST['username']), password_hash($_POST['password'], PASSWORD_DEFAULT), trim($_POST['display_name']), time()]);
            flash('Administrator created. Please sign in.'); redirect('?page=login');
        } catch (PDOException $e) {
            // Duplicate username or database trouble: never leak driver details.
            error_log('Task board: installer error: ' . $e->getMessage());
            $error = 'Could not create the administrator account. The username may already be taken.';
        }
    }
}

if (user()) {
    // Idle session timeout (12 hours by default, configurable via config.php).
    $tbIdleSeconds = max(0, (int)($config['session_idle_seconds'] ?? 43200));
    if ($tbIdleSeconds > 0 && isset($_SESSION['_last_activity']) && time() - (int)$_SESSION['_last_activity'] > $tbIdleSeconds) {
        tb_session_destroy();
        redirect('?page=login');
    }
    $_SESSION['_last_activity'] = time();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        try {
            if ($action === 'save_task') {
                $id = (int)($_POST['id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $statusId = (int)($_POST['status_id'] ?? 0);
                $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
                $schedulePeriod = trim((string)($_POST['schedule_period'] ?? ''));
                if ($schedulePeriod === '') $schedulePeriod = null;
                elseif (!array_key_exists($schedulePeriod, schedule_periods())) throw new RuntimeException('Invalid schedule period.');
                // Scheduler targets (#31): where the cron puts the task when the
                // schedule fires. Empty = the #18 defaults (TODO, unassigned),
                // so a period-only schedule behaves exactly as it always did.
                $scheduleStatusId = (int)($_POST['schedule_status_id'] ?? 0) ?: null;
                if ($scheduleStatusId !== null && !status_exists($pdo, $scheduleStatusId)) throw new RuntimeException('Choose a valid schedule status.');
                $scheduleAssignees = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['schedule_assignees'] ?? [])), fn(int $v): bool => $v > 0)));
                foreach ($scheduleAssignees as $scheduleUid) {
                    if (!user_exists($pdo, $scheduleUid)) throw new RuntimeException('Choose valid schedule assignees.');
                }
                $scheduleAssigneesCsv = $scheduleAssignees ? implode(',', $scheduleAssignees) : null;
                if ($title === '') throw new RuntimeException('Task title is required.');
                if (tb_char_length($title) > 200) throw new RuntimeException('Task title must be 200 characters or fewer.');
                if ($parentId && ($parentId === $id || !task_exists($pdo, $parentId))) throw new RuntimeException('Invalid parent task.');
                if (!status_exists($pdo, $statusId)) throw new RuntimeException('Choose a valid status.');
                // Complexity estimation (#44): a whole number 1..1000, default 1.
                // It drives the block width on the dependency waterflow chart.
                $complexityRaw = trim((string)($_POST['complexity'] ?? '1'));
                if (!preg_match('/^\d+$/', $complexityRaw) || (int)$complexityRaw < 1 || (int)$complexityRaw > 1000) throw new RuntimeException('Complexity must be a whole number between 1 and 1000.');
                $complexity = (int)$complexityRaw;
                $projectId = (int)($_POST['project_id'] ?? 0) ?: null;
                if ($projectId && !project_exists($pdo, $projectId)) throw new RuntimeException('Invalid project.');
                $assignees = array_values(array_unique(array_map('intval', $_POST['assignees'] ?? [])));
                // Priority (#45): a whole number 1..1000, default 1 - the
                // ordering weight shown on the task list's Priority column.
                $priorityRaw = trim((string)($_POST['priority'] ?? '1'));
                if (!preg_match('/^\d+$/', $priorityRaw) || (int)$priorityRaw < 1 || (int)$priorityRaw > 1000) throw new RuntimeException('Priority must be a whole number between 1 and 1000.');
                $priority = (int)$priorityRaw;
                if ($id) {
                    $old = find_task($pdo, $id);
                    if (!$old) throw new RuntimeException('Task not found.');
                    // Full audit (#54): the assignee roster must be captured
                    // before the delete below rewrites it.
                    $hStmt=$pdo->prepare('SELECT user_id FROM task_assignees WHERE task_id=?'); $hStmt->execute([$id]);
                    $oldAssigneeIds=array_map('intval', $hStmt->fetchAll(PDO::FETCH_COLUMN));
                    $pdo->prepare('UPDATE tasks SET title=?,description=?,status_id=?,complexity=?,priority=?,parent_id=?,schedule_period=?,schedule_status_id=?,schedule_assignees=?,project_id=? WHERE id=?')->execute([$title,$description,$statusId,$complexity,$priority,$parentId,$schedulePeriod,$scheduleStatusId,$scheduleAssigneesCsv,$projectId,$id]);
                    $pdo->prepare('DELETE FROM task_assignees WHERE task_id=?')->execute([$id]);
                    // Field-level audit (#54): one history row per changed
                    // field with the from/to values recorded human-readable
                    // (names and titles, not ids), so the change details page
                    // shows exactly who changed what from what.
                    $audit=[];
                    $tbAudit=function(string $field, string $label, ?string $oldValue, ?string $newValue) use (&$audit): void {
                        if (($oldValue ?? '') !== ($newValue ?? '')) $audit[]=[$field,$label,$oldValue ?? '—',$newValue ?? '—'];
                    };
                    $statusNames=[];
                    foreach (all_statuses($pdo) as $st) $statusNames[(int)$st['id']]=(string)$st['name'];
                    $userNames=[];
                    foreach ($pdo->query('SELECT id, display_name FROM users') as $u) $userNames[(int)$u['id']]=(string)$u['display_name'];
                    $taskTitle=function(int $tid) use ($pdo): ?string { $ts=$pdo->prepare('SELECT title FROM tasks WHERE id=?'); $ts->execute([$tid]); $t=(string)$ts->fetchColumn(); return $t!==''?$t:null; };
                    $projectName=function(int $pid) use ($pdo): ?string { $ps=$pdo->prepare('SELECT name FROM projects WHERE id=?'); $ps->execute([$pid]); $p=(string)$ps->fetchColumn(); return $p!==''?$p:null; };
                    $namesFor=function(array $ids) use ($userNames): string { $n=array_map(fn(int $i): string => $userNames[$i] ?? ('#'.$i), $ids); return $n?implode(', ',$n):'—'; };
                    $tbAudit('title','Title',(string)$old['title'],$title);
                    $tbAudit('description','Description',(string)$old['description'],$description);
                    $tbAudit('status','Status',$statusNames[(int)$old['status_id']] ?? null,$statusNames[$statusId] ?? null);
                    $tbAudit('complexity','Complexity',(string)$old['complexity'],(string)$complexity);
                    $tbAudit('priority','Priority',(string)$old['priority'],(string)$priority);
                    $tbAudit('parent','Parent task',$old['parent_id']?$taskTitle((int)$old['parent_id']):null,$parentId?$taskTitle((int)$parentId):null);
                    $tbAudit('project','Project',$old['project_id']?$projectName((int)$old['project_id']):null,$projectId?$projectName((int)$projectId):null);
                    $tbAudit('schedule_period','Schedule period',$old['schedule_period'],$schedulePeriod);
                    $tbAudit('schedule_status','Schedule status',$old['schedule_status_id']?($statusNames[(int)$old['schedule_status_id']] ?? null):null,$scheduleStatusId?($statusNames[(int)$scheduleStatusId] ?? null):null);
                    $oldScheduleNames=$namesFor(array_filter(array_map('intval', explode(',', (string)$old['schedule_assignees']))));
                    $tbAudit('schedule_assignees','Schedule assignees',$oldScheduleNames,$namesFor($scheduleAssignees));
                    if ($namesFor($oldAssigneeIds) !== $namesFor($assignees)) $audit[]=['assignees','Assignees',$namesFor($oldAssigneeIds),$namesFor($assignees)];
                    // #54 v2 feedback: the generic save entry says exactly what
                    // changed - the changed field labels plus the per-field
                    // from/to sentence - instead of a vague "may have changed".
                    // The row itself is written after the assignee insert below.
                    $saveEvent = ['Task updated', $audit
                        ? 'The followings are changed: '.implode(', ',array_column($audit,1)).'. '
                          .implode('; ',array_map(fn(array $a): string => $a[1].' changed from "'.$a[2].'" to "'.$a[3].'"',$audit)).'.'
                        : 'No fields were changed.'];
                } else {
                    $pdo->prepare('INSERT INTO tasks(title,description,status_id,complexity,priority,parent_id,schedule_period,schedule_status_id,schedule_assignees,project_id,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$title,$description,$statusId,$complexity,$priority,$parentId,$schedulePeriod,$scheduleStatusId,$scheduleAssigneesCsv,$projectId,user()['id'],time(),time()]);
                    $id = (int)$pdo->lastInsertId();
                    $saveEvent = ['Task created', $title];
                }
                // The event row is written only after the assignee rows are
                // persisted: history() notifies the task's assignees (#58) and
                // the list is only now final - a save that (re)assigned users
                // used to ping nobody because the notify ran between the delete
                // and the re-insert of task_assignees (CI fix).
                $a = $pdo->prepare('INSERT IGNORE INTO task_assignees(task_id,user_id) VALUES(?,?)');
                foreach ($assignees as $uid) $a->execute([$id,$uid]);
                history($pdo,$id,$saveEvent[0],$saveEvent[1]);
                if (!empty($audit)) foreach ($audit as [$hField,$hLabel,$hOld,$hNew]) history_change($pdo,$id,$hField,$hLabel,$hOld,$hNew);
                redirect('?page=task&id=' . $id);
            }
            if ($action === 'archive_task') {
                // Soft delete (#12): the task leaves the lists but stays restorable.
                $id = (int)$_POST['id'];
                $t = find_task($pdo, $id);
                if (!$t) throw new RuntimeException('Task not found.');
                if (!is_admin() && (int)$t['created_by'] !== (int)user()['id']) throw new RuntimeException('Only an administrator or task creator can archive this task.');
                $pdo->prepare('UPDATE tasks SET archived_at=?, updated_at=? WHERE id=? AND archived_at IS NULL')->execute([time(),time(),$id]);
                history($pdo, $id, 'Task archived', 'The task was moved to the archive. Restore it from the archive page.');
                flash('Task archived. Find it under Archive.');
                $returnUrl = $_POST['return'] ?? '';
                redirect(is_string($returnUrl) && str_starts_with($returnUrl, '?page=tasks') ? $returnUrl : '?page=task&id=' . $id);
            }
            if ($action === 'restore_task') {
                $id = (int)$_POST['id'];
                $pdo->prepare('UPDATE tasks SET archived_at=NULL, updated_at=? WHERE id=? AND archived_at IS NOT NULL')->execute([time(),$id]);
                history($pdo, $id, 'Task restored', 'The task was restored from the archive.');
                flash('Task restored.');
                redirect('?page=task&id=' . $id);
            }
            if ($action === 'delete_task') {
                // Hard delete (#12): only allowed on archived tasks, so everything
                // goes through the archive before it is removed permanently.
                $id = (int)$_POST['id'];
                $t = find_task($pdo, $id);
                if (!$t) throw new RuntimeException('Task not found.');
                if (!task_is_archived($t)) throw new RuntimeException('Archive the task before deleting it permanently.');
                if (!is_admin() && (int)$t['created_by'] !== (int)user()['id']) throw new RuntimeException('Only an administrator or task creator can delete this task.');
                // Orphan cleanup (#38 v2): capture the stored names before the
                // delete cascades the attachment rows, then hard-delete the
                // files too, so no upload is left behind in uploads/.
                $storedNames = tb_task_attachment_files($pdo, $id);
                $pdo->prepare('DELETE FROM tasks WHERE id=?')->execute([$id]);
                tb_unlink_stored_files($storedNames);
                $returnUrl = $_POST['return'] ?? '';
                flash('Task deleted permanently.');
                redirect(is_string($returnUrl) && (str_starts_with($returnUrl, '?page=tasks') || str_starts_with($returnUrl, '?page=archive')) ? $returnUrl : '?page=archive');
            }
            if ($action === 'bulk_archive_tasks') {
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['task_ids'] ?? [])), fn($v) => $v > 0)));
                if (!$ids) throw new RuntimeException('Select at least one task to archive.');
                $returnUrl = $_POST['return'] ?? '';
                $returnUrl = is_string($returnUrl) && str_starts_with($returnUrl, '?page=tasks') ? $returnUrl : '?';
                $result = tb_bulk_apply(
                    $pdo,
                    $ids,
                    'SELECT created_by FROM tasks WHERE id=?',
                    'UPDATE tasks SET archived_at=?, updated_at=? WHERE id=? AND archived_at IS NULL',
                    [time(), time()]
                );
                flash(tb_bulk_flash($result['done'], $result['skipped'], 'task', 'archived', 'only administrators or the task creator can archive a task.'));
                redirect($returnUrl);
            }
            if ($action === 'save_template') {
                $id = (int)($_POST['id'] ?? 0);
                $type = $_POST['type'] ?? 'task';
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                if (!in_array($type, ['task','project'], true)) throw new RuntimeException('Choose a valid template type.');
                if ($name === '') throw new RuntimeException('Template name is required.');
                if ($id) {
                    $s = $pdo->prepare('SELECT id FROM templates WHERE id=?'); $s->execute([$id]);
                    if (!$s->fetch()) throw new RuntimeException('Template not found.');
                    $pdo->prepare('UPDATE templates SET type=?,name=?,description=? WHERE id=?')->execute([$type,$name,$description,$id]);
                    flash('Template updated.');
                } else {
                    $pdo->prepare('INSERT INTO templates(type,name,description,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?)')->execute([$type,$name,$description,user()['id'],time(),time()]);
                    flash('Template created.');
                }
                redirect('?page=templates');
            }
            if ($action === 'delete_template') {
                $id = (int)$_POST['id'];
                $s = $pdo->prepare('SELECT created_by FROM templates WHERE id=?'); $s->execute([$id]);
                $creator = $s->fetchColumn();
                if ($creator === false) throw new RuntimeException('Template not found.');
                if (!is_admin() && (int)$creator !== (int)user()['id']) throw new RuntimeException('Only an administrator or template creator can delete this template.');
                $pdo->prepare('DELETE FROM templates WHERE id=?')->execute([$id]);
                flash('Template deleted.');
                redirect('?page=templates');
            }
            if ($action === 'save_search') {
                // Saved searches (#29): the list filters live in the URL only,
                // so the stored search is the whitelisted filter part of the
                // current list URL (tb_saved_search_query), kept per user.
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') throw new RuntimeException('Search name is required.');
                if (tb_char_length($name) > 200) throw new RuntimeException('Search name must be 200 characters or fewer.');
                $query = tb_saved_search_query($_POST);
                if ($query === null) throw new RuntimeException('Set at least one filter before saving a search.');
                $pdo->prepare('INSERT INTO saved_searches(user_id,name,query,created_at,updated_at) VALUES(?,?,?,?,?)')->execute([user()['id'],$name,$query,time(),time()]);
                flash('Search saved. Find it in the Saved searches dropdown.');
                // #43: a search saved from the kanban board returns there; the
                // task list stays the default for every other caller.
                $saveTarget = (($_POST['save_page'] ?? '') === 'waterflow') ? '?page=waterflow&'
                    : (($_POST['save_page'] ?? '') === 'kanban' ? '?page=kanban&' : '?page=tasks&');
                redirect($saveTarget . $query);
            }
            if ($action === 'delete_search') {
                // Same creator-or-admin guard as the templates: a saved search
                // belongs to the user who saved it.
                $id = (int)$_POST['id'];
                $s = $pdo->prepare('SELECT user_id FROM saved_searches WHERE id=?'); $s->execute([$id]);
                $owner = $s->fetchColumn();
                if ($owner === false) throw new RuntimeException('Saved search not found.');
                if (!is_admin() && (int)$owner !== (int)user()['id']) throw new RuntimeException('Only an administrator or the search owner can delete this saved search.');
                $pdo->prepare('DELETE FROM saved_searches WHERE id=?')->execute([$id]);
                flash('Saved search deleted.');
                $returnUrl = $_POST['return'] ?? '';
                redirect(is_string($returnUrl) && (str_starts_with($returnUrl, '?page=tasks') || str_starts_with($returnUrl, '?page=kanban') || str_starts_with($returnUrl, '?page=waterflow')) ? $returnUrl : '?');
            }
            if ($action === 'comment') {
                $id = (int)$_POST['task_id']; $body = trim($_POST['body'] ?? '');
                if (!task_exists($pdo,$id) || $body === '') throw new RuntimeException('Comment cannot be empty.');
                $pdo->prepare('INSERT INTO comments(task_id,user_id,body,created_at) VALUES(?,?,?,?)')->execute([$id,user()['id'],$body,time()]);
                // #54 v2 feedback: the history entry carries the comment itself,
                // so the audit shows what was written, not just that something was.
                history($pdo,$id,'Comment added',$body); redirect('?page=task&id='.$id.'#comments');
            }
            if ($action === 'send_message') {
                // Message board (#56): a signed-in user leaves a message to
                // another user - never to themselves, "to each other".
                $recipientId = (int)($_POST['recipient_id'] ?? 0);
                $msgBody = trim((string)($_POST['body'] ?? ''));
                if ($recipientId === (int)user()['id']) throw new RuntimeException('Pick another user to message.');
                if (!user_exists($pdo, $recipientId)) throw new RuntimeException('Pick a recipient.');
                // Attachment (#94): the composer carries an optional file
                // input next to the textarea, so a message can be text, a
                // file, or both. The file checks mirror the task/document
                // upload flow verbatim: same MIME allowlist, same configured
                // size limit, same random stored name inside uploads/.
                $hasFile = isset($_FILES['attachment'])
                    && is_array($_FILES['attachment'])
                    && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
                    && ($_FILES['attachment']['tmp_name'] ?? '') !== '';
                if ($msgBody === '' && !$hasFile) throw new RuntimeException('The message cannot be empty.');
                $attachment = null;
                if ($hasFile) {
                    $file = $_FILES['attachment'];
                    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > (int)$config['max_upload_bytes']) throw new RuntimeException('Upload failed or file exceeds the configured size limit.');
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']);
                    if (!in_array($mime, TASK_BOARD_UPLOAD_MIMES, true)) throw new RuntimeException('This file type is not allowed.');
                    $attachment = ['name' => basename((string)$file['name']), 'mime' => $mime, 'size' => (int)$file['size']];
                }
                // Double send (#87): the composer carries a one-time token that
                // the first accepted send consumes. A replayed submit - the
                // accidental second click on the send button - no longer
                // matches, so the same text cannot be inserted twice; the
                // visitor goes back to the thread with a note instead of a
                // duplicate, and the token is only consumed when a message is
                // really written (a validation error keeps it usable).
                if (!hash_equals(tb_message_send_token(), (string)($_POST['send_token'] ?? ''))) {
                    flash('This submit was dropped: the send form was already used (double click or another tab). Nothing was duplicated.');
                    redirect('?page=messages&u='.$recipientId);
                }
                // The file moves into uploads/ only after every check passed,
                // right before the rows are written; a move failure aborts
                // the send while the one-time token stays usable (the same
                // contract a text validation error has).
                $storedName = null;
                if ($attachment !== null) {
                    $dir = __DIR__ . '/uploads';
                    if (!is_dir($dir)) mkdir($dir, 0750, true);
                    $storedName = bin2hex(random_bytes(20));
                    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $dir . '/' . $storedName)) throw new RuntimeException('Could not save uploaded file.');
                }
                $pdo->prepare('INSERT INTO messages(sender_id,recipient_id,body,created_at) VALUES(?,?,?,?)')->execute([(int)user()['id'],$recipientId,$msgBody,time()]);
                if ($storedName !== null) {
                    $pdo->prepare('INSERT INTO message_attachments(message_id,user_id,stored_name,original_name,mime_type,file_size,created_at) VALUES(?,?,?,?,?,?,?)')
                        ->execute([(int)$pdo->lastInsertId(),(int)user()['id'],$storedName,$attachment['name'],$attachment['mime'],$attachment['size'],time()]);
                }
                tb_message_send_token_rotate();
                // (#129) The confirmation ribbon is marked in the markup so
                // the mobile stylesheet can hide exactly this flash: on a
                // phone it only ate chat space and never dismissed itself,
                // while the bubble's own Sent/Seen status (#118) already
                // tells the story. Every other flash stays unmarked.
                flash('Message sent.', 'tb-flash-message-sent');
                // Back to the conversation the send belongs to, so the mobile
                // single-pane view (#94) also lands inside the thread.
                redirect('?page=messages&u='.$recipientId);
            }
            if ($action === 'delete_message') {
                // Message deletion (#122): an administrator can remove a
                // single message from the conversation they are looking at.
                // Single users are restricted from deleting - the guard holds
                // even though the UI never renders them the controls.
                if (!is_admin()) throw new RuntimeException('Only an administrator can delete messages.');
                $msgId = (int)($_POST['message_id'] ?? 0);
                $s = $pdo->prepare('SELECT sender_id, recipient_id FROM messages WHERE id=?');
                $s->execute([$msgId]);
                $row = $s->fetch();
                if (!$row) throw new RuntimeException('Message not found.');
                tb_delete_messages($pdo, [$msgId]);
                flash('Message deleted.');
                // Back to the conversation the message belonged to: the other
                // end of the pair, with the orphan thread as u=0 (#17).
                $other = (int)$row['sender_id'] === (int)user()['id'] ? (int)$row['recipient_id'] : (int)$row['sender_id'];
                redirect('?page=messages&u=' . $other);
            }
            if ($action === 'delete_messages') {
                // The bulk half of #122: every selected message of the open
                // thread goes away in one confirm-guarded submit. The ids
                // ride in as the checkboxes' ids[] array; an empty or
                // non-array selection is a validation error, not a wipe.
                if (!is_admin()) throw new RuntimeException('Only an administrator can delete messages.');
                $msgIds = is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [];
                $deleted = tb_delete_messages($pdo, $msgIds);
                if ($deleted === 0) throw new RuntimeException('Select the messages to delete.');
                flash($deleted === 1 ? '1 message deleted.' : $deleted . ' messages deleted.');
                redirect('?page=messages&u=' . max(0, (int)($_POST['u'] ?? 0)));
            }
            if ($action === 'delete_conversation') {
                // And the whole-conversation half of #122: the complete
                // thread with the selected peer - u=0 is the "Deleted user"
                // thread (#17) - is wiped in one submit. Only the messages of
                // this pair are touched, never anybody else's conversations.
                if (!is_admin()) throw new RuntimeException('Only an administrator can delete messages.');
                $peerId = max(0, (int)($_POST['u'] ?? 0));
                $msgIds = tb_conversation_message_ids($pdo, (int)user()['id'], $peerId);
                if (!$msgIds) throw new RuntimeException('The conversation is already empty.');
                tb_delete_messages($pdo, $msgIds);
                flash('Conversation deleted.');
                redirect('?page=messages' . ($peerId > 0 ? '&u=' . $peerId : ''));
            }
            if ($action === 'mark_all_seen') {
                // Seen all notification (#79): one click clears the whole
                // stream, so the red navbar flag disappears until new unseen
                // notifications arrive. The per-item click-through (#73)
                // stays for reading one by one; this is the bulk shortcut.
                $pdo->prepare('UPDATE notifications SET is_seen=1 WHERE user_id=? AND is_seen=0')->execute([(int)user()['id']]);
                flash('All notifications marked as seen.');
                redirect('?page=notifications');
            }
            if ($action === 'link_task') {
                $id=(int)$_POST['task_id']; $other=(int)$_POST['related_task_id']; $type=$_POST['link_type'] ?? '';
                if ($id===$other || !task_exists($pdo,$id) || !task_exists($pdo,$other) || !array_key_exists($type, TASK_BOARD_LINK_TYPES)) throw new RuntimeException('Invalid task relationship.');
                $pdo->prepare('INSERT IGNORE INTO task_links(task_id,related_task_id,link_type) VALUES(?,?,?)')->execute([$id,$other,$type]);
                history($pdo,$id,'Relationship added',$type.' task #'.$other); redirect('?page=task&id='.$id);
            }
            if ($action === 'unlink_task') {
                $id=(int)$_POST['task_id']; $other=(int)$_POST['related_task_id']; $type=(string)($_POST['link_type'] ?? '');
                if (!array_key_exists($type, TASK_BOARD_LINK_TYPES)) throw new RuntimeException('Invalid task relationship.');
                $pdo->prepare('DELETE FROM task_links WHERE task_id=? AND related_task_id=? AND link_type=?')->execute([$id,$other,$type]);
                history($pdo,$id,'Relationship removed',$type.' task #'.$other); redirect('?page=task&id='.$id);
            }
            if ($action === 'upload') {
                $id=(int)$_POST['task_id'];
                if (!task_exists($pdo,$id) || empty($_FILES['file']['tmp_name'])) throw new RuntimeException('Choose a file.');
                $file=$_FILES['file'];
                if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > (int)$config['max_upload_bytes']) throw new RuntimeException('Upload failed or file exceeds the configured size limit.');
                $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($file['tmp_name']);
                $allowed=TASK_BOARD_UPLOAD_MIMES;
                if (!in_array($mime,$allowed,true)) throw new RuntimeException('This file type is not allowed.');
                $dir=__DIR__.'/uploads'; if (!is_dir($dir)) mkdir($dir,0750,true);
                $stored=bin2hex(random_bytes(20));
                if (!move_uploaded_file($file['tmp_name'],$dir.'/'.$stored)) throw new RuntimeException('Could not save uploaded file.');
                $pdo->prepare('INSERT INTO attachments(task_id,user_id,stored_name,original_name,mime_type,file_size,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$id,user()['id'],$stored,basename($file['name']),$mime,$file['size'],time()]);
                history($pdo,$id,'Attachment added',basename($file['name'])); redirect('?page=task&id='.$id);
            }
            if ($action === 'delete_attachment') {
                // Attachment deletion (#38): an administrator or the uploader
                // removes a single attachment and the stored file goes with it.
                $id = (int)($_POST['attachment_id'] ?? 0);
                $s=$pdo->prepare('SELECT * FROM attachments WHERE id=?'); $s->execute([$id]);
                $attachment=$s->fetch();
                if (!$attachment) throw new RuntimeException('Attachment not found.');
                if (!is_admin() && (int)$attachment['user_id'] !== (int)user()['id']) throw new RuntimeException('Only an administrator or the uploader can delete an attachment.');
                $pdo->prepare('DELETE FROM attachments WHERE id=?')->execute([$id]);
                // tb_attachment_path() keeps the unlink inside uploads/ even
                // for a tampered stored_name row.
                $storedFile = tb_attachment_path((string)$attachment['stored_name']);
                if (is_file($storedFile)) unlink($storedFile);
                history($pdo,(int)$attachment['task_id'],'Attachment deleted',(string)$attachment['original_name']);
                flash('Attachment deleted.'); redirect('?page=task&id='.$attachment['task_id']);
            }
            if ($action === 'doc_save') {
                // Know-hows (#53): create or update a knowledge-base document.
                // Editing stays with the creator or an administrator, the same
                // rule the task archive enforces.
                $id = (int)($_POST['id'] ?? 0);
                $title = trim((string)($_POST['title'] ?? ''));
                $content = trim((string)($_POST['content'] ?? ''));
                if ($title === '') throw new RuntimeException('The title is required.');
                if (tb_char_length($title) > 200) throw new RuntimeException('The title must be 200 characters or fewer.');
                if ($id > 0) {
                    $s=$pdo->prepare('SELECT * FROM documents WHERE id=?'); $s->execute([$id]); $doc=$s->fetch();
                    if (!$doc) throw new RuntimeException('Document not found.');
                    if (!document_can_edit($doc)) throw new RuntimeException('Only an administrator or the creator can edit a know-how.');
                    $pdo->prepare('UPDATE documents SET title=?,content=?,updated_at=? WHERE id=?')->execute([$title,$content,time(),$id]);
                    flash('Know-how updated.'); redirect('?page=document&id='.$id);
                }
                $pdo->prepare('INSERT INTO documents(title,content,created_by,created_at,updated_at) VALUES(?,?,?,?,?)')->execute([$title,$content,user()['id'],time(),time()]);
                flash('Know-how saved.'); redirect('?page=documents');
            }
            if ($action === 'doc_comment') {
                $id=(int)($_POST['document_id'] ?? 0); $body=trim((string)($_POST['body'] ?? ''));
                if (!document_exists($pdo,$id) || $body === '') throw new RuntimeException('Comment cannot be empty.');
                $pdo->prepare('INSERT INTO document_comments(document_id,user_id,body,created_at) VALUES(?,?,?,?)')->execute([$id,user()['id'],$body,time()]);
                flash('Comment added.'); redirect('?page=document&id='.$id.'#comments');
            }
            if ($action === 'doc_upload') {
                // The task upload flow verbatim, aimed at the know-how
                // attachment table: same MIME checks, same uploads folder,
                // same random stored name.
                $id=(int)($_POST['document_id'] ?? 0);
                if (!document_exists($pdo,$id) || empty($_FILES['file']['tmp_name'])) throw new RuntimeException('Choose a file.');
                $file=$_FILES['file'];
                if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > (int)$config['max_upload_bytes']) throw new RuntimeException('Upload failed or file exceeds the configured size limit.');
                $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($file['tmp_name']);
                if (!in_array($mime,TASK_BOARD_UPLOAD_MIMES,true)) throw new RuntimeException('This file type is not allowed.');
                $dir=__DIR__.'/uploads'; if (!is_dir($dir)) mkdir($dir,0750,true);
                $stored=bin2hex(random_bytes(20));
                if (!move_uploaded_file($file['tmp_name'],$dir.'/'.$stored)) throw new RuntimeException('Could not save uploaded file.');
                $pdo->prepare('INSERT INTO document_attachments(document_id,user_id,stored_name,original_name,mime_type,file_size,created_at) VALUES(?,?,?,?,?,?,?)')->execute([$id,user()['id'],$stored,basename($file['name']),$mime,$file['size'],time()]);
                flash('Attachment added.'); redirect('?page=document&id='.$id);
            }
            if ($action === 'doc_delete_attachment') {
                $id = (int)($_POST['attachment_id'] ?? 0);
                $s=$pdo->prepare('SELECT * FROM document_attachments WHERE id=?'); $s->execute([$id]);
                $attachment=$s->fetch();
                if (!$attachment) throw new RuntimeException('Attachment not found.');
                if (!is_admin() && (int)$attachment['user_id'] !== (int)user()['id']) throw new RuntimeException('Only an administrator or the uploader can delete an attachment.');
                $pdo->prepare('DELETE FROM document_attachments WHERE id=?')->execute([$id]);
                // tb_attachment_path() keeps the unlink inside uploads/ even
                // for a tampered stored_name row.
                $storedFile = tb_attachment_path((string)$attachment['stored_name']);
                if (is_file($storedFile)) unlink($storedFile);
                flash('Attachment deleted.'); redirect('?page=document&id='.$attachment['document_id']);
            }
            if ($action === 'doc_delete') {
                $id = (int)($_POST['id'] ?? 0);
                $s=$pdo->prepare('SELECT * FROM documents WHERE id=?'); $s->execute([$id]); $doc=$s->fetch();
                if (!$doc) throw new RuntimeException('Document not found.');
                if (!document_can_edit($doc)) throw new RuntimeException('Only an administrator or the creator can delete a know-how.');
                // The stored files go with the document, exactly like the task
                // attachment deletion (#38 v2).
                $fs=$pdo->prepare('SELECT stored_name FROM document_attachments WHERE document_id=?'); $fs->execute([$id]);
                foreach (array_map('strval', $fs->fetchAll(PDO::FETCH_COLUMN)) as $storedName) {
                    $storedFile = tb_attachment_path($storedName);
                    if (is_file($storedFile)) unlink($storedFile);
                }
                // Explicit row deletes on top of the FK cascades, so the
                // behaviour does not depend on the driver's pragma/enforcement.
                $pdo->prepare('DELETE FROM document_comments WHERE document_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM document_attachments WHERE document_id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM documents WHERE id=?')->execute([$id]);
                flash('Know-how deleted.'); redirect('?page=documents');
            }
            if ($action === 'save_timezone') {
                // Timezone preference (#108): the viewer's IANA zone for every
                // datetime render. Stored with the #66 user_preferences
                // discipline; the render helper re-validates the name on every
                // read, so a stale or crafted value can never break a page.
                $tz = trim((string)($_POST['timezone'] ?? ''));
                if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) throw new RuntimeException('Unknown timezone.');
                tb_user_pref_set($pdo, (int)user()['id'], 'timezone', $tz);
                flash('Timezone saved.');
                redirect('?page=settings');
            }
            if ($action === 'save_datetime_format') {
                // Date/time format preference (#110): the shape every datetime
                // renders in, chosen from the tb_datetime_formats() allowlist
                // and stored with the #66 user_preferences discipline - the
                // same re-validate-on-read guarantee as the #108 timezone
                // preference, so a stale or crafted value falls back to the
                // default instead of breaking a page.
                $fmt = trim((string)($_POST['datetime_format'] ?? ''));
                if (!array_key_exists($fmt, tb_datetime_formats())) throw new RuntimeException('Unknown date/time format.');
                tb_user_pref_set($pdo, (int)user()['id'], 'datetime_format', $fmt);
                flash('Date/time format saved.');
                redirect('?page=settings');
            }
            if ($action === 'save_theme') {
                $theme = $_POST['theme'] ?? '';
                if (!in_array($theme, valid_themes(), true)) throw new RuntimeException('Invalid theme selection.');
                // Send the visitor back to the page the switcher was used on.
                // Only same-site relative URLs (path or query strings) are allowed.
                $returnUrl = '?';
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                if (is_string($uri) && $uri !== '' && $uri[0] === '/' && ($uri[1] ?? '') !== '/') $returnUrl = $uri;
                $pdo->prepare('UPDATE users SET theme=? WHERE id=?')->execute([$theme, user()['id']]);
                $_SESSION['user']['theme'] = $theme;
                flash('Theme saved.'); redirect($returnUrl);
            }
            if ($action === 'toggle_kanban_column') {
                // Kanban toggle (#66): open/close one status column. The state
                // is a per-user preference (user_preferences table), so it
                // survives reloads, the list auto-refresh and new sign-ins,
                // and stays separate per user. Send the visitor back to the
                // board they toggled on; only same-site relative URLs are
                // allowed, exactly like the theme switcher above.
                $statusId = (int)($_POST['status_id'] ?? 0);
                $s = $pdo->prepare('SELECT COUNT(*) FROM statuses WHERE id=?');
                $s->execute([$statusId]);
                if ((int)$s->fetchAll(PDO::FETCH_COLUMN)[0] === 0) throw new RuntimeException('Unknown status.');
                $collapsed = array_values(array_filter(array_map('intval', explode(',', tb_user_pref_get($pdo, (int)user()['id'], 'kanban_collapsed_statuses')))));
                if (in_array($statusId, $collapsed, true)) {
                    $collapsed = array_values(array_diff($collapsed, [$statusId]));
                } else {
                    $collapsed[] = $statusId;
                }
                tb_user_pref_set($pdo, (int)user()['id'], 'kanban_collapsed_statuses', implode(',', $collapsed));
                $returnUrl = '?';
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                if (is_string($uri) && $uri !== '' && $uri[0] === '/' && ($uri[1] ?? '') !== '/') $returnUrl = $uri;
                redirect($returnUrl);
            }
            if ($action === 'toggle_notification_flag') {
                // Notification flag toggle (#100): show or hide the red "New
                // notifications" badge in the top menu. The state is a
                // per-user preference (user_preferences table, the #66
                // discipline), so it survives reloads and new sign-ins and
                // stays separate per user. Both badge sources read the same
                // flag - the server-rendered navbar badge and the
                // ?page=badges JSON feed the footer poller swaps the badge
                // with - so the switch takes effect on the very next page
                // load or poll, not only after a sign-out. Send the visitor
                // back to the page the switch was used on; only same-site
                // relative URLs are allowed, exactly like the theme switcher
                // above.
                $flagOn = tb_notification_flag_enabled($pdo, (int)user()['id']);
                tb_user_pref_set($pdo, (int)user()['id'], 'notification_flag_enabled', $flagOn ? '0' : '1');
                $returnUrl = '?';
                $uri = $_SERVER['REQUEST_URI'] ?? '';
                if (is_string($uri) && $uri !== '' && $uri[0] === '/' && ($uri[1] ?? '') !== '/') $returnUrl = $uri;
                redirect($returnUrl);
            }
            if ($action === 'bulk_move_tasks') {
                // Bulk action (#23): move the selected tasks into one project.
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['task_ids'] ?? [])), fn($v) => $v > 0)));
                if (!$ids) throw new RuntimeException('Select at least one task to move.');
                $projectId = (int)($_POST['project_id'] ?? 0);
                $s = $pdo->prepare('SELECT id, name FROM projects WHERE id=?'); $s->execute([$projectId]);
                $project = $s->fetch();
                if (!$project) throw new RuntimeException('Choose a valid project.');
                $returnUrl = $_POST['return'] ?? '';
                $returnUrl = is_string($returnUrl) && str_starts_with($returnUrl, '?page=tasks') ? $returnUrl : '?';
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $move = $pdo->prepare('UPDATE tasks SET project_id=? WHERE id IN (' . $placeholders . ')');
                $move->execute(array_merge([$projectId], $ids));
                foreach ($ids as $tid) {
                    history($pdo, $tid, 'Task moved to project', 'The task was moved to project #' . $projectId . ' ' . $project['name'] . '.');
                }
                flash(count($ids) . ' task' . (count($ids) === 1 ? '' : 's') . ' moved to project ' . $project['name'] . '.');
                redirect($returnUrl);
            }
            if ($action === 'add_status' && is_admin()) {
                $name=trim($_POST['name'] ?? '');
                if ($e=status_validation_error($pdo,$name)) throw new RuntimeException($e);
                // #42: same colour fields as the edit form - the picker value
                // unless the "Default colour" checkbox clears it.
                $color=isset($_POST['color_default'])?'':($_POST['color'] ?? '');
                if ($e=tb_status_color_validation_error($color)) throw new RuntimeException($e);
                $pdo->prepare('INSERT INTO statuses(name,sort_order,is_done,color) VALUES(?,?,?,?)')->execute([$name,(int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM statuses')->fetchColumn(),isset($_POST['is_done'])?1:0,tb_status_color_normalized($color)]);
                flash('Status added.'); redirect('?page=statuses');
            }
            if ($action === 'edit_status' && is_admin()) {
                $id=(int)($_POST['id'] ?? 0);
                $s=$pdo->prepare('SELECT id FROM statuses WHERE id=?'); $s->execute([$id]);
                if (!$s->fetch()) throw new RuntimeException('Status not found.');
                $name=trim($_POST['name'] ?? '');
                if ($e=status_validation_error($pdo,$name,$id)) throw new RuntimeException($e);
                // #42: the "Default colour" checkbox clears the stored colour;
                // otherwise the colour picker's #RRGGBB value is stored as-is.
                $color=isset($_POST['color_default'])?'' :($_POST['color'] ?? '');
                if ($e=tb_status_color_validation_error($color)) throw new RuntimeException($e);
                $pdo->prepare('UPDATE statuses SET name=?,sort_order=?,is_done=?,color=? WHERE id=?')->execute([$name,(int)($_POST['sort_order'] ?? 0),isset($_POST['is_done'])?1:0,tb_status_color_normalized($color),$id]);
                flash('Status updated.'); redirect('?page=statuses');
            }
            if ($action === 'delete_status' && is_admin()) {
                $id=(int)($_POST['id'] ?? 0);
                $s=$pdo->prepare('SELECT name FROM statuses WHERE id=?'); $s->execute([$id]);
                $name=(string)$s->fetchColumn();
                if ($name==='') throw new RuntimeException('Status not found.');
                $s=$pdo->prepare('SELECT COUNT(*) FROM tasks WHERE status_id=?'); $s->execute([$id]);
                $used=(int)$s->fetchColumn();
                if ($used>0) throw new RuntimeException('Cannot delete \''.$name.'\': '.$used.' task'.($used===1?' still uses':'s still use').' this status.');
                if ((int)$pdo->query('SELECT COUNT(*) FROM statuses')->fetchColumn()<=1) throw new RuntimeException('Cannot delete \''.$name.'\': at least one status must remain.');
                $pdo->prepare('DELETE FROM statuses WHERE id=?')->execute([$id]);
                flash('Status deleted.'); redirect('?page=statuses');
            }
            if ($action === 'save_activity_levels' && is_admin()) {
                // Activity status periods (#34): the settings form's rows are
                // parsed into the storage shape, validated, stored and made
                // effective for the rest of this request.
                $levels = tb_activity_levels_from_form((array)($_POST['levels'] ?? []));
                if ($e = tb_activity_levels_validation_error($levels)) throw new RuntimeException($e);
                tb_save_activity_levels($pdo, $levels);
                $GLOBALS['TB_ACTIVITY_LEVELS'] = $levels;
                flash('Activity status periods saved.');
                redirect('?page=activity_settings');
            }
            if ($action === 'reset_activity_levels' && is_admin()) {
                tb_save_activity_levels($pdo, null);
                unset($GLOBALS['TB_ACTIVITY_LEVELS']);
                flash('Activity status periods reset to the built-in defaults.');
                redirect('?page=activity_settings');
            }
            if ($action === 'save_logged_out_indicator' && is_admin()) {
                // The logged-out indicator (#106): the settings form's style +
                // character are parsed into the storage shape, validated,
                // stored (null stores the disable marker) and made effective
                // for the rest of this request.
                $indicator = tb_logged_out_indicator_from_form($_POST);
                if ($indicator !== null && ($e = tb_logged_out_indicator_validation_error($indicator)) !== null) throw new RuntimeException($e);
                tb_save_logged_out_indicator($pdo, $indicator);
                $GLOBALS['TB_LOGGED_OUT_INDICATOR'] = $indicator;
                flash($indicator === null ? 'Logged-out indicator disabled.' : 'Logged-out indicator saved.');
                redirect('?page=activity_settings');
            }
            if ($action === 'add_user' && is_admin()) {
                $name=trim($_POST['username'] ?? ''); $display=trim($_POST['display_name'] ?? ''); $pass=$_POST['password'] ?? '';
                if ($name==='' || $display==='' || strlen($pass)<10) throw new RuntimeException('Enter username, display name and a password of at least 10 characters.');
                if (tb_char_length($name) > 80 || tb_char_length($display) > 120) throw new RuntimeException('Username is limited to 80 and display name to 120 characters.');
                $pdo->prepare('INSERT INTO users(username,password_hash,display_name,role,created_at) VALUES(?,?,?,?,?)')->execute([$name,password_hash($pass,PASSWORD_DEFAULT),$display,$_POST['role']==='admin'?'admin':'user',time()]);
                flash('User created.'); redirect('?page=users');
            }
            if ($action === 'edit_user' && is_admin()) {
                $id=(int)($_POST['id'] ?? 0);
                $s=$pdo->prepare('SELECT id,username,role FROM users WHERE id=?'); $s->execute([$id]); $target=$s->fetch();
                if (!$target) throw new RuntimeException('User not found.');
                $name=trim($_POST['username'] ?? ''); $display=trim($_POST['display_name'] ?? ''); $role=($_POST['role'] ?? '')==='admin'?'admin':'user'; $pass=$_POST['password'] ?? '';
                if ($name==='' || $display==='') throw new RuntimeException('Enter a username and a display name.');
                if (mb_strlen($name)>80) throw new RuntimeException('The username is too long (max 80 characters).');
                if (mb_strlen($display)>120) throw new RuntimeException('The display name is too long (max 120 characters).');
                if ($pass!=='' && strlen($pass)<10) throw new RuntimeException('Use a password with at least 10 characters, or leave the field empty to keep the current one.');
                $s=$pdo->prepare('SELECT 1 FROM users WHERE username=? AND id<>?'); $s->execute([$name,$id]);
                if ($s->fetch()) throw new RuntimeException('This username is already taken.');
                if ($target['role']==='admin' && $role==='user' && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn()<=1) throw new RuntimeException('At least one administrator must remain.');
                if ($pass!=='') {
                    $pdo->prepare('UPDATE users SET username=?,display_name=?,role=?,password_hash=? WHERE id=?')->execute([$name,$display,$role,password_hash($pass,PASSWORD_DEFAULT),$id]);
                } else {
                    $pdo->prepare('UPDATE users SET username=?,display_name=?,role=? WHERE id=?')->execute([$name,$display,$role,$id]);
                }
                if ((int)user()['id']===$id) {
                    // Rebuild the session from the updated row instead of a hand-picked
                    // field list: the old copy dropped the theme key, so renaming your
                    // own account flipped dark mode back to light (#61). The preference
                    // itself is already stored id-based (users.theme on users.id) and
                    // was never lost in the database - only in the session.
                    $s=$pdo->prepare('SELECT id,username,display_name,role,theme,last_seen_at,logged_out FROM users WHERE id=?'); $s->execute([$id]);
                    $fresh=$s->fetch();
                    if (is_array($fresh)) $_SESSION['user'] = tb_session_user($fresh);
                }
                flash('User updated.'); redirect('?page=users');
            }
            if ($action === 'delete_user' && is_admin()) {
                $id=(int)($_POST['id'] ?? 0);
                if ($id===(int)user()['id']) throw new RuntimeException('You cannot delete your own account.');
                $s=$pdo->prepare('SELECT role FROM users WHERE id=?'); $s->execute([$id]); $target=$s->fetch();
                if (!$target) throw new RuntimeException('User not found.');
                if ($target['role']==='admin' && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn()<=1) throw new RuntimeException('At least one administrator must remain.');
                $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
                flash('User deleted. Their tasks, comments and attachments were kept.'); redirect('?page=users');
            }
            if ($action === 'cleanup_uploads' && is_admin()) {
                // Orphan sweep (#38 v2): hard-delete every uploads/ file that
                // no attachment row references anymore, so the folder never
                // accumulates leftovers from deletes or failed uploads.
                $removed = tb_cleanup_orphan_uploads($pdo);
                flash($removed === 0 ? 'No orphaned attachment files found.' : $removed.' orphaned attachment file(s) removed.');
                redirect('?page=admin');
            }
            if ($action === 'save_project') {
                $id = (int)($_POST['id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $statusId = (int)($_POST['status_id'] ?? 0);
                if ($name === '') throw new RuntimeException('Project name is required.');
                if (!status_exists($pdo, $statusId)) throw new RuntimeException('Choose a valid status.');
                $assignees = array_values(array_unique(array_map('intval', $_POST['assignees'] ?? [])));
                if ($id) {
                    if (!project_exists($pdo, $id)) throw new RuntimeException('Project not found.');
                    $pdo->prepare('UPDATE projects SET name=?,description=?,status_id=? WHERE id=?')->execute([$name,$description,$statusId,$id]);
                } else {
                    $pdo->prepare('INSERT INTO projects(name,description,status_id,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?)')->execute([$name,$description,$statusId,user()['id'],time(),time()]);
                    $id = (int)$pdo->lastInsertId();
                }
                $pdo->prepare('DELETE FROM project_assignees WHERE project_id=?')->execute([$id]);
                $a = $pdo->prepare('INSERT IGNORE INTO project_assignees(project_id,user_id) VALUES(?,?)');
                foreach ($assignees as $uid) $a->execute([$id,$uid]);
                redirect('?page=project&id=' . $id);
            }
            if ($action === 'delete_project') {
                $id = (int)$_POST['id'];
                $project = find_project($pdo, $id);
                if (!$project) throw new RuntimeException('Project not found.');
                if (!is_admin() && (int)$project['created_by'] !== (int)user()['id']) throw new RuntimeException('Only an administrator or project creator can delete this project.');
                $pdo->prepare('DELETE FROM projects WHERE id=?')->execute([$id]);
                $returnUrl = $_POST['return'] ?? '';
                flash('Project deleted.');
                redirect(is_string($returnUrl) && str_starts_with($returnUrl, '?page=projects') ? $returnUrl : '?page=projects');
            }
            if ($action === 'bulk_delete_projects') {
                $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['project_ids'] ?? [])), fn($v) => $v > 0)));
                if (!$ids) throw new RuntimeException('Select at least one project to delete.');
                $returnUrl = $_POST['return'] ?? '';
                $returnUrl = is_string($returnUrl) && str_starts_with($returnUrl, '?page=projects') ? $returnUrl : '?page=projects';
                $result = tb_bulk_apply(
                    $pdo,
                    $ids,
                    'SELECT created_by FROM projects WHERE id=?',
                    'DELETE FROM projects WHERE id=?'
                );
                flash(tb_bulk_flash($result['done'], $result['skipped'], 'project', 'deleted', 'only administrators or the project creator can delete a project.'));
                redirect($returnUrl);
            }
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

// Returns the validation message for a status name, or null when it is usable.
// $ignoreId lets a status keep its own name when editing (0 = adding).
function status_validation_error(PDO $pdo, string $name, int $ignoreId = 0): ?string {
    if ($name==='') return 'Status name is required.';
    if (preg_match_all('/./us',$name)>40) return 'Status name must be 40 characters or fewer.';
    $s=$pdo->prepare('SELECT id FROM statuses WHERE name=?'.($ignoreId>0?' AND id<>'.(int)$ignoreId:''));
    $s->execute([$name]);
    if ($s->fetchColumn()) return 'A status with this name already exists.';
    return null;
}

function render_header(string $title, array $config, string $error = ''): void {
    $theme = current_theme();
    ?><!doctype html><html lang="en" data-bs-theme="<?=h($theme)?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?> - <?=h($config['app_name'])?></title>
    <link rel="icon" type="image/svg+xml" href="?page=favicon&amp;type=svg"><link rel="icon" href="?page=favicon" sizes="48x48 32x32 16x16">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#f5f6f8}.card{border:0;box-shadow:0 1px 5px #0000000d}.muted{color:#687386}.prewrap{white-space:pre-wrap}.navbar-brand{font-weight:700}.tb-activity-dot{display:inline-block;width:.55em;height:.55em;border-radius:50%;margin-right:.3em}.tb-activity-green{background:#198754}.tb-activity-yellow{background:#ffc107}.tb-activity-red{background:#dc3545}.tb-activity-grey{background:#6c757d}.tb-activity-char{font-weight:700;margin-right:.3em}.tb-activity-empty{font-weight:400;margin-right:.3em;color:#6c757d}.tb-activity-empty.tb-activity-green{color:#198754;background:transparent}.tb-activity-empty.tb-activity-yellow{color:#ffc107;background:transparent}.tb-activity-empty.tb-activity-red{color:#dc3545;background:transparent}html[data-bs-theme="dark"] body{background:#14181f}html[data-bs-theme="dark"] .card{box-shadow:0 1px 6px #00000059}html[data-bs-theme="dark"] .muted{color:#8b96a5}</style></head><body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark"><div class="container"><a class="navbar-brand" href="?"><?=h($config['app_name'])?></a><?php if(user()): ?><button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#tb-navbar" aria-controls="tb-navbar" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button><div class="collapse navbar-collapse" id="tb-navbar"><div class="navbar-nav ms-auto align-items-center"><a class="nav-link" href="?page=tasks">Tasks</a><a class="nav-link" href="?page=kanban">Kanban</a><a class="nav-link" href="?page=waterflow">Waterflow</a><a class="nav-link" href="?page=projects">Projects</a><a class="nav-link" href="?page=templates">Templates</a><a class="nav-link" href="?page=documents">Know-hows</a><a class="nav-link" href="?page=messages">Messages<?php global $pdo; $tbUnread=array_key_exists('TB_UNREAD_BADGE',$GLOBALS)?(int)$GLOBALS['TB_UNREAD_BADGE']:tb_unread_message_count($pdo,(int)user()['id']); if($tbUnread): ?> <span class="badge text-bg-danger" title="New messages"><?=$tbUnread?></span><?php endif; unset($GLOBALS['TB_UNREAD_BADGE']); ?></a><a class="nav-link" href="?page=notifications">Notifications<?php global $pdo; $tbSeen=array_key_exists('TB_NOTIFICATION_BADGE',$GLOBALS)?(int)$GLOBALS['TB_NOTIFICATION_BADGE']:tb_unseen_notification_count($pdo,(int)user()['id']); if($tbSeen && tb_notification_flag_enabled($pdo,(int)user()['id'])): ?> <span class="badge text-bg-danger" title="New notifications"><?=$tbSeen?></span><?php endif; unset($GLOBALS['TB_NOTIFICATION_BADGE']); ?></a><a class="nav-link" href="?page=settings">Settings</a><?php if(is_admin()): ?><a class="nav-link" href="?page=admin">Administration</a><a class="nav-link" href="?page=activity_settings">Activity periods</a><a class="nav-link" href="?page=updates">Software updates</a><?php endif; ?><form method="post" class="d-flex align-items-center mx-2"><?=csrf_field()?><input type="hidden" name="action" value="save_theme"><select name="theme" class="form-select form-select-sm bg-dark text-light border-secondary" aria-label="Theme" title="Theme" onchange="this.form.submit()"><option value="light" <?=$theme==='light'?'selected':''?>>Light</option><option value="dark" <?=$theme==='dark'?'selected':''?>>Dark</option></select><noscript><button class="btn btn-sm btn-outline-light ms-1">Save</button></noscript></form><span class="navbar-text mx-2"><?=tb_user_with_activity($_SESSION['user'])?></span><form method="post" action="?page=logout" class="d-inline m-0"><?=csrf_field()?><button type="submit" class="btn btn-link nav-link p-0 text-white text-decoration-none">Sign out</button></form></div></div><?php endif; ?></div></nav><main class="container py-4">
    <?php /* (#129) The queued confirmation ribbon: flash() may carry an extra class that marks the div (tb-flash-message-sent on the send confirmation) so the messages page's mobile stylesheet can hide exactly that one below the lg breakpoint - the bubble itself carries the live Sent/Seen status (#118). Every other flash renders exactly as before, on every viewport. */ ?>
    <?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success<?=!empty($_SESSION['flash_class']) ? ' ' . h((string)$_SESSION['flash_class']) : ''?>"><?=h($_SESSION['flash'])?></div><?php unset($_SESSION['flash'], $_SESSION['flash_class']); endif; ?>
    <?php if($error): ?><div class="alert alert-danger"><?=h($error)?></div><?php endif;
}
function render_footer(): void { ?></main><?php if (user()): ?><script>
(function(){
// (#64) The red navbar badges are server-rendered at page load; this poller
// keeps them live while the board stays open: every 10 seconds it reads the
// tiny ?page=badges JSON feed and swaps the two badges in or out, exactly
// like a fresh page load would render them. Without JS nothing changes - the
// badges still render server-side on every full page load.
var busy=false;
function apply(kind,title,count){
    var link=document.querySelector('a.nav-link[href="?page='+kind+'"]');
    if(!link) return;
    var badge=link.querySelector('span.badge[title="'+title+'"]');
    if(count>0){
        if(!badge){badge=document.createElement('span');badge.className='badge text-bg-danger';badge.title=title;link.appendChild(badge);}
        badge.textContent=String(count);
    } else if(badge){ badge.remove(); }
}
function tick(){
    if(busy||document.hidden) return;
    busy=true;
    fetch('?page=badges',{credentials:'same-origin'})
        .then(function(r){return r.ok?r.json():null;})
        .then(function(d){if(d){apply('messages','New messages',d.messages|0);apply('notifications','New notifications',d.notifications|0);}})
        .catch(function(){})
        .then(function(){busy=false;});
}
setInterval(tick,10000);
document.addEventListener('visibilitychange',function(){if(!document.hidden)tick();});
})();
</script><?php endif; ?><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html><?php }

// ---- Task scheduler (#18, targets #31) ----------------------------------------------------
// Tasks may opt in to a recurring schedule. An external cron job calls ?page=cronjob
// every few minutes; whenever a scheduled task has not been updated for longer than its
// period, it is reset to the scheduled status (default TODO) with the scheduled
// assignees (default: none), and an automatic comment is recorded.

/** Allowed schedule periods keyed by the value stored in tasks.schedule_period. */
function schedule_periods(): array {
    return [
        'hourly'   => 'Hourly',
        'daily'    => 'Daily',
        'weekly'   => 'Weekly',
        'biweekly' => 'Every 2 weeks',
        'monthly'  => 'Monthly',
    ];
}

/** The epoch cutoff (#108) a schedule period reopens before: now minus the period. */
function schedule_cutoff_ts(string $period): ?int {
    $seconds = ['hourly' => 3600, 'daily' => 86400, 'weekly' => 604800, 'biweekly' => 1209600, 'monthly' => 2592000][$period] ?? null;
    return $seconds === null ? null : time() - $seconds;
}

/** The id of the status used to reopen tasks: the "TODO" status, or the first non-completion one. */
function scheduler_todo_status_id(PDO $pdo): ?int {
    $s = $pdo->query("SELECT id FROM statuses WHERE name='TODO' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$s) $s = $pdo->query('SELECT id FROM statuses WHERE is_done=0 ORDER BY sort_order,id LIMIT 1')->fetchColumn();
    return $s ? (int)$s : null;
}

/**
 * The assignee ids a scheduled reopen keeps on the task, filtered to users
 * that still exist (#31). The stored schedule may name an account that has
 * since been deleted; such an id is dropped instead of blocking the reopen.
 */
function schedule_existing_assignees(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare('SELECT id FROM users WHERE id IN (' . $ph . ')');
    $s->execute($ids);
    $existing = array_map('intval', array_column($s->fetchAll(), 'id'));
    return array_values(array_intersect($ids, $existing));   // keeps the stored (sorted) order
}

/**
 * Reopens every scheduled task that was not updated within its period (#18),
 * applying the reopen targets the task stores (#31): the scheduled status
 * (default: TODO) and the scheduled assignee list (default: none).
 * Returns the ids of the reopened tasks.
 */
function reopen_due_tasks(PDO $pdo): array {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $reopened = [];
    $todoId = null;
    foreach (array_keys(schedule_periods()) as $period) {
        $cutoff = schedule_cutoff_ts($period);
        if ($cutoff === null) continue;
        $find = $pdo->prepare('SELECT t.id, t.title, t.created_by, t.status_id, t.schedule_status_id, t.schedule_assignees, (SELECT GROUP_CONCAT(ax.user_id) FROM task_assignees ax WHERE ax.task_id=t.id) assignee_ids FROM tasks t WHERE t.schedule_period=? AND t.archived_at IS NULL AND t.updated_at < ?');
        $find->execute([$period, $cutoff]);
        foreach ($find->fetchAll() as $task) {
            // The reopen target (#31): what the task edit form stored. A task
            // without an explicit scheduled status goes to TODO (the #18
            // behavior, also the fallback when a stored status was deleted),
            // one without scheduled assignees is left unassigned.
            $targetStatusId = (int)($task['schedule_status_id'] ?? 0);
            if ($targetStatusId === 0 || !status_exists($pdo, $targetStatusId)) {
                $todoId ??= scheduler_todo_status_id($pdo);
                $targetStatusId = $todoId ?? 0;
            }
            if ($targetStatusId === 0) continue;   // no resolvable status: leave the task alone
            $targetAssignees = schedule_existing_assignees($pdo, schedule_target_assignees($task['schedule_assignees'] ?? null));
            $currentAssignees = schedule_target_assignees($task['assignee_ids'] ?? null);
            // A task that already sits in its target state would not change:
            // leave it (and its comment stream) alone instead of pinging it every run.
            if ((int)$task['status_id'] === $targetStatusId && $currentAssignees === $targetAssignees) continue;
            $pdo->prepare('UPDATE tasks SET status_id=?, updated_at=? WHERE id=?')->execute([$targetStatusId, time(), (int)$task['id']]);
            $pdo->prepare('DELETE FROM task_assignees WHERE task_id=?')->execute([(int)$task['id']]);
            if ($targetAssignees) {
                $ins = $pdo->prepare('INSERT IGNORE INTO task_assignees(task_id,user_id) VALUES(?,?)');
                foreach ($targetAssignees as $uid) $ins->execute([(int)$task['id'], $uid]);
            }
            $s = $pdo->prepare('SELECT name FROM statuses WHERE id=?');
            $s->execute([$targetStatusId]);
            $statusName = (string)$s->fetchColumn();
            $targetNames = [];
            if ($targetAssignees) {
                $ph = implode(',', array_fill(0, count($targetAssignees), '?'));
                $s = $pdo->prepare('SELECT display_name FROM users WHERE id IN (' . $ph . ') ORDER BY display_name');
                $s->execute($targetAssignees);
                $targetNames = array_column($s->fetchAll(), 'display_name');
            }
            $pdo->prepare('INSERT INTO comments(task_id,user_id,body,created_at) VALUES(?,?,?,?)')->execute([(int)$task['id'], (int)$task['created_by'], 'Reopen by auto-scheduler', time()]);
            $pdo->prepare('INSERT INTO task_history(task_id,user_id,event,details) VALUES(?,?,?,?)')->execute([
                (int)$task['id'], (int)$task['created_by'], 'Reopened by auto-scheduler',
                'Not updated within the schedule period ('.$period.'). Status set to '.$statusName
                .($targetNames ? ' and assignees set to: '.implode(', ', $targetNames).'.' : ' and assignees removed.'),
            ]);
            // Notifications (#58): the cron has no session, so the fan-out is
            // explicit here - the re-applied assignees hear about the reopen,
            // the task creator (who configured the schedule) is the actor.
            tb_notify_task_assignees($pdo, (int)$task['id'], (int)$task['created_by'], 'Reopened by auto-scheduler', (int)$pdo->lastInsertId());
            $reopened[] = (int)$task['id'];
        }
    }
    return $reopened;
}
// ---- End task scheduler -------------------------------------------------------------------

// ---- Task scheduler cron controller (#18) -------------------------------------------------
// Called by the external cron job (e.g. every 5 minutes) without a user session:
//   GET ?page=cronjob
// Reopens scheduled tasks that were not updated within their period. Always exits.
// ---- Favicon (#60) -------------------------------------------------------------------------
// The icon files live next to index.php, but some hosts do not serve newly
// uploaded static files reliably (the request 404s into the host's own error
// page while every PHP route works), so the pages reference the icon through
// the app itself: ?page=favicon serves the ICO, ?page=favicon&type=svg the
// vector version. Reachable without a session - the login page carries it too.
// v6: on the live host the docroot copies themselves went missing in the
// deployment - static/binary uploads never landed while every PHP file did,
// so /favicon.ico redirects into the host's 404 page and the route below
// answered its own "Not found.", keeping the tab icon from ever appearing.
// The exact repository bytes are therefore embedded right here and served
// whenever the file is absent: the icon can no longer depend on anything
// except this file, which every deployment must carry anyway. The docroot
// file still wins when present (pinned byte-identical by FaviconTest).
define('TB_FAVICON_ICO_B64', 'AAABAAMAEBAAAAAAIADIAgAANgAAACAgAAAAACAACQYAAP4CAAAwMAAAAAAgAKsJAAAHCQAAiVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAACj0lEQVR4nH2Sz4uVVRjHP+e8pzt6uTIXHbCxH2oQghq40GnjQjIhKCqbVaJZf0GujNRmaBg3LqRdiG7EfS5EjNwVhBsXId2QoCi83qA7jM687/3xnvM8T4v33qsLc3E4Z3E+fL/P5xwHcOLThydfCI2FlPrbRaI3VWcmmCqqiplUu4qZoZD9leL60o2bb151n3z27/GQTV1TicTUx0bAM2DMFFXBETAzYuyd8JhcqOCePBdWBatWjH1RjaByIZjZbJQ+ppb9H6yqOKf0+xGHknnLUooYOutVxZ6XPIZ7ReTll6ZoNjNiFLDKyUhYBY4vmz2BvVeKPPLKq1N8e+kN3v/gRdbXhzhnmKrzVbJM5K2vlaQogOJQBoNEo+FYPr+L1dWS6989oF73SBoFTGqPEufmGtTrMBwmzIRYJr5afJ2ZmRpnvrhHp10QMkNEMBP8eGYRIfPKwsIOzp7dgUPpdgd8fmon+/ZN8/Vii1brMY1NnpRk4slPDt7I88i5c7+ze0+DL8+8xsfHtvHue1u5cvlPbv/QYXraE0t5SrLi5udbNnlnpzxaHXLwYJOl5V0AfH/rH5aXfmVTw5Pkia9xc/fR0Xtqpk61epoQjJXukLePbGH/gSbfXLyvKYl5N5pbk1c1V7UQc0c//MVUKuv9XqToRbxT8rwklonNm+vUaoGUUtWSIVCioyZBRDohC7Nra4XMzU1nbx2eQUTx3sw55x78nd9ut4v7IbBxamPo/fzjw0Pt9nBv5r0TjZ1gYqfV2bUQQray0ue31mN09O8No9Mutna7A8281ZynzPNB07vMmSaA0w7gnSM/nazV6gt5XmwvioE3rZyYKhs21AghQ2Q8Qon3/BFTb+nO3WNX/wPM/kH838PCqgAAAABJRU5ErkJggolQTkcNChoKAAAADUlIRFIAAAAgAAAAIAgGAAAAc3p69AAABdBJREFUeJy1l19snlUZwH/POe/7/Wm7dc3YtIhbhw4WLuzuFC/UaOLishBx/YZBZQh6uRFYhMCgqESFZXDBQkyMNxq9aeEGp4ZEXQwJUaIzgpOtM7YUGaW4Ytuv/f685zyPF1//fF/7tWzLdq7ec95znt95nvP8OUcASiXzw8MS7zg42teZ9hyOqvtMsz4zTcxMMMXMMDPAFr4VzJbGjdZ+0xoDAsiYwclKpfzsSy/dOlYqDfnh4QNRFuF33fXOgSTXecK5dGsI88RYXynoSuBLa0Q8zuWIoT4Zw+yhX/3m00OlknkBOHjPf/fn0g3PhzBPCJWI4QzaaK4LgCa4adPG1t6wmZqpqnOpdy5PVp8eOPnbW1+Qu++++FGcvSbiu0OoKuDbC7pyePMaVY3eJS5qNp1E9wkXJRxJks5NIVSuORwUEXyINU18cVNG/Ugixm0hzFvD7NcS3vLtQqyaYbcloNtirIvBNYY3zTNENUPNtjmzq2/2xb5zIGKYNsOXnBLBfLLebq8ULhghKnPlQC6FXF4wbYYvr3FXHS5GvR7pKDr2D3yYnTd1Uq3EhiVWwM2M5GqeuWBobIwdfWwn/f0bmZ0NfPve00xN1Ug8q2S5q+lwIka5nHH/AzfS37+REIx83uEcjSNoI8u1Dl453HuYmqrz9W98hC/u2UKWKUkiHD82wtv/mSeXE1RXy3LtwsZYhosYzhkirAt///06n//Cddxz7zayTElTx89/9ia/PnmBri5PjNpWkVVR0Kw5GPNzgRCVxEOhIMRgLcXHOWN2NrBrVycPPvQxYjTS1HHqD5P89CejbOpO14SbaWsUNMMFo1qJfPZzmzh6dDu33NLBzEzA++X5IkatFunuThj87k0UCh7vhXNnZ3nqR+coFh1qa8NbomCl5vVM2bw54YEjN+CdsHv3Bh55eIRzZ8t0bfCEYJgaMRqPDu6kt7eAKly8WGfwsTNkWSSfF2JcP8+4lWduTaYtlzNG/10FoKvL8/0nPs72vgKzMxlpAjMzde67fwe7d3cTghGj8r3HzzBxoUKh4NaFLzthu8uEKk6MWk15fPBfvDlWQQR6elKefPJmduwo8tb4PHd+7Qb27v3Qksc/c3yE03+dYsNGTwjxA+pBoy+l0llrF2qLVpifD1y3OeXY8Zvp7c0DMDFRY3jobQ4dvpEQjCQRfvmLcZ47cZ6enpQQ9BJqy0LmHBh4w9aLc+9gbi6wdWvKU8d20Xt9gcUWo+G98PIf3+PoI6/T1ekXYv3SC5v7oCQTglIsOiYmqjz4nX8y+W4NgGo14r1w/nyZH/7gDQoFh9rlwRuZ8BLqeQiRjg7HOxcqPPzQGd6dqFIoeN6brDH46D+o1SKJB9XLg5sZ8pXbX7f14EuC1HAeyuWM3t48n/xUD395dYq3xucoFh2hyeMvp7bI7V9+zdaDQ2sddw5qtUBlPpAvONIECzEu5xBrJwtZq7YkZhobl9HV8BAiIVOM1kVg5PJCDIqpE+cSVHWFFWVhbsSIbeFqFhOwcZGkTzWAIc3wri5Pd3d+WTNWaAjMTNemskxrIjhtUgIDQKs125jVpROaU7IaJGDZeBKjvpjL5e4LWSWCeDA0KrlUeO7H/WzZkmONpoADzgNPAMWFscXmgPro6Gzvtw6eeibL6MDUzEzUTBOXerXai4nzuafr9fJB53LdMdYi4BEjy4zf/26S7duLqBnSwjZUTUSE2ZmsODIy/Zk0dXnTaLb4P6oU8kk2NjpzfcgsZ6ZGAx6dJC7Euf95sacFYN+XXt2f5rqej6FCiLUI5kxN5ubCulmMheMqFAtLN9/Fo2rOByJ1sGiqps4lXiQl1ucGXvnbV19Yepzu3fPKAZ/kTzhJtoZYRTXDSRuPXuGQZoZG1aWi1lJXGmOm5sDjXILGbDLE6qE/nb5zqFQaajxOFzexZ8+pvsR1HjbVfWbLz/P16vkqq6z2dgOCmYyBnAz16Wf//PdvLj3P/w/aOKOBQ3PpMAAAAABJRU5ErkJggolQTkcNChoKAAAADUlIRFIAAAAwAAAAMAgGAAAAVwL5hwAACXJJREFUeJzNmm2MVcUZx3/PzNy77wtYoFq0FaloIgkxadNUahFrKBu0aANGEARKUk0amsZarQ3yarUfbKym6WeNH6xs6wto0aRVsYAiFd+qWESQgtgmLOwud9m995yZpx/OPXfvvXuAXS3IfNhz79w5c37zzP/5z5yzR6gq8+ap7ewUD7B4Wdc0i5kfgr8qaJiIhiZFBVUUJTmSHFWB6iOQtqmrq21LcqzpU0n+0A+yT+Fl4+PHn9x4+daEcb3t7LzRp8ySfpg+/SW3efOMeNGig5NdQ9v9qJ9jTKMNoYj3JVQDp4an8v2U8ClnVZ815wMiDhGHj4tehGcGSt13b9r03d0pa2UAacXCJZ9el8+3PeZMftTAwFFAvWowIKc98tXwg78F1UBA1OZcO94P9MSlwqJnn79iY8osqWwWLf302rxrfTqE2MZxMRbB1QCdVvi6toNSGryuhtiYnFM1PvKF6zdtmvbsvHnrrYDK0qU9EzHyNoQW74sBxNZI4YxHPktiEELw1jijaF8gTH3uuW/uMyAatPhgLtfcGsdFD2I56+CTNiJYH0re2qZW9dGDICoLl376LSf5V0OIFdSkFzsrZFM/0HKdqgbBiPrSt41VWexckwySngl4PjN8+XpqbYMostgFmOF9kbLbnGWyyYBHgWC8L6HoDAdc6H0J4AxY5WeXTXVbVEQ1ArjQidKohLpOzkLZVLdFCUm7Rld/8lkrm+q2VX25s0U2xqSXUDQMDx7AnV74U8sGFAGO9UaEoIhAS7MZFjyDM/DFyCbJSBgYiLn+hnO5bEob77zVw1+e+y/5vJwAngq8orgvUjbOQldXiR8tu4DFSy4A4OqrxyICTz35CW1tDu+zI5/2Y74I2agm8EePRsz8/jgWL7mAOFZKpYD3yrTvnJPkwglkUy17d8bdRhVr4VhvzGVTWrnjjknlOkEAa4U3d3ZX+smSTXW/7kzLxhgYGPB8aWyOlasm09BoUIUQFOuELVsO88QfD9DaavE+ZEe+isENfjnNskERFO+TuhUrL2b8+AZCSM63VtjzYYH71n2AtQxeJ0M21TNtqgGHCy8oyAhkUz6KQKEQcfvPJzFlSjveKyKCMcLRIyVW3vMexaLHOSGEkA1flxdmpJE3onivxFEih+HCWwtHj5a45ZbzmTlzHN4rxgiqShwra1fv4pOD/TQ1GbzPgNd6xqoZGEnk+457GhqEtnZDoRAjMhx4obs74pprxrF02Vcr8CEkx989+CGvv36E9nZLHJ8g8vUzXWZ1I4l833HPlVeOYtmy82hoEDZuOMwjjxyivd3ifTa8MVA4FnHJJa3cceckVKnAWyusf+IgT/35E8aMcUTR8GRTzeqGG/koCpwzxrF8+QRaWpIsu3nhuRQKMevX/4fRoy1xPFTzxaJn1GjHqtWTaWqyhEDFNrdt7eIPv9/DqFHJgnUyt8mCV60k8cndBpQQlKYmobk5iXYI4L1y623nM2fOOLq6IpylBj6E5Lx7Vk3mvK80lh0ngf/ooz5+vW4XDQ0mI4dOLptU9lQn8cmsMgQllxMOHizy/PNdWJskXyqF5T/9Gh0dY+k6UsKVLVAkkc7Pbp/E1Kmjahyntydi9T3v0d8f41wy0JHIpnqFNqeCr8yAKo0NwsMP/ZsXyoNIdo/Jw70777qIWbPGcqSrRD4vHDlS5KYFE+joGF/jOMEr69bsYv/+vspsDsdtagJdVWeGvUhp4v2NjcJvH9jHi387UhlEWu765dfpmD2ej/f1MWPGWH5864VDHOfhh/awbdth2ttd4jifQTY1SXxq+FqfR6C52fDAA3tpbBSumDamAimSDGL0aMdN8ycgUus4f+o8SOf6A4wefQL4EUQ+rZO5c3dplv1lnZz6vJQXsyjyrFh5MdPKg7C28qy4UtL67a8d4a5fvENTk6mAjsRtMpmSHMiC54TwaVIbA84J69bsZuuWRE6pFYaQXCiN/P79x7l37fvkc4KU8+nzyKZuK3Fq2WStsCGE8iBg7ep/sWNHN9YKcTyYsCLCsWMxK1f8k0IhIpcTfNlKR+o2mW6p6Q1NjWxODZ8ONATFGrAW1q7axdtv9ZQ3YlRy4t4177P3owLNzZbYh0H4EbpNNhuY4comc5ZU8UFxTihFgRW/eo9XNh8GlN7eiN/c9wFbt1Y5zv9JNtWzJz+84V0drmzq4auT0BiIokAUBS6a1Myx3ohDh/ppbXXl3eXnc5sseEXTxyojj3zNbxW3AWsNe/cUMJah8J/DbbLgUcUNFz49cQh82hat7HWS20Ql1ET+5PAjkU11QJ1qGABpTDBOAK/J5kwSC09uxiT7mI5TAJLn3XivYVD7WhkwVfBpXfkoqsjJ4YUQ/IBT9GNrcpd6P6CoyFD4ZGMWR4H+fj94A1PpPHvAaSRVA03J6lU7a5nt0/SOEfEnhFeCGnIC8ccO5SWR3KUaBgKitn6KRJQ4DrS1O36yfCJjxuQqM5IWRZNZqK4JqmJE+grxh7t39zxrRUyo/Fz5RCj/VVVx1vje7mLLyy8emtvfL+cgIZnMOtlqIIh1Vim+5BT/qI+P34bUNkz1ag0UjsUsWDCB2dd+mREUSfTBxTNnnXcA2AU0V5iziwO6iqU4/9cXDi8x1gdU7SBTJUriw4AGDY+6DRu+sf26a3dszOdH/aBU7I5BXAqPKkGhoUHYubOba2aOo6XFVaKfEmYVVVURkRDCgd7eUvBezhcRm32Kx3vEWjSKwthd73ZfDjGqWidp0OBjl2tzUdS7YfsbC7YLqMyevWOiM/m3QVu8LwWgVkoCxQFPa5ulqewwlUFmusvg975C1Bu8xmI1p6GcMZU2teuDQIgj3xjFplGI6rQPqt6LOIPSJxSnbvnHgn1u3rxO09l5496Ojq3zc7b1aWOc9b4UQ9X/DgLkG4T+4zGFY7U2WJPEGYOw1rQLEKJqmOo2DAmGSFS+FU9/BFUfi+QciA/h+PxXdy7cO29ezta8ajBr1t+vy7vWx4zJj4pKPQRVL6hJLa0+2vWukuXvqkErbjWkTcbsJbOR5KMGVSUIaq1txYdij4/7F7325s0bp09f5TZvXhMPedmj43uvTLb55vtV/Rxr89aHEsFHlenWwZAxRJ8nWaROtavMWqQEi4gj9iVvhGeKxcLdO95ZvDuFT/OwUqpfZeno2DbNqMzXoFcF9RNBm1SDDIUnQz51oCOHV1XtFzH7UHmZEB7f8sbczNdt/gctfz24kxR2WAAAAABJRU5ErkJggg==');
define('TB_FAVICON_SVG_B64', 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCI+CiAgPGRlZnM+CiAgICA8bGluZWFyR3JhZGllbnQgaWQ9ImciIHgxPSIwIiB5MT0iMCIgeDI9IjEiIHkyPSIxIj4KICAgICAgPHN0b3Agb2Zmc2V0PSIwIiBzdG9wLWNvbG9yPSIjNjM2NmYxIi8+CiAgICAgIDxzdG9wIG9mZnNldD0iMSIgc3RvcC1jb2xvcj0iIzM3MzBhMyIvPgogICAgPC9saW5lYXJHcmFkaWVudD4KICA8L2RlZnM+CiAgPHJlY3QgeD0iMSIgeT0iMSIgd2lkdGg9IjYyIiBoZWlnaHQ9IjYyIiByeD0iMTQiIGZpbGw9InVybCgjZykiLz4KICA8cGF0aCBkPSJNMTguNzUgMzMuNSBMMjkgNDMuNSBMNDcuMjUgMjIiIGZpbGw9Im5vbmUiIHN0cm9rZT0iI2ZmZmZmZiIgc3Ryb2tlLXdpZHRoPSI1Ljc1IiBzdHJva2UtbGluZWNhcD0icm91bmQiIHN0cm9rZS1saW5lam9pbj0icm91bmQiLz4KICA8cmVjdCB4PSIxNCIgeT0iNTMuNzUiIHdpZHRoPSIzNiIgaGVpZ2h0PSIzLjUiIHJ4PSIxLjc1IiBmaWxsPSIjZmZmZmZmIiBvcGFjaXR5PSIwLjM2Ii8+CiAgPHJlY3QgeD0iMTQiIHk9IjUzLjc1IiB3aWR0aD0iMTkuNSIgaGVpZ2h0PSIzLjUiIHJ4PSIxLjc1IiBmaWxsPSIjZmZmZmZmIi8+Cjwvc3ZnPgo=');
if ($page==='favicon') {
    $svg = ($_GET['type'] ?? '') === 'svg';
    $file = __DIR__ . ($svg ? '/favicon.svg' : '/favicon.ico');
    if (is_file($file)) {
        header('Content-Type: ' . ($svg ? 'image/svg+xml' : 'image/x-icon'));
        header('Content-Length: ' . (string)filesize($file));
        header('Cache-Control: public, max-age=86400');
        readfile($file);
        exit;
    }
    // The docroot copy is missing (a deployment that never landed the
    // static/binary files - the exact live failure this task keeps hitting),
    // so serve the bytes embedded above instead of 404ing.
    $bytes = base64_decode($svg ? TB_FAVICON_SVG_B64 : TB_FAVICON_ICO_B64, true);
    if ($bytes === false) { http_response_code(404); exit('Not found.'); }
    header('Content-Type: ' . ($svg ? 'image/svg+xml' : 'image/x-icon'));
    header('Content-Length: ' . (string)strlen($bytes));
    header('Cache-Control: public, max-age=86400');
    echo $bytes;
    exit;
}

if ($page==='cronjob') {
    header('Content-Type: text/plain; charset=utf-8');
    try {
        $reopened = reopen_due_tasks($pdo);
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Auto-scheduler error: ' . $e->getMessage());
    }
    if (!$reopened) exit('Auto-scheduler: nothing to reopen.');
    exit('Auto-scheduler reopened ' . count($reopened) . ' task(s): ' . implode(', ', array_map(fn(int $id): string => '#' . $id, $reopened)) . '.');
}
// ---- End task scheduler cron controller ---------------------------------------------------

if (!user() && !in_array($page,['login','install'],true)) redirect('?page=login');

// ---- Live badge counters (#64) -------------------------------------------------------------
// A tiny JSON feed for the navbar badge poller: the red messages and
// notifications badges are server-rendered at page load, and this endpoint
// lets the footer script keep them current between page loads (#64 - the
// flag should show up without a manual refresh). Read-only, login-guarded
// like the pages whose badges it reports, and marked no-store so no proxy
// serves one user's counters to another.
if ($page==='badges') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    exit(json_encode([
        'messages' => tb_unread_message_count($pdo, (int)user()['id']),
        // #100: the notification flag toggle hides the badge entirely, so the
        // poller must report 0 for a user who switched it off - otherwise the
        // 10-second feed would re-create the badge the server omitted.
        'notifications' => tb_notification_flag_enabled($pdo, (int)user()['id']) ? tb_unseen_notification_count($pdo, (int)user()['id']) : 0,
    ]));
}

if ($page==='login') {
    render_header('Sign in',$config,$error); ?>
    <div class="row justify-content-center"><div class="col-md-5"><div class="card p-4"><h2 class="h4 mb-3">Sign in</h2><form method="post"><?=csrf_field()?><div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" autocomplete="username" required autofocus></div><div class="mb-3"><label class="form-label">Password</label><input class="form-control" type="password" name="password" autocomplete="current-password" required></div><button class="btn btn-primary w-100">Sign in</button></form><?php if ((int)user_count($pdo) === 0): ?><p class="small muted mt-3 mb-0">First time here? <a href="?page=install">Create the administrator account</a>.</p><?php endif; ?></div></div></div>
    <?php render_footer(); exit;
}
if ($page==='install') {
    render_header('Initial setup',$config,$error); ?>
    <div class="row justify-content-center"><div class="col-md-6"><div class="card p-4"><h2 class="h4">Create administrator</h2><p class="muted">Run schema.sql in phpMyAdmin before continuing. This page only works while there are no users.</p><form method="post"><?=csrf_field()?><div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" required></div><div class="mb-3"><label class="form-label">Display name</label><input class="form-control" name="display_name" required></div><div class="mb-3"><label class="form-label">Password (10+ characters)</label><input class="form-control" type="password" name="password" minlength="10" required></div><button class="btn btn-primary">Create administrator</button></form></div></div></div>
    <?php render_footer(); exit;
}

if ($page==='download') {
    $id=(int)($_GET['id']??0); $s=$pdo->prepare('SELECT * FROM attachments WHERE id=?'); $s->execute([$id]); $a=$s->fetch();
    if(!$a || !is_file(__DIR__.'/uploads/'.$a['stored_name'])) { http_response_code(404); exit('File not found.'); }
    header('Content-Type: '.$a['mime_type']); header('Content-Length: '.$a['file_size']);
    // Strip quotes and control characters (including CRLF) from the stored name
    // so the Content-Disposition header can never be split or malformed.
    $tbName = preg_replace('/[\x00-\x1f\x7f"]+/', '', (string)$a['original_name']) ?? '';
    if ($tbName === '') $tbName = 'attachment';
    header('Content-Disposition: attachment; filename="'.$tbName.'"');
    readfile(__DIR__.'/uploads/'.$a['stored_name']); exit;
}

if ($page==='doc_download') {
    // Know-how attachments (#53): the same headers and sanitization as the
    // task attachment download route above, aimed at the other table.
    $id=(int)($_GET['id']??0); $s=$pdo->prepare('SELECT * FROM document_attachments WHERE id=?'); $s->execute([$id]); $a=$s->fetch();
    if(!$a || !is_file(__DIR__.'/uploads/'.$a['stored_name'])) { http_response_code(404); exit('File not found.'); }
    header('Content-Type: '.$a['mime_type']); header('Content-Length: '.$a['file_size']);
    $tbName = preg_replace('/[\x00-\x1f\x7f"]+/', '', (string)$a['original_name']) ?? '';
    if ($tbName === '') $tbName = 'attachment';
    header('Content-Disposition: attachment; filename="'.$tbName.'"');
    readfile(__DIR__.'/uploads/'.$a['stored_name']); exit;
}

if ($page === 'updates') {
    if (!is_admin()) { http_response_code(403); exit('Forbidden.'); }
    require __DIR__ . '/updater.php';
    task_board_update_page($pdo, $config);
    exit;
}

// ---- Task archive (#12; #57-D list v2 on the shared CRUD engine) -----------
// Archived (soft-deleted) tasks: view, restore or delete them permanently.
// The #57-D rebuild runs the restorable table through the shared CRUD engine
// (src/crud.php): the rows render exactly what the hand-built table rendered,
// and the page gains the search/sort/pagination mechanics of the other lists.
// The generated links (search form, pagination, sort headers, the per-row
// delete returns) carry the canonical page name, so bookmarks and saved
// links survive.
if ($page === 'archive') {
    $archivePage = 'archive';
    // The search of the task list, narrowed to the archived rows: a literal
    // %, _ or | never acts as a wildcard, and the values reach the query only
    // as bound parameters.
    $conds=['t.archived_at IS NOT NULL']; $params=[];
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $term = str_replace(['|','%','_'],['||','|%','|_'],$q);
        $conds[] = "(t.title LIKE ? ESCAPE '|' OR t.description LIKE ? ESCAPE '|')";
        $params[] = '%'.$term.'%'; $params[] = '%'.$term.'%';
    }
    $list=tb_crud_list([
        'pdo'=>$pdo,
        'table'=>'tasks t JOIN statuses s ON s.id=t.status_id LEFT JOIN users u ON u.id=t.created_by',
        'select'=>'t.id,t.title,t.archived_at,t.updated_at,t.created_by,s.name status_name,s.color status_color,u.display_name creator,u.last_seen_at creator_last_seen',
        'where'=>$conds,
        'params'=>$params,
        'sortColumns'=>[
            'task'=>'t.title {dir}',
            'status'=>'s.sort_order {dir}, s.name {dir}',
            'creator'=>'u.display_name {dir}',
            'archived'=>'t.archived_at {dir}',
        ],
        // The order of the hand-built page: newest archived first.
        'defaultSort'=>'archived',
        'defaultDir'=>'desc',
        'orderBySuffix'=>'t.id {dir}',
    ]);
    $archivedTasks=$list['rows'];
    $archiveUrl=tb_crud_url_builder($archivePage,['q']);
    $archiveColumns=[
        ['label'=>'Task','sortKey'=>'task','sort'=>$list['sort'],'url'=>$archiveUrl,'cell'=>fn(array $t): string => '<td class="ps-3"><a class="fw-semibold text-decoration-none" href="?page=task&id='.$t['id'].'">#'.$t['id'].' · '.h($t['title']).'</a></td>'],
        ['label'=>'Status','sortKey'=>'status','sort'=>$list['sort'],'url'=>$archiveUrl,'cell'=>fn(array $t): string => '<td>'.tb_status_badge((string)$t['status_name'], $t['status_color'] ?? null).'</td>'],
        ['label'=>'Creator','sortKey'=>'creator','sort'=>$list['sort'],'url'=>$archiveUrl,'cell'=>fn(array $t): string => '<td>'.tb_user_with_activity(['display_name'=>$t['creator'],'last_seen_at'=>$t['creator_last_seen']??null]).'</td>'],
        ['label'=>'Archived','sortKey'=>'archived','sort'=>$list['sort'],'url'=>$archiveUrl,'cell'=>fn(array $t): string => '<td class="small muted">'.h(tb_ago($t['archived_at'])).'</td>'],
        ['label'=>'Actions','cell'=>function(array $t) use ($archivePage): string {
            $canDelete=is_admin()||(int)$t['created_by']===(int)user()['id'];
            return '<td class="text-nowrap"><form method="post" class="d-inline">'.csrf_field().'<input type="hidden" name="action" value="restore_task"><input type="hidden" name="id" value="'.$t['id'].'"><button class="btn btn-sm btn-outline-primary">Restore</button></form>'.($canDelete?' <form method="post" class="d-inline" onsubmit="return confirm(\'Delete task #'.$t['id'].' permanently? This cannot be undone.\')">'.csrf_field().'<input type="hidden" name="action" value="delete_task"><input type="hidden" name="id" value="'.$t['id'].'"><input type="hidden" name="return" value="?page='.$archivePage.'"><button class="btn btn-sm btn-outline-danger">Delete</button></form>':'').'</td>';
        }],
    ];
    render_header('Archive',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Archive</h1><div class="muted"><?=$list['total']?> archived task<?=$list['total']===1?'':'s'?></div></div><a class="btn btn-outline-secondary" href="?">Back to tasks</a></div>
    <p class="small muted">Archived tasks are hidden from the task list but kept here. Restore brings a task back unchanged; Delete removes it and its comments, attachments, history and links permanently.</p>
    <form method="get" class="d-flex gap-2 mb-3"><input type="hidden" name="page" value="<?=$archivePage?>"><input class="form-control" type="search" name="q" value="<?=h($q)?>" placeholder="Search title and description..." aria-label="Search archived tasks"><button class="btn btn-outline-secondary">Search</button><?php if($q!==''): ?><a class="btn btn-link" href="?page=<?=$archivePage?>&amp;clear_search=1">Clear</a><?php endif; ?></form>
    <div class="card"><?=tb_crud_table(['columns'=>$archiveColumns,'rows'=>$archivedTasks,'emptyText'=>$q!==''?'No archived tasks match the search.':'The archive is empty.','emptyColspan'=>5])?></div>
    <?php echo tb_crud_pagination(['ariaLabel'=>'Archive list pages','page'=>$list['page'],'pages'=>$list['pages'],'url'=>$archiveUrl]), "\n"; ?>
    <?php render_footer(); exit;
}

// ---- Messages older-page feed (#94) ----------------------------------------
// The JSON endpoint behind the chat's infinity scroll: given a conversation
// (?u=peer id, or u=0 for the deleted-user thread) and the id of the oldest
// message already rendered (?before=), it returns the next older page of up
// to 20 bubbles as ready-made HTML - the exact markup tb_message_bubble()
// renders on the page itself, so pulling history changes nothing visually
// and the escaping stays server-side. Every row is scoped to the signed-in
// user by the pair WHERE clauses, so a foreign ?u= yields an empty page and
// an unknown one a 404; nothing outside a visitor's own conversations can
// leave this endpoint.
// ---- Messages live feed (#114) ----------------------------------------------
// The JSON poll endpoint that makes the messenger page behave like a chat
// app between full loads: it returns the bubbles newer than ?after= for the
// open conversation, the people rows (unread badges, activity indicators,
// open-conversation highlight) exactly as a fresh load would render them,
// and the viewer's unread count for the page badge. Viewing semantics stay
// the board's own: the bubbles this feed hands over are on screen now, so
// exactly the open conversation's incoming messages flip to read here - a
// message from anybody else keeps its red badge until their conversation is
// opened. The poll cadence and the hidden-tab rules mirror the shared list
// refresh script (#51).
if ($page==='messages_live') {
    // The poll is navigation-invisible (#114 x #113 interplay, disclosed):
    // tb_list_view_boot() already stamped this request into the session's
    // last-page marker, but the visitor is in fact still sitting on the
    // messenger page - restoring the marker keeps the "consecutive requests
    // of the same list page never replay" guarantee intact while the tab
    // polls away.
    if (($_SESSION['tb_last_page'] ?? null) === 'messages_live') {
        $_SESSION['tb_last_page'] = 'messages';
    }
    $me=(int)user()['id'];
    $u=isset($_GET['u'])?(int)$_GET['u']:-1;
    $after=max(0,(int)($_GET['after'] ?? 0));
    // The people rows carry the chat page name in their links; the feed
    // always builds them for the messenger's canonical page name.
    $chatPage='messages';
    if ($u!==0 && $u<=0) { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); exit(json_encode(['error'=>'Unknown conversation.'])); }
    if ($u>0) {
        $s=$pdo->prepare('SELECT 1 FROM users WHERE id=?'); $s->execute([$u]);
        if(!$s->fetchColumn()){ http_response_code(404); header('Content-Type: application/json; charset=utf-8'); exit(json_encode(['error'=>'Unknown conversation.'])); }
    }
    // The new bubbles of the open thread, oldest first - the append order of
    // a chat log. A cap keeps one poll bounded; the cursor advances over the
    // rows actually returned, so the next poll drains the rest.
    if ($u===0) {
        $t=$pdo->prepare('SELECT * FROM messages WHERE ((recipient_id=? AND sender_id IS NULL) OR (sender_id=? AND recipient_id IS NULL)) AND id>? ORDER BY id ASC LIMIT 50');
        $t->execute([$me,$me,$after]);
    } else {
        $t=$pdo->prepare('SELECT * FROM messages WHERE ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?)) AND id>? ORDER BY id ASC LIMIT 50');
        $t->execute([$me,$u,$u,$me,$after]);
    }
    $rows=$t->fetchAll();
    if ($rows && $u>0) {
        $pdo->prepare('UPDATE messages SET is_read=1 WHERE recipient_id=? AND is_read=0 AND sender_id=?')->execute([$me,$u]);
    }
    $html='';
    $lastId=$after;
    $feedAttachments=tb_message_attachments($pdo,array_map(function($m){return (int)$m['id'];},$rows));
    foreach ($rows as $m) {
        $mine=$m['sender_id']!==null&&(int)$m['sender_id']===$me;
        $html.=tb_message_bubble($m,$mine,$feedAttachments[(int)$m['id']]??[],is_admin());
        $lastId=(int)$m['id'];
    }
    $people=tb_chat_people_model($pdo,$me);
    $feedPeer=null;
    if ($u>0) {
        $fp=$pdo->prepare('SELECT id, display_name, last_seen_at, logged_out FROM users WHERE id=?');
        $fp->execute([$u]);
        $row=$fp->fetchAll();
        $feedPeer=$row?['id'=>(int)$row[0]['id'],'display_name'=>(string)$row[0]['display_name'],'last_seen_at'=>$row[0]['last_seen_at'] ?? null,'logged_out'=>(int)($row[0]['logged_out'] ?? 0)]:null;
    } elseif ($u===0) {
        $feedPeer=['id'=>0,'display_name'=>'Deleted user','last_seen_at'=>null,'logged_out'=>0];
    }
    // #118: how far the seen status has reached for the viewer's own messages
    // in the open conversation - the peer opening the thread flips the Sent
    // labels to Seen in place, without a page reload.
    if ($u>0) {
        $seen=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM messages WHERE sender_id=? AND recipient_id=? AND is_read=1');
        $seen->execute([$me,$u]);
    } else {
        $seen=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM messages WHERE sender_id=? AND recipient_id IS NULL AND is_read=1');
        $seen->execute([$me]);
    }
    $seenUpTo=(int)$seen->fetchColumn();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    exit(json_encode([
        'html'=>$html,
        'lastId'=>$lastId,
        'peopleHtml'=>tb_chat_people_rows($people['entries'],$people['unreadBySender'],$people['orphanUnread'],$feedPeer,$chatPage),
        'newCount'=>tb_unread_message_count($pdo,$me),
        'seenUpTo'=>$seenUpTo,
    ], JSON_UNESCAPED_UNICODE));
}

if ($page==='messages_more') {
    $me=(int)user()['id'];
    $u=isset($_GET['u'])?(int)$_GET['u']:-1;
    $before=(int)($_GET['before'] ?? 0);
    if ($u!==0 && $u<=0) { http_response_code(404); exit('Unknown conversation.'); }
    if ($u>0) {
        $s=$pdo->prepare('SELECT 1 FROM users WHERE id=?'); $s->execute([$u]);
        if(!$s->fetchColumn()){ http_response_code(404); exit('Unknown conversation.'); }
    }
    if ($u===0) {
        $t=$pdo->prepare('SELECT * FROM messages WHERE ((recipient_id=? AND sender_id IS NULL) OR (sender_id=? AND recipient_id IS NULL))'.($before>0?' AND id<?':'').' ORDER BY id DESC LIMIT 21');
        $t->execute($before>0?[$me,$me,$before]:[$me,$me]);
    } else {
        $t=$pdo->prepare('SELECT * FROM messages WHERE ((sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?))'.($before>0?' AND id<?':'').' ORDER BY id DESC LIMIT 21');
        $t->execute($before>0?[$me,$u,$u,$me,$before]:[$me,$u,$u,$me]);
    }
    $rows=$t->fetchAll();
    $feedHasMore=count($rows)>20;
    if ($feedHasMore) $rows=array_slice($rows,0,20);
    $rows=array_reverse($rows);
    $feedAttachments=tb_message_attachments($pdo,array_map(function($m){return (int)$m['id'];},$rows));
    $html='';
    foreach ($rows as $m) {
        $mine=$m['sender_id']!==null&&(int)$m['sender_id']===$me;
        $html.=tb_message_bubble($m,$mine,$feedAttachments[(int)$m['id']]??[],is_admin());
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    exit(json_encode([
        'hasMore'=>$feedHasMore,
        'oldestId'=>count($rows)?(int)$rows[0]['id']:null,
        'html'=>$html,
    ], JSON_UNESCAPED_UNICODE));
}

// ---- Message attachment download (#94) -------------------------------------
// Serves one chat attachment to the participants of its conversation only:
// the sender or the recipient of the parent message (either side may be the
// deleted user; a NULL user id never matches a viewer, so a stranger's
// request stops at the participant check with a 403 before the filesystem
// is ever touched). File output mirrors the task/document download routes.
if ($page==='message_download') {
    $id=(int)($_GET['id']??0);
    $s=$pdo->prepare('SELECT a.*, m.sender_id sender_user, m.recipient_id recipient_user FROM message_attachments a JOIN messages m ON m.id=a.message_id WHERE a.id=?');
    $s->execute([$id]);
    $a=$s->fetch();
    if(!$a) { http_response_code(404); exit('File not found.'); }
    $me=(int)user()['id'];
    if (!((int)$a['sender_user']===$me || (int)$a['recipient_user']===$me)) { http_response_code(403); exit('Forbidden.'); }
    if (!is_file(tb_attachment_path((string)$a['stored_name']))) { http_response_code(404); exit('File not found.'); }
    header('Content-Type: '.$a['mime_type']); header('Content-Length: '.$a['file_size']);
    $tbName = preg_replace('/[\x00-\x1f\x7f"]+/', '', (string)$a['original_name']) ?? '';
    if ($tbName === '') $tbName = 'attachment';
    header('Content-Disposition: attachment; filename="'.$tbName.'"');
    readfile(tb_attachment_path((string)$a['stored_name'])); exit;
}

// ---- Message board (#56, messenger redesign #65, chat UX #94) ---------------
// A messenger-style board: every other user on the left with their unread
// count, the thread with the selected person as chat bubbles on the right
// (mine carry the sending time and the Seen/Sent status), and the sender
// form under the thread. Viewing the board marks the messages read - the
// badge on the page and in the navbar always means "new since the last
// visit". The navbar carries the same count as a badge.
// Chat UX (#94): the thread fills the page height and scrolls (1), opens on
// the last 20 messages with the composer pinned to the bottom (2, 4) and
// pulls older pages on demand while scrolling up (3), Enter sends while
// Shift+Enter / Ctrl+Enter make a newline (5), the composer carries an
// attachment button (6), the people list orders by latest conversation (7)
// and on mobile only one pane shows at a time (8-10).
if ($page === 'messages') {
    // The #57-E rebuild runs the messenger's people list through the shared
    // CRUD engine (src/crud.php): the rows render exactly what the hand-built
    // list rendered, and the page gains the search/pagination mechanics of the
    // other lists while the #94 chat regions stay untouched. The generated
    // links (people rows, the back arrow, the search form, the pagination)
    // carry the canonical page name, so bookmarks and the #98 deep links
    // survive.
    $chatPage = 'messages';
    $me=(int)user()['id'];
    $newCount=tb_unread_message_count($pdo,$me);
    // The navbar badge renders after the mark-read below, so it reuses this
    // pre-mark-read count instead of querying a self-consumed zero.
    $GLOBALS['TB_UNREAD_BADGE']=$newCount;
    // The left-pane read model (#114) is shared with the messages_live feed:
    // per-person unread counts, the orphan counters and the people rows in
    // the #94 activity order. The unread maps are read before the mark-read
    // below, so the left list shows what is new on this very load.
    $pq = trim((string)($_GET['q'] ?? ''));
    $chatPeopleModel=tb_chat_people_model($pdo,$me,$pq);
    $unreadBySender=$chatPeopleModel['unreadBySender'];
    $orphanUnread=$chatPeopleModel['orphanUnread'];
    $chatEntries=$chatPeopleModel['entries'];
    $peopleList=['page'=>$chatPeopleModel['page'],'pages'=>$chatPeopleModel['pages']];
    $peopleUrl=tb_crud_url_builder($chatPage,['q','u']);
    // Viewing the board is what marks the messages read - the badge on the
    // page and in the navbar always means "new since the last visit".
    $pdo->prepare('UPDATE messages SET is_read=1 WHERE recipient_id=? AND is_read=0')->execute([$me]);
    // The selected conversation: ?u=<user id>, with u=0 as the "Deleted user"
    // thread (#17: messages survive account deletion, both FKs SET NULL).
    // Without a valid selection the newest unread sender wins, else the
    // conversation with the most recent activity, else the first person of
    // the list. The peer is resolved straight from the users table (#57-E):
    // the people list now paginates, so a deep link to a person whose row
    // sits on another people page must still open exactly their conversation
    // instead of falling through to the auto-select.
    $explicitPeer=isset($_GET['u']);
    $peerId=isset($_GET['u'])?(int)$_GET['u']:-1;
    $peer=null;
    $peerExplicit=false;
    $peerRowById=function(int $id) use ($pdo): ?array {
        $s=$pdo->prepare('SELECT id, display_name, last_seen_at, logged_out FROM users WHERE id=?');
        $s->execute([$id]);
        $row=$s->fetchAll();
        return $row ? ['id'=>(int)$row[0]['id'],'display_name'=>(string)$row[0]['display_name'],'last_seen_at'=>$row[0]['last_seen_at'] ?? null,'logged_out'=>(int)($row[0]['logged_out'] ?? 0)] : null;
    };
    if ($peerId>0 && $peerId!==$me) {
        // The self id is excluded on purpose: the people list never contains
        // the visitor's own row (no self-conversation), so a crafted
        // ?u=<own id> falls through to the auto-select exactly like it did
        // when the peer was looked up in the rendered list rows.
        $peer=$peerRowById($peerId);
        if ($peer!==null) $peerExplicit=true;
    }
    if ($peerId===0 && $explicitPeer) $peerExplicit=true;
    if ($peer===null && $peerId!==0) {
        if ($unreadBySender) {
            $newest=$pdo->prepare('SELECT sender_id FROM messages WHERE recipient_id=? AND is_read=0 AND sender_id IS NOT NULL ORDER BY id DESC LIMIT 1');
            $newest->execute([$me]);
            $newestId=(int)($newest->fetchAll(PDO::FETCH_COLUMN)[0] ?? 0);
            if ($newestId>0) $peer=$peerRowById($newestId);
        }
        if ($peer===null) {
            $recent=$pdo->prepare('SELECT CASE WHEN sender_id=? THEN recipient_id ELSE sender_id END other_id FROM messages WHERE sender_id=? OR recipient_id=? ORDER BY id DESC LIMIT 1');
            $recent->execute([$me,$me,$me]);
            $otherId=(int)($recent->fetchAll(PDO::FETCH_COLUMN)[0] ?? 0);
            if ($otherId>0) $peer=$peerRowById($otherId);
        }
        if ($peer===null && $chatEntries) { foreach ($chatEntries as $p) { if ((int)$p['id']!==0) { $peer=$p; break; } } }
        $peerId=$peer===null?0:(int)$peer['id'];
    }
    // Single-pane mobile view (#94 points 8-10): the chat row only carries
    // the peer class when the visitor explicitly opened a conversation
    // (?u=...), so the mobile default stays the people list; on desktop the
    // class is inert - the CSS only acts on it inside the mobile breakpoint.
    $hasPeer=$peerExplicit;
    // The thread with the selected person, oldest first like a chat log. For
    // u=0 the thread holds the messages whose other end was deleted. The
    // newest page of up to 20 messages renders on load (#94 point 2); older
    // pages load on demand through the messages_more feed (#94 point 3),
    // fetched by the cursor the scroll container carries.
    $threadRows=[];
    if ($peerId===0) {
        $t=$pdo->prepare('SELECT * FROM messages WHERE (recipient_id=? AND sender_id IS NULL) OR (sender_id=? AND recipient_id IS NULL) ORDER BY id DESC LIMIT 21');
        $t->execute([$me,$me]);
        $threadRows=$t->fetchAll();
    } elseif ($peer!==null) {
        $t=$pdo->prepare('SELECT * FROM messages WHERE (sender_id=? AND recipient_id=?) OR (sender_id=? AND recipient_id=?) ORDER BY id DESC LIMIT 21');
        $t->execute([$me,$peerId,$peerId,$me]);
        $threadRows=$t->fetchAll();
    }
    $hasMore=count($threadRows)>20;
    if ($hasMore) $threadRows=array_slice($threadRows,0,20);
    $thread=array_reverse($threadRows);
    $oldestId=count($thread)?(int)$thread[0]['id']:null;
    // #114: the live feed cursor - the newest message id the page rendered.
    $newestId=count($thread)?(int)$thread[count($thread)-1]['id']:0;
    $threadAttachments=tb_message_attachments($pdo,array_map(function($m){return (int)$m['id'];},$thread));
    render_header('Messages',$config,$error); ?>
    <style>
    .tb-chat{height:calc(100vh - 195px);height:calc(100dvh - 195px);min-height:420px;align-items:stretch}
    /* The chat JS re-sizes the row to the exact space under the page header,
       so the composer stays inside the viewport no matter how the header
       wraps (the calc above is only the no-JS fallback). */
    .tb-chat>.col-lg-4,.tb-chat>.col-lg-8{display:flex;min-height:0;height:100%}
    .tb-chat .card{display:flex;flex-direction:column;flex:1 1 auto;width:100%;min-height:0}
    .tb-chat-people-card{overflow-y:auto}
    .tb-chat-head{flex:0 0 auto}
    .tb-chat-head h2{margin-bottom:0}
    .tb-chat-scroll{flex:1 1 auto;overflow-y:auto;min-height:0;padding:6px 2px}
    .tb-chat-composer{flex:0 0 auto}
    .tb-chat-back{display:none}
    /* #122: the admin delete controls of a bubble sit on their own row,
       checkbox first, the single-message button next to it. */
    .tb-chat-delrow{display:flex;align-items:center;gap:.5rem;margin-top:.25rem}
    .tb-chat-delcheck{opacity:.75;font-size:.875rem;font-weight:400}
    .tb-chat-delone{display:inline}
    @media (max-width: 991.98px){
      /* #117: on a phone the intro (title + description) only pushes the chat
         down - the chat top sits right below the main menu header instead. The
         unread count stays visible on the navbar Messages badge, and the chat
         re-size JS below measures the freed space at runtime. The !important
         is not optional: the intro row carries Bootstrap's d-flex utility,
         whose display:flex!important beats a plain display:none (#117 v2 -
         the pre-v2 rule silently lost and the intro stayed on the phone). */
      .tb-chat-intro{display:none!important}
      /* #129: the "Message sent." ribbon renders on the top of the chat, is
         never dismissed and on a phone only eats chat area - the bubble's
         own Sent/Seen status (#118) already tells that story there, so the
         ribbon is hidden below the lg breakpoint. The !important is the
         same defence the intro hide uses against Bootstrap's display
         utilities. Desktop keeps the ribbon, and any other flash (the
         dropped-submit note included) still renders on the phone - only
         the marked send confirmation hides. */
      .tb-flash-message-sent{display:none!important}
      .tb-chat>.col-lg-4,.tb-chat>.col-lg-8{flex:0 0 100%;max-width:100%;height:100%}
      .tb-chat>.col-lg-8{display:none}
      .tb-chat.has-peer>.col-lg-8{display:flex}
      .tb-chat.has-peer>.col-lg-4{display:none}
      .tb-chat.has-peer .tb-chat-back{display:inline-block}
      .tb-chat-bubble{max-width:88%!important}
    }
    </style>
    <div class="d-flex justify-content-between align-items-center mb-3 tb-chat-intro"><div><h1 class="h3 mb-0">Messages</h1><div class="muted">A messenger-style board: pick a person on the left, chat in bubbles on the right - every message shows when it was sent and whether it was seen.</div></div><span class="badge text-bg-danger fs-6" data-tb-chat-newcount><?=$newCount?> new message<?=$newCount===1?'':'s'?></span></div>
    <div class="row g-4 tb-chat<?=$hasPeer?' has-peer':''?>" data-tb-chat data-tb-chat-live-url="<?=h('?page=messages_live&u='.$peerId)?>">
        <div class="col-lg-4 tb-chat-people"><div class="card p-3 tb-chat-people-card"><h2 class="h5">People</h2>
            <form method="get" class="d-flex gap-2 mb-2"><input type="hidden" name="page" value="<?=$chatPage?>"><?php if($peerExplicit): ?><input type="hidden" name="u" value="<?=$peerId?>"><?php endif; ?><input class="form-control form-control-sm" type="search" name="q" value="<?=h($pq)?>" placeholder="Search people..." aria-label="Search people"><button class="btn btn-sm btn-outline-secondary">Search</button><?php if($pq!==''): ?><a class="btn btn-link btn-sm" href="<?=h($peerExplicit?'?page='.$chatPage.'&u='.$peerId:'?page='.$chatPage)?>&amp;clear_search=1">Clear</a><?php endif; ?></form>
            <div data-tb-chat-people-rows<?php if($pq===''): ?> data-tb-chat-live-people="1"<?php endif; ?>><?=tb_chat_people_rows($chatEntries,$unreadBySender,$orphanUnread,$peer,$chatPage)?></div>
            <?php if(!$chatEntries): ?><div class="small muted"><?=$pq!==''?'No people match the search.':'Nobody to talk to yet.'?></div><?php endif; ?>
            <?=tb_crud_pagination(['ariaLabel'=>'People list pages','page'=>$peopleList['page'],'pages'=>$peopleList['pages'],'url'=>$peopleUrl])?>
        </div></div>
        <div class="col-lg-8 tb-chat-thread"><div class="card p-3">
            <div class="tb-chat-head d-flex align-items-center gap-2"><a class="btn btn-sm btn-outline-secondary tb-chat-back" href="?page=<?=$chatPage?>" aria-label="Back to the people list" title="Back to the people list">&larr;</a><h2 class="h5"><?=h($peer!==null?'Conversation with '.$peer['display_name']:'Deleted user')?></h2><?php if (is_admin()): ?><form method="post" id="tb-chat-bulk-delete" class="ms-auto" onsubmit="return confirm('Delete the selected messages permanently?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_messages"><input type="hidden" name="u" value="<?=$peerId?>"><button class="btn btn-sm btn-outline-danger" title="Delete the selected messages">Delete selected</button></form><form method="post" onsubmit="return confirm('Delete this whole conversation permanently?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_conversation"><input type="hidden" name="u" value="<?=$peerId?>"><button class="btn btn-sm btn-outline-danger" title="Delete the whole conversation">Delete conversation</button></form><?php endif; ?></div>
            <div class="tb-chat-scroll" data-tb-chat-scroll data-tb-more="<?=h('?page=messages_more&u='.$peerId.($oldestId!==null?'&before='.(int)$oldestId:''))?>" data-tb-has-more="<?=$hasMore?'1':'0'?>" data-tb-after="<?=$newestId?>">
            <?php foreach ($thread as $m): $mine=$m['sender_id']!==null&&(int)$m['sender_id']===$me; echo tb_message_bubble($m,$mine,$threadAttachments[(int)$m['id']]??[],is_admin()); endforeach; if(!$thread): ?><div class="text-center muted small py-4">No messages here yet - say hi below.</div><?php endif; ?>
            </div>
            <?php if ($peerId!==0): ?><form method="post" class="tb-chat-composer mt-2" enctype="multipart/form-data" data-tb-chat-send><?=csrf_field()?><input type="hidden" name="action" value="send_message"><input type="hidden" name="recipient_id" value="<?=$peerId?>"><input type="hidden" name="send_token" value="<?=h(tb_message_send_token())?>"><div class="input-group"><button class="btn btn-outline-secondary tb-chat-attach" type="button" aria-label="Attach a file" title="Attach a file">&#128206;</button><input type="file" class="d-none" name="attachment" aria-label="Attachment file" data-tb-chat-file><textarea class="form-control" id="message-body" name="body" rows="2" placeholder="Write a message..." aria-label="Message" data-tb-chat-body autofocus></textarea><button class="btn btn-primary" type="submit">Send</button></div><div class="small muted mt-1 d-none" data-tb-chat-file-name></div><div class="small muted mt-1">Enter sends &middot; Shift+Enter or Ctrl+Enter adds a new line &middot; max <?=round($config['max_upload_bytes']/1024/1024)?> MB per attachment.</div></form>
            <?php else: ?><div class="small muted mt-2">This account was deleted - the conversation stays readable, but new messages cannot be delivered.</div><?php endif; ?>
        </div></div></div>
    <script>
    (function(){
        var root=document.querySelector('[data-tb-chat]');
        if(!root) return;
        // Pin the chat to the exact viewport space under the page header (1):
        // the composer always sits at the bottom of the first screen, on
        // desktop and on the wrapped mobile header alike.
        var size=function(){
            var h=window.innerHeight-root.getBoundingClientRect().top-14;
            root.style.height=Math.max(h,320)+'px';
        };
        size();
        window.addEventListener('resize',size);
        var scroll=root.querySelector('[data-tb-chat-scroll]');
        if(scroll){
            // Open at the bottom - the newest page of the conversation (2).
            scroll.scrollTop=scroll.scrollHeight;
            var moreUrl=scroll.getAttribute('data-tb-more')||'';
            var hasMore=scroll.getAttribute('data-tb-has-more')==='1';
            var cursor=null,m=moreUrl.match(/before=(\d+)/);
            if(m) cursor=parseInt(m[1],10);
            var busy=false;
            // Infinity scroll (3): near the top, pull the next older page and
            // prepend it while keeping the viewport on the same messages.
            var loadOlder=function(){
                if(busy||!hasMore||cursor===null) return;
                busy=true;
                fetch(moreUrl,{credentials:'same-origin',headers:{'Accept':'application/json'}})
                    .then(function(r){return r.ok?r.json():null;})
                    .then(function(d){
                        if(d&&typeof d.html==='string'&&d.html!==''){
                            var before=scroll.scrollHeight;
                            scroll.insertAdjacentHTML('afterbegin',d.html);
                            scroll.scrollTop=scroll.scrollHeight-before;
                            if(d.hasMore&&d.oldestId){
                                cursor=parseInt(d.oldestId,10);
                                moreUrl=moreUrl.replace(/before=\d+/,'before='+cursor);
                            } else {
                                hasMore=false;
                            }
                        } else {
                            hasMore=false;
                        }
                    })
                    .catch(function(){})
                    .then(function(){busy=false;fill();});
            };
            // A short conversation never overflows, so no scroll-up could
            // ever reach the older pages: keep pulling one page after
            // another until the thread scrolls or history is exhausted.
            var fill=function(){
                if(hasMore&&scroll.scrollHeight<=scroll.clientHeight) loadOlder();
            };
            scroll.addEventListener('scroll',function(){
                if(scroll.scrollTop<40) loadOlder();
            });
            fill();
        }
        var attach=root.querySelector('.tb-chat-attach');
        var file=root.querySelector('[data-tb-chat-file]');
        var fileName=root.querySelector('[data-tb-chat-file-name]');
        if(attach&&file){
            // The paperclip button (6) drives the real file input.
            attach.addEventListener('click',function(){file.click();});
            file.addEventListener('change',function(){
                if(!fileName) return;
                if(file.files&&file.files.length){
                    fileName.textContent='Attached: '+file.files[0].name;
                    fileName.classList.remove('d-none');
                } else {
                    fileName.textContent='';
                    fileName.classList.add('d-none');
                }
            });
        }
        var form=root.querySelector('[data-tb-chat-send]');
        var body=root.querySelector('[data-tb-chat-body]');
        if(form&&body){
            // Enter sends (5); Shift+Enter and Ctrl+Enter fall through and
            // let the textarea add the newline itself.
            body.addEventListener('keydown',function(e){
                if(e.key==='Enter'&&!e.shiftKey&&!e.ctrlKey&&!e.metaKey){
                    e.preventDefault();
                    var hasFile=file&&file.files&&file.files.length>0;
                    if(body.value.trim()===''&&!hasFile) return;
                    if(typeof form.requestSubmit==='function'){form.requestSubmit();}
                    else{form.submit();}
                }
            });
        }
        // Live updates (#114): a poll on the messages_live feed appends the
        // bubbles that arrived for the open conversation, swaps the people
        // rows (unread badges, activity indicators, highlight) for a fresh
        // full-load picture and keeps the page badge in sync - without ever
        // touching the composer draft. Same cadence and hidden-tab rules as
        // the shared list refresh (#51); a bubble only scrolls the thread
        // when the reader already sits at the bottom, so reading history is
        // never yanked away.
        var liveUrl=root.getAttribute('data-tb-chat-live-url')||'';
        if(liveUrl){
            var lastId=parseInt(scroll?scroll.getAttribute('data-tb-after')||'0':'0',10);
            if(!(lastId>=0)) lastId=0;
            var liveBusy=false;
            // #118: the relative sending time of every bubble is refreshed in
            // place from its epoch - the suffix grammar is deterministic, the
            // absolute datetime (timezone + format) stays exactly as rendered.
            var agoSuffix=function(ts,nowSec){
                var s=nowSec-ts;
                if(!(ts>0)||s<0) return null;
                if(s<60) return 'just now';
                var unit=function(n,w){return n+' '+w+(n===1?'':'s')+' ago';};
                var minutes=Math.floor(s/60);
                if(minutes<60) return unit(minutes,'minute');
                var hours=Math.floor(s/3600);
                if(hours<24) return unit(hours,'hour');
                var days=Math.floor(s/86400);
                if(days<7) return unit(days,'day');
                if(days<30) return unit(Math.floor(days/7),'week');
                if(days<365) return unit(Math.floor(days/30),'month');
                return unit(Math.floor(days/365),'year');
            };
            var refreshAgo=function(){
                var nowSec=Math.floor(Date.now()/1000);
                var els=root.querySelectorAll('[data-tb-ago-sfx]');
                for(var i=0;i<els.length;i++){
                    var ts=parseInt(els[i].getAttribute('data-tb-ago-ts'),10);
                    var sfx=agoSuffix(ts,nowSec);
                    // #125: the parentheses are the PHP markup's own - the span
                    // renders inside them - so the refreshed suffix must carry
                    // exactly the bare grammar, never a second pair.
                    if(sfx!==null) els[i].textContent=sfx;
                }
            };
            refreshAgo();
            var poll=function(){
                if(liveBusy||document.hidden) return;
                refreshAgo();
                liveBusy=true;
                fetch(liveUrl+'&after='+lastId,{credentials:'same-origin',headers:{'Accept':'application/json'}})
                    .then(function(r){return r.ok?r.json():null;})
                    .then(function(d){
                        if(!d) return;
                        if(d.html&&scroll){
                            var nearBottom=scroll.scrollHeight-scroll.scrollTop-scroll.clientHeight<60;
                            scroll.insertAdjacentHTML('beforeend',d.html);
                            if(nearBottom) scroll.scrollTop=scroll.scrollHeight;
                        }
                        if(typeof d.lastId==='number'&&d.lastId>lastId) lastId=d.lastId;
                        if(typeof d.newCount==='number'){
                            var badge=document.querySelector('[data-tb-chat-newcount]');
                            if(badge) badge.textContent=d.newCount+' new message'+(d.newCount===1?'':'s');
                        }
                        if(typeof d.peopleHtml==='string'&&d.peopleHtml!==''){
                            var rowsEl=document.querySelector('[data-tb-chat-live-people]');
                            if(rowsEl&&rowsEl.innerHTML!==d.peopleHtml) rowsEl.innerHTML=d.peopleHtml;
                        }
                        if(typeof d.seenUpTo==='number'&&d.seenUpTo>0){
                            // #118: the peer read the thread - my Sent labels
                            // flip to Seen without a reload.
                            var statuses=root.querySelectorAll('[data-tb-msg-status]');
                            for(var i=0;i<statuses.length;i++){
                                var b=statuses[i].closest('[data-tb-msg-id]');
                                if(b&&parseInt(b.getAttribute('data-tb-msg-id'),10)<=d.seenUpTo) statuses[i].textContent='Seen';
                            }
                        }
                    })
                    .catch(function(){})
                    .then(function(){liveBusy=false;});
            };
            setInterval(poll,10000);
            document.addEventListener('visibilitychange',function(){if(!document.hidden)poll();});
        }
    })();
    </script>
    <?php render_footer(); exit;
}

// ---- Notifications (#58, per-item seen #73, page-open clear #79) ----------
// One stream per user: the board drops a notification into it whenever a
// task-history event hits a task the user is assigned to (minus their own
// edits). Opening the stream marks everything seen (#79): the red flag
// disappears until new unseen ones arrive; every row still links through
// ?page=notification_seen&id=... so a single click marks exactly that row
// seen on the way to its task, and the Mark-all-as-seen button clears the
// whole stream in one POST.
if ($page === 'notifications') {
    // The #57-E rebuild runs the notification stream through the shared CRUD
    // engine (src/crud.php): the rows render exactly what the hand-built
    // stream rendered, and the page gains the search/pagination mechanics of
    // the other lists - the hand-rolled LIMIT 200 cap becomes real pagination.
    // The newest-first order stays the fixed default of the engine's sort
    // allowlist (a crafted ?sort= falls back to it), and the generated links
    // (search form, clear, pagination) carry the canonical page name, so
    // bookmarks survive.
    $listPage = 'notifications';
    $me=(int)user()['id'];
    $newCount=tb_unseen_notification_count($pdo,$me);
    // #100: the switch that shows or hides the red navbar badge for this
    // user. Rendered next to the stream counter; the POST flips the
    // per-user preference and lands back on this page.
    $flagOn=tb_notification_flag_enabled($pdo,$me);
    $nConds=['n.user_id=?']; $nParams=[$me];
    $nq = trim((string)($_GET['q'] ?? ''));
    if ($nq !== '') {
        // The same |, %, _ escaping the task search uses, so a search for a
        // literal % or _ cannot turn into a wildcard scan.
        $term = str_replace(['|','%','_'],['||','|%','|_'],$nq);
        $nConds[] = "(t.title LIKE ? ESCAPE '|' OR n.event LIKE ? ESCAPE '|')";
        $nParams[] = '%'.$term.'%'; $nParams[] = '%'.$term.'%';
    }
    $notifList=tb_crud_list([
        'pdo'=>$pdo,
        'table'=>'notifications n JOIN tasks t ON t.id=n.task_id',
        'select'=>'n.id,n.task_id,n.event,n.created_at,n.is_seen,t.title task_title',
        'where'=>$nConds,
        'params'=>$nParams,
        'sortColumns'=>['newest'=>'n.id {dir}'],
        'defaultSort'=>'newest',
        'defaultDir'=>'desc',
    ]);
    $items=$notifList['rows'];
    $notifUrl=tb_crud_url_builder($listPage,['q']);
    // Opening the page marks everything seen (#79 point 2): the red navbar
    // flag disappears on this very load and stays gone until new unseen
    // notifications arrive. Reading one by one still works - the per-item
    // click-through (#73) marks its row seen on the way to the task, and the
    // Mark-all-as-seen button is the bulk shortcut in one POST.
    $pdo->prepare('UPDATE notifications SET is_seen=1 WHERE user_id=? AND is_seen=0')->execute([$me]);
    render_header('Notifications',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Notifications</h1><div class="muted">Everything that happened on the tasks you are assigned to. Opening this page marks everything seen - the red flag disappears until new ones arrive; clicking a single one opens the task it came from, and the button clears them all in one click.</div></div><div class="d-flex gap-2 align-items-center"><form method="post" class="d-inline-flex align-items-center gap-1" title="Shows or hides the red notification counter in the top menu"><?=csrf_field()?><input type="hidden" name="action" value="toggle_notification_flag"><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" id="notification-flag-toggle" aria-label="Red flag in the top menu"<?=$flagOn?' checked':''?> onchange="this.form.submit()"><label class="form-check-label small muted" for="notification-flag-toggle">Red flag in the top menu</label></div><noscript><button class="btn btn-sm btn-outline-secondary">Apply</button></noscript></form><span class="badge text-bg-danger fs-6"><?=$newCount?> new notification<?=$newCount===1?'':'s'?></span><?php if($newCount): ?><form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="action" value="mark_all_seen"><button class="btn btn-outline-primary btn-sm" title="Mark every notification as seen">Mark all as seen</button></form><?php endif; ?></div></div>
    <form method="get" class="d-flex gap-2 mb-3"><input type="hidden" name="page" value="<?=$listPage?>"><input class="form-control" type="search" name="q" value="<?=h($nq)?>" placeholder="Search task and event..." aria-label="Search notifications"><button class="btn btn-outline-secondary">Search</button><?php if($nq!==''): ?><a class="btn btn-link" href="<?=h($notifUrl())?>&amp;clear_search=1">Clear</a><?php endif; ?></form>
    <div class="card p-4"><h2 class="h5">Stream</h2><?php foreach($items as $n): ?><div class="border-bottom py-2<?=$n['is_seen']?'':' fw-semibold'?>"><div><a class="fw-semibold text-decoration-none" href="?page=notification_seen&id=<?=$n['id']?>">#<?=$n['task_id']?> · <?=h($n['task_title'])?></a> <strong><?=h($n['event'])?></strong> <span class="small muted"><?=h(tb_ago($n['created_at']))?></span><?php if(!$n['is_seen']): ?> <span class="badge text-bg-danger">new</span><?php endif; ?></div></div><?php endforeach; if(!$items): ?><div class="small muted"><?=$nq!==''?'No notifications match the search.':'No notifications yet. Assign yourself to a task and follow its history.'?></div><?php endif; ?></div>
    <?=tb_crud_pagination(['ariaLabel'=>'Notification list pages','page'=>$notifList['page'],'pages'=>$notifList['pages'],'url'=>$notifUrl])?>
    <?php render_footer(); exit;
}

// ---- Notification seen (#73) ---------------------------------------------------------------
// The click-through of a notification: ?page=notification_seen&id=N marks
// exactly that one notification seen for the signed-in user and redirects to
// the task it came from, so the badge decreases per opened item instead of
// clearing in bulk when the stream page opens. The id is scoped to its owner -
// a forged, foreign or already-cascaded-away id simply lands back on the
// stream without touching any row.
if ($page==='notification_seen') {
    $me=(int)user()['id'];
    $id=(int)($_GET['id'] ?? 0);
    $q=$pdo->prepare('SELECT task_id FROM notifications WHERE id=? AND user_id=?');
    $q->execute([$id,$me]);
    $row=$q->fetch();
    if ($row) {
        $pdo->prepare('UPDATE notifications SET is_seen=1 WHERE id=? AND user_id=?')->execute([$id,$me]);
        redirect('?page=task&id='.(int)$row['task_id']);
    }
    redirect('?page=notifications');
}

// ---- Change details, full audit (#54) ---------------------------------------
// One history entry rendered as a full audit record: what changed, the
// from/to values for field changes, who made it and when. Every history row
// on the task page links here.
if ($page==='task_history') {
    $id=(int)($_GET['id'] ?? 0);
    $s=$pdo->prepare('SELECT h.*, u.display_name actor, u.last_seen_at actor_last_seen, u.logged_out actor_logged_out, t.title task_title FROM task_history h LEFT JOIN users u ON u.id=h.user_id JOIN tasks t ON t.id=h.task_id WHERE h.id=?'); $s->execute([$id]); $entry=$s->fetch();
    if(!$entry){ http_response_code(404); exit('History entry not found.'); }
    render_header('Change details',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Change details</h1><div class="muted">On task <a class="fw-semibold text-decoration-none" href="?page=task&id=<?=$entry['task_id']?>">#<?=$entry['task_id']?> · <?=h($entry['task_title'])?></a></div></div><a class="btn btn-outline-secondary" href="?page=task&id=<?=$entry['task_id']?>">Back to the task</a></div>
    <div class="card p-4 mb-4"><h2 class="h5"><?=h($entry['event'])?></h2><div class="small muted mb-2">by <?=tb_user_with_activity(['display_name'=>$entry['actor'],'last_seen_at'=>$entry['actor_last_seen']??null])?> · <?=h(tb_ago($entry['created_at']))?></div><?php if($entry['details']): ?><div class="prewrap"><?=h($entry['details'])?></div><?php endif; ?><?php if($entry['field']): ?><table class="table table-bordered align-middle mt-3 mb-0"><tbody><tr><th class="w-25" scope="row">Field</th><td class="fw-semibold"><?=h((string)$entry['field'])?></td></tr><tr><th scope="row">From</th><td><div class="prewrap"><?=h((string)($entry['old_value'] ?? '—'))?></div></td></tr><tr><th scope="row">To</th><td><div class="prewrap"><?=h((string)($entry['new_value'] ?? '—'))?></div></td></tr></tbody></table><?php endif; ?><?php if($entry['event']==='Comment added'): ?><a class="small text-decoration-none" href="?page=task&id=<?=$entry['task_id']?>#comments">Open the comment on the task page</a><?php endif; ?><?php if($entry['event']==='Task updated' && !$entry['field']):
    // #54 v2 feedback: the generic save entry lists the changed fields of
    // exactly this save in one From/To table - the field rows of the save sit
    // directly after it in the write order, up to the next event-level row.
    $g=$pdo->prepare('SELECT * FROM task_history WHERE task_id=? AND field IS NOT NULL AND id>? AND id<(SELECT COALESCE(MIN(id),1000000000) FROM task_history WHERE task_id=? AND field IS NULL AND id>?) ORDER BY id');
    $g->execute([(int)$entry['task_id'],(int)$entry['id'],(int)$entry['task_id'],(int)$entry['id']]);
    $groupChanges=$g->fetchAll();
    if($groupChanges): ?>
<table class="table table-bordered align-middle mt-3 mb-0"><thead><tr><th scope="col">Changed</th><th scope="col">From</th><th scope="col">To</th></tr></thead><tbody><?php foreach($groupChanges as $c): ?><tr><th scope="row" class="fw-semibold"><a class="text-decoration-none" href="?page=task_history&id=<?=$c['id']?>"><?=h((string)$c['details'])?></a></th><td><div class="prewrap"><?=h((string)($c['old_value'] ?? '—'))?></div></td><td><div class="prewrap"><?=h((string)($c['new_value'] ?? '—'))?></div></td></tr><?php endforeach; ?></tbody></table>
    <?php endif; endif; ?></div>
    <?php render_footer(); exit;
}

// ---- End task archive ---------------------------------------------------------------------

// ---- Administration hub (#42) --------------------------------------------------------------
// The old single Administration page held the users table, the status editor,
// the maintenance button and the activity periods link all at once. #42 splits
// it: Users (?page=users) and Task statuses (?page=statuses) are pages of
// their own now, and this page stays as the compact hub that links to every
// administration area and keeps the uploads maintenance action.
// The separated pages are admin-only and answer 403 to everyone else, like the
// other dedicated admin pages (?page=activity_settings, ?page=updates). A plain
// GET of ?page=admin by a non-admin keeps the pre-#42 behavior: the request
// falls through to the task list, which the access-control suite pins.
if (in_array($page, ['users', 'statuses'], true) && !is_admin()) {
    http_response_code(403);
    exit('Forbidden.');
}
if ($page==='admin' && is_admin()) {
    render_header('Administration',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Administration</h1><div class="muted">User accounts, task statuses and board maintenance</div></div></div>
    <div class="row g-4">
        <div class="col-md-6"><div class="card p-4 h-100"><h2 class="h5">Users</h2><p class="small muted mb-3">Create user accounts, edit display names, roles and passwords, or delete accounts. Their tasks, comments and attachments are always kept.</p><a class="btn btn-outline-primary" href="?page=users">Manage users</a></div></div>
        <div class="col-md-6"><div class="card p-4 h-100"><h2 class="h5">Task statuses</h2><p class="small muted mb-3">Rename statuses, change their display order, mark them as completion statuses, choose their colour or delete the ones no task uses.</p><a class="btn btn-outline-primary" href="?page=statuses">Manage task statuses</a></div></div>
        <div class="col-md-6"><div class="card p-4 h-100"><h2 class="h5">Activity status periods</h2><p class="small muted mb-3">The coloured dot next to every user name is driven by time periods. Configure which period shows which colour or character - when green turns yellow, when red turns to the empty grey and so on.</p><a class="btn btn-outline-primary" href="?page=activity_settings">Configure periods</a></div></div>
        <div class="col-md-6"><div class="card p-4 h-100"><h2 class="h5">Software updates</h2><p class="small muted mb-3">Check the installed version against the latest release and install updates with one click.</p><a class="btn btn-outline-primary" href="?page=updates">Open software updates</a></div></div>
    </div>
    <div class="card p-4 mt-4"><h2 class="h5">Maintenance</h2><p class="small muted mb-2">Scan the uploads folder and hard-delete the files that no attachment row references anymore — leftovers from tasks deleted before #38 v2, failed uploads or stray files. Files that existing attachments still use are never touched.</p><form method="post" onsubmit="return confirm('Delete every orphaned file in the uploads folder now?')"><?=csrf_field()?><input type="hidden" name="action" value="cleanup_uploads"><button class="btn btn-outline-danger">Clean up orphaned attachment files</button></form></div>
    <?php render_footer(); exit;
}

// ---- Users administration (#42, on the CRUD engine since #57) ---------------
// The admin user list, rebuilt on the shared CRUD list engine (src/crud.php):
// the count/page/fetch query, the URL builder, the sortable table skeleton,
// the pagination window and the realtime region come from the abstraction;
// only the user-specific parts stay here (the search/role conditions, the
// activity clock, the create form and the row cells). The admin's step 4
// parity proof is tests/E2E/CrudAbstractionTest.php: it pinned this page
// feature by feature against the old ?page=users contract. The generated
// links carry the canonical page name, so bookmarks and saved links survive.
if ($page === 'users' && is_admin()) {
    $usersPage = 'users';
    // The exact search and role filter of the v1 page: the search escapes
    // |, % and _ so a literal % cannot become a wildcard, and the role
    // filter is an allowlisted bound-parameter IN list.
    $conds=[]; $params=[];
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $term = str_replace(['|','%','_'],['||','|%','|_'],$q);
        $conds[] = "(u.username LIKE ? ESCAPE '|' OR u.display_name LIKE ? ESCAPE '|')";
        $params[] = '%'.$term.'%'; $params[] = '%'.$term.'%';
    }
    $roleFilter=array_values(array_intersect(filter_values('role'),['admin','user']));
    if ($roleFilter) {
        $conds[] = 'u.role IN ('.tb_in_placeholders(count($roleFilter)).')';
        array_push($params, ...$roleFilter);
    }
    $list=tb_crud_list([
        'pdo'=>$pdo,
        'table'=>'users u',
        'select'=>'u.id,u.username,u.display_name,u.role,u.created_at,u.last_seen_at,u.logged_out',
        'where'=>$conds,
        'params'=>$params,
        'sortColumns'=>[
            'name'=>'u.display_name {dir}, u.id {dir}',
            'username'=>'u.username {dir}, u.id {dir}',
            'role'=>'u.role {dir}, u.display_name {dir}',
            'created'=>'u.created_at {dir}, u.id {dir}',
            'activity'=>'u.last_seen_at {dir}, u.id {dir}',
        ],
        'defaultSort'=>'name',
    ]);
    $users=$list['rows'];
    $usersUrl=tb_crud_url_builder($usersPage,['q','role']);
    $userColumns=[
        ['label'=>'Display name','sortKey'=>'name','sort'=>$list['sort'],'url'=>$usersUrl,'cell'=>fn(array $u): string => '<td class="fw-semibold">'.tb_user_with_activity($u).'</td>'],
        ['label'=>'Username','sortKey'=>'username','sort'=>$list['sort'],'url'=>$usersUrl,'cell'=>fn(array $u): string => '<td>'.h($u['username']).'</td>'],
        ['label'=>'Role','sortKey'=>'role','sort'=>$list['sort'],'url'=>$usersUrl,'cell'=>fn(array $u): string => '<td><span class="badge text-bg-secondary">'.h($u['role']).'</span></td>'],
        // #108/#110: the stamps are epoch seconds and every render goes through
        // tb_ago() (the tb_dt() form with the relative-time suffix).
        ['label'=>'Created','sortKey'=>'created','sort'=>$list['sort'],'url'=>$usersUrl,'cell'=>fn(array $u): string => '<td class="small muted">'.h(tb_ago($u['created_at'])).'</td>'],
        ['label'=>'Last active','sortKey'=>'activity','sort'=>$list['sort'],'url'=>$usersUrl,'cell'=>fn(array $u): string => '<td class="small muted">'.h($u['last_seen_at']===null||((int)$u['last_seen_at'])===0?'never':'active: '.tb_last_seen_label($u['last_seen_at'])).'</td>'],
        ['label'=>'Actions','cell'=>function(array $u): string {
            // The message shortcut (#98): jumps to the messenger with this
            // user's conversation already selected (?u=<id>). The own row
            // offers none - the messenger has no self-conversation.
            $message = (int)$u['id'] === (int)user()['id'] ? '' : ' <a class="btn btn-sm btn-outline-primary" href="?page=messages&u='.$u['id'].'">Message</a>';
            return '<td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?page=user&id='.$u['id'].'">Edit</a>'.$message.' <form method="post" class="d-inline" onsubmit="return confirm(\'Delete this user permanently? Their tasks, comments and attachments will be kept.\')">'.csrf_field().'<input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="'.$u['id'].'"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td>';
        }],
    ];
    render_header('Users',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Users</h1><div class="muted"><?=$list['total']?> user<?=$list['total']===1?'':'s'?> · accounts, roles and last activity</div></div><a class="btn btn-outline-secondary" href="?page=admin">Back to administration</a></div>
    <div class="row g-4"><div class="col-lg-5"><div class="card p-4"><h2 class="h5">Create user</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="add_user"><div class="mb-2"><input class="form-control" name="username" placeholder="Username" required></div><div class="mb-2"><input class="form-control" name="display_name" placeholder="Display name" required></div><div class="mb-2"><input class="form-control" type="password" name="password" placeholder="Password (10+ characters)" minlength="10" required></div><div class="mb-3"><select class="form-select" name="role"><option value="user">User</option><option value="admin">Administrator</option></select></div><button class="btn btn-primary">Create user</button></form></div></div>
    <div class="col-lg-7"><div id="tb-list-region" data-tb-refresh="10">
    <form method="get" class="row g-2 align-items-end mb-3"><input type="hidden" name="page" value="<?=$usersPage?>"><div class="col-md-5"><input class="form-control" type="search" name="q" value="<?=h($q)?>" placeholder="Search username or display name" aria-label="Search users"></div><div class="col-md-3"><label class="form-label small muted mb-1" for="filter-user-role">Roles</label><select class="form-select" id="filter-user-role" name="role[]" multiple size="2"><option value="admin" <?=in_array('admin',$roleFilter,true)?'selected':''?>>Administrator</option><option value="user" <?=in_array('user',$roleFilter,true)?'selected':''?>>User</option></select></div><div class="col-md-4 d-grid"><button class="btn btn-outline-primary">Filter</button></div><div class="col-md-auto d-grid"><a class="btn btn-outline-danger<?=tb_list_view_has_selection($usersPage)?'':' d-none'?>" href="?page=<?=$usersPage?>&amp;clear_search=1" title="Drop the stored selection and show the plain list (#113)">Clear search</a></div><div class="col-12"><div class="form-text mb-0">Hold Ctrl/Cmd to pick several roles; an empty dropdown filters nothing. The search matches username and display name.</div></div></form>
    <div class="card p-4"><h2 class="h5">All users</h2><?=tb_crud_table(['columns'=>$userColumns,'rows'=>$users,'emptyText'=>$q!==''||$roleFilter?'No users match the filters.':'No users yet.','emptyColspan'=>6])?></div>
    <?php echo tb_crud_pagination(['ariaLabel'=>'User list pages','page'=>$list['page'],'pages'=>$list['pages'],'url'=>$usersUrl]), "\n"; ?>
    </div></div></div>
    <?php tb_render_list_refresh_script(); ?>
    <?php render_footer(); exit;
}

// ---- Task statuses administration (#42) ----------------------------------------------------
// The status editor half of the old Administration page, on a page of its own
// and with the new per-status colour picker: every status badge on the board
// (task list, task/project/archive pages) renders the chosen fill with an
// automatically readable text colour; the default look stays the default.
if ($page==='statuses' && is_admin()) {
    $statuses=all_statuses($pdo);
    render_header('Task statuses',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Task statuses</h1><div class="muted">Labels, display order, completion flag and colour</div></div><a class="btn btn-outline-secondary" href="?page=admin">Back to administration</a></div>
    <div class="card p-4"><p class="small muted">Rename statuses, change their display order, mark them as completion statuses, pick their colour or delete the ones no task uses. The colour renders on every status badge of the board; tick &quot;Default colour&quot; to go back to the built-in look.</p><ul class="list-group mb-3"><?php foreach($statuses as $st): ?><li class="list-group-item"><form method="post" class="row g-2 align-items-center"><?=csrf_field()?><input type="hidden" name="action" value="edit_status"><input type="hidden" name="id" value="<?=$st['id']?>"><div class="col-12 col-md-2"><?=tb_status_badge((string)$st['name'], $st['color'] ?? null)?></div><div class="col-12 col-md-4"><label class="visually-hidden" for="status-name-<?=$st['id']?>">Status name</label><input class="form-control form-control-sm" name="name" id="status-name-<?=$st['id']?>" value="<?=h($st['name'])?>" maxlength="40" required></div><div class="col-4 col-md-1"><label class="visually-hidden" for="status-sort-<?=$st['id']?>">Sort order</label><input class="form-control form-control-sm" type="number" name="sort_order" id="status-sort-<?=$st['id']?>" value="<?=$st['sort_order']?>" title="Display order, lower numbers first"></div><div class="col-auto"><label class="visually-hidden" for="status-color-<?=$st['id']?>">Colour of status <?=h($st['name'])?></label><input type="color" class="form-control form-control-color form-control-sm" name="color" id="status-color-<?=$st['id']?>" value="<?=h(strtolower((string)($st['color'] ?? '#6c757d')))?>" title="Status colour (a colour picker; tick &quot;Default colour&quot; for the built-in look)"></div><div class="col-auto form-check mb-0 ms-1"><input class="form-check-input" type="checkbox" name="color_default" id="status-color-default-<?=$st['id']?>" <?=($st['color'] ?? null)===null?'checked':''?>><label class="form-check-label small" for="status-color-default-<?=$st['id']?>">Default colour</label></div><div class="col-8 col-md-2 form-check ms-md-1 mb-0"><input class="form-check-input" type="checkbox" name="is_done" id="status-done-<?=$st['id']?>" <?=$st['is_done']?'checked':''?>><label class="form-check-label small" for="status-done-<?=$st['id']?>">Completed</label><?php if($st['is_done']): ?><span class="badge text-bg-success ms-1">Completion status</span><?php endif; ?></div><div class="col-12 col-md-2 d-flex gap-1 justify-content-md-end"><button class="btn btn-sm btn-outline-primary">Save</button><button type="submit" class="btn btn-sm btn-outline-danger" form="delete-status-<?=$st['id']?>">Delete</button></div></form><form method="post" id="delete-status-<?=$st['id']?>" class="d-none" onsubmit="return confirm('Delete this status?');"><?=csrf_field()?><input type="hidden" name="action" value="delete_status"><input type="hidden" name="id" value="<?=$st['id']?>"></form></li><?php endforeach; ?></ul><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="add_status"><div class="row g-2 align-items-center"><div class="col-12 col-md-5"><input class="form-control" name="name" placeholder="New status label" required></div><div class="col-auto"><label class="visually-hidden" for="new-status-color">Colour of the new status</label><input type="color" class="form-control form-control-color form-control-sm" name="color" id="new-status-color" value="#6c757d" title="Status colour"></div><div class="col-auto form-check mb-0 ms-1"><input class="form-check-input" type="checkbox" name="color_default" id="new-status-color-default"><label class="form-check-label small" for="new-status-color-default">Default colour</label></div><div class="col-12 col-md-3 form-check mb-0 ms-md-1"><input class="form-check-input" type="checkbox" name="is_done" id="done"><label class="form-check-label" for="done">Treat as completed</label></div><div class="col-12 col-md-auto"><button class="btn btn-primary">Add status</button></div></div></form><p class="small muted mt-3">Statuses are shown in display order. A status that tasks still use and the last remaining status cannot be deleted.</p></div>
    <?php render_footer(); exit;
}

// ---- Activity status periods (#34) ---------------------------------------------------------
// The admin-facing settings page for the dot next to user names: which age
// period renders as which colour or character. One fixed-width form (five
// rows, unused rows dropped on save) keeps the page JavaScript-free, like
// the rest of the admin pages.
if ($page==='activity_settings') {
    if (!is_admin()) { http_response_code(403); exit('Forbidden.'); }
    $levels = tb_activity_levels_current();
    $loggedOut = tb_logged_out_indicator_current();
    $styleOptions = [
        'color:green' => 'Dot - green',
        'color:yellow' => 'Dot - yellow',
        'color:red' => 'Dot - red',
        'color:grey' => 'Dot - grey',
        'character' => 'Custom character',
    ];
    // The logged-out indicator shares the period styles plus the disable
    // choice (#106): the built-in default is the grey empty circle, and the
    // empty style switches the feature off (signed-out users keep showing
    // their plain activity dot).
    $loggedOutStyleValue = $loggedOut === null ? '' : (($loggedOut['character'] ?? null) !== null ? 'character' : 'color:' . ($loggedOut['color'] ?? 'grey'));
    render_header('Activity status periods',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Activity status periods</h1><div class="muted">Which time period next to user names renders as which colour or character</div></div><a class="btn btn-outline-secondary" href="?page=admin">Back to administration</a></div>
    <div class="card p-4 mb-4"><h2 class="h5">Current legend</h2><p class="small muted mb-2">These are the periods in effect right now, freshest on the left, longest gone on the right:</p><p class="mb-0"><?php foreach($levels as $i=>$lvl): ?><span class="me-3 text-nowrap"><?=tb_activity_dot_for_bucket((int)$i, $levels)?> <span class="small"><?=h(tb_activity_level_title((int)$i, $levels))?></span></span><?php endforeach; ?></p></div>
    <div class="card p-4"><h2 class="h5">Periods</h2><p class="small muted">List the periods in order: a user falls into the first period whose upper bound still covers how long ago they were last seen. Every period shows a coloured dot or a short character, solid or hollow (#112): untick <em>Filled</em> to render the hollow circle &quot;&#9675;&quot; in the period&rsquo;s colour; the ticked default renders the solid dot &quot;&#9679;&quot;. Leave the minute bound of the last period empty to mean &quot;forever&quot;, and clear a row completely to remove that period.</p>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_activity_levels">
    <div class="table-responsive"><table class="table align-middle"><thead><tr><th style="width:2rem">#</th><th style="width:14rem">Up to (minutes ago)</th><th style="width:16rem">Shows</th><th style="width:6rem">Filled</th><th>Character (for &quot;Custom character&quot;)</th></tr></thead><tbody><?php for($i=0;$i<5;$i++): $lvl=$levels[$i]??null; $minutesValue=($lvl!==null&&($lvl['until_minutes']??null)!==null)?(string)(int)$lvl['until_minutes']:''; $styleValue=($lvl!==null&&($lvl['character']??null)!==null)?'character':(($lvl!==null&&($lvl['color']??null)!==null)?'color:'.$lvl['color']:''); $filledChecked=$lvl!==null&&($lvl['color']??null)!==null&&tb_activity_style_filled($lvl); ?><tr><td><?=$i+1?></td><td><label class="visually-hidden" for="lvl-minutes-<?=$i?>">Upper bound of period <?=$i+1?> in minutes</label><input class="form-control" type="number" min="1" max="525600" id="lvl-minutes-<?=$i?>" name="levels[minutes][]" value="<?=$minutesValue?>" placeholder="<?=$i===4?'':'1-525600 or empty on the last period'?>"></td><td><label class="visually-hidden" for="lvl-style-<?=$i?>">Style of period <?=$i+1?></label><select class="form-select" id="lvl-style-<?=$i?>" name="levels[style][]"><option value="">- unused -</option><?php foreach($styleOptions as $sv=>$sl): ?><option value="<?=h($sv)?>" <?=$styleValue===$sv?'selected':''?>><?=h($sl)?></option><?php endforeach; ?></select></td><td><div class="form-check"><input type="hidden" name="levels[filled][<?=$i?>]" value="0"><input class="form-check-input" type="checkbox" value="1" id="lvl-filled-<?=$i?>" name="levels[filled][<?=$i?>]" <?=$filledChecked?'checked':''?>><label class="form-check-label small" for="lvl-filled-<?=$i?>">Filled</label></div></td><td><label class="visually-hidden" for="lvl-character-<?=$i?>">Character of period <?=$i+1?></label><input class="form-control" id="lvl-character-<?=$i?>" name="levels[character][]" maxlength="3" value="<?=h($lvl['character']??'')?>"></td></tr><?php endfor; ?></tbody></table></div>
    <button class="btn btn-primary">Save periods</button>
    </form>
    <form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="action" value="reset_activity_levels"><button class="btn btn-outline-danger" onclick="return confirm('Reset the periods to the built-in defaults?')">Reset to defaults</button></form>
    <p class="small muted mt-3">Saved periods take effect immediately for every user of the board. Resetting removes the stored list and restores the built-in 15-minute / 60-minute traffic light.</p></div>
    <div class="card p-4 mb-4"><h2 class="h5">Logged-out indicator</h2><p class="small muted mb-2">Users who signed out through the board&rsquo;s own sign-out button show this instead of their activity dot - never both - until they sign back in. A session that quietly expires does not count as a sign-out, so an idle user keeps ageing through the periods above.</p>
    <p class="mb-3"><span class="me-2">Rendered now for a signed-out user:</span><?=$loggedOut === null ? '<span class="small muted">nothing - the feature is disabled</span>' : tb_logged_out_dot($loggedOut)?></p>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_logged_out_indicator">
    <div class="row align-items-end"><div class="col-md-3 mb-2"><label class="form-label" for="lo-style">Shows</label><select class="form-select" id="lo-style" name="style"><?php foreach($styleOptions as $sv=>$sl): ?><option value="<?=h($sv)?>" <?=$loggedOutStyleValue===$sv?'selected':''?>><?=h($sl)?></option><?php endforeach; ?><option value="" <?=$loggedOut===null?'selected':''?>>- disabled (always the activity dot) -</option></select></div><div class="col-md-3 mb-2"><div class="form-check"><input type="hidden" name="filled" value="0"><input class="form-check-input" type="checkbox" value="1" id="lo-filled" name="filled" <?=$loggedOut!==null&&($loggedOut['color']??null)!==null&&tb_activity_style_filled($loggedOut)?'checked':''?>><label class="form-check-label" for="lo-filled">Filled</label></div><div class="form-text">Solid dot &#9679; or hollow circle &#9675; (#112)</div></div><div class="col-md-3 mb-2"><label class="form-label" for="lo-character">Character (for &quot;Custom character&quot;)</label><input class="form-control" id="lo-character" name="character" maxlength="3" value="<?=h($loggedOut['character']??'')?>"></div><div class="col-md-3 mb-2"><button class="btn btn-primary">Save logged-out indicator</button></div></div>
    <p class="small muted mb-0 mt-2">Every colour renders solid (&#9679;) by default; untick <em>Filled</em> for the hollow circle &#9675; in that colour - the grey choice keeps its hollow circle unless you tick <em>Filled</em>, which is the grey solid dot that was impossible before #112. Any 1-3 character glyph works too - pick &quot;Custom character&quot; and type it. Saving without a colour or character (empty custom field) is rejected; the built-in default when nothing is stored is the grey hollow circle.</p>
    </form></div>
    <?php render_footer(); exit;
}

if ($page==='user' && is_admin()) {
    $id=(int)($_GET['id']??0);
    $s=$pdo->prepare('SELECT id,username,display_name,role,created_at FROM users WHERE id=?'); $s->execute([$id]); $u=$s->fetch();
    if(!$u){ http_response_code(404); exit('User not found.'); }
    render_header('Edit user',$config); ?>
    <div class="row justify-content-center"><div class="col-md-6"><div class="card p-4"><h2 class="h4">Edit user</h2><p class="muted small mb-3">Account created <?=h(tb_ago($u['created_at']))?>.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="edit_user"><input type="hidden" name="id" value="<?=$u['id']?>"><div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" maxlength="80" value="<?=h($u['username'])?>" required></div><div class="mb-3"><label class="form-label">Display name</label><input class="form-control" name="display_name" maxlength="120" value="<?=h($u['display_name'])?>" required></div><div class="mb-3"><label class="form-label">Role</label><select class="form-select" name="role"><option value="user" <?=$u['role']==='user'?'selected':''?>>User</option><option value="admin" <?=$u['role']==='admin'?'selected':''?>>Administrator</option></select><div class="form-text">At least one administrator must remain on the board.</div></div><div class="mb-3"><label class="form-label">New password (leave empty to keep the current one)</label><input class="form-control" type="password" name="password" autocomplete="new-password"><div class="form-text">Minimum 10 characters.</div></div><button class="btn btn-primary">Save user</button> <a class="btn btn-outline-secondary" href="?page=users">Cancel</a></form></div></div></div>
    <?php render_footer(); exit;
}

if ($page==='edit' || $page==='new') {
    $id=(int)($_GET['id']??0); $task=['id'=>0,'title'=>'','description'=>'','status_id'=>0,'complexity'=>1,'priority'=>1,'parent_id'=>null,'schedule_period'=>null,'schedule_status_id'=>null,'schedule_assignees'=>null,'project_id'=>null,'archived_at'=>null]; $selected=[];
    if($id){$task=find_task($pdo,$id) ?: $task;$s=$pdo->prepare('SELECT user_id FROM task_assignees WHERE task_id=?');$s->execute([$id]);$selected=array_map('intval',array_column($s->fetchAll(),'user_id'));}
    // Clone (#46): ?page=new&clone=N opens the create form prefilled from task
    // N. Everything the form saves rides along (title, description, status,
    // complexity, priority, parent, project, schedule targets, assignees);
    // comments, attachments, history and relationships stay with the original
    // - the clone is a brand-new task created by save_task. The source may be
    // archived: the copy is a fresh, non-archived task.
    $cloneSourceId=(int)($_GET['clone']??0);
    if ($page==='new' && $cloneSourceId>0) {
        $cloneSource=find_task($pdo,$cloneSourceId);
        if(!$cloneSource){http_response_code(404);exit('Task not found.');}
        $task=$cloneSource;unset($task['id'],$task['archived_at']);
        $s=$pdo->prepare('SELECT user_id FROM task_assignees WHERE task_id=?');$s->execute([$cloneSourceId]);$selected=array_map('intval',array_column($s->fetchAll(),'user_id'));
    }
    // Scheduler targets for the edit form (#31): the stored status id (0 =
    // the empty option = the TODO default) and the stored assignee id list.
    $scheduleStatusStored=(int)($task['schedule_status_id']??0);
    // #35: the scheduler area starts expanded when the task already has a schedule
    $schedulerActive=(bool)($task['schedule_period']??false);
    $scheduleSelectedIds=schedule_target_assignees($task['schedule_assignees']??null);
    // Archived tasks (#12) must be restored before they can be edited again.
    if(!empty($task['id']) && task_is_archived($task)) {
        render_header('Edit task',$config,$error); ?>
        <div class="card p-4"><h2 class="h4">This task is archived</h2><p class="muted mb-3">Archived tasks are read-only. Restore task #<?=$task['id']?> from the archive first if you want to edit it.</p><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="?page=task&id=<?=$task['id']?>">View the task</a><a class="btn btn-outline-primary" href="?page=archive">Open the archive</a><form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="action" value="restore_task"><input type="hidden" name="id" value="<?=$task['id']?>"><button class="btn btn-primary">Restore now</button></form></div></div>
        <?php render_footer(); exit;
    }
    $users=user_options($pdo);$statuses=all_statuses($pdo);$tasks=$pdo->prepare('SELECT id,title FROM tasks WHERE id<>? AND archived_at IS NULL ORDER BY id DESC LIMIT 200');$tasks->execute([$id]);$projects=$pdo->query('SELECT id,name FROM projects ORDER BY name')->fetchAll();$tpls=$id?[]:$pdo->query("SELECT id,name,description FROM templates WHERE type='task' ORDER BY name")->fetchAll();
    render_header($id?'Edit task':'New task',$config,$error); ?>
    <div class="card p-4"><h2 class="h4"><?= $id?'Edit task':'Create task' ?></h2><form method="post" id="task-form"<?= $id?'':' onsubmit="return tb_confirm_no_project();"' ?>><?=csrf_field()?><input type="hidden" name="action" value="save_task"><input type="hidden" name="id" value="<?=$id?>"><?php if(!$id && $tpls): ?><div class="mb-3"><label class="form-label">Template</label><select class="form-select" id="task-template"><option value="">No template</option><?php foreach($tpls as $tp): ?><option value="<?=$tp['id']?>" data-description="<?=h($tp['description'])?>"><?=h($tp['name'])?></option><?php endforeach; ?></select><div class="form-text">Choosing a template fills the description below with the template text.</div></div><?php endif; ?><div class="mb-3"><label class="form-label">Title</label><input class="form-control" name="title" maxlength="200" value="<?=h($task['title'])?>" required></div><div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="5"><?=h($task['description'])?></textarea></div><div class="row"><div class="col-md-3 mb-3"><label class="form-label">Status</label><select class="form-select" name="status_id" required><?php foreach($statuses as $st): ?><option value="<?=$st['id']?>" <?=$task['status_id']==$st['id']?'selected':''?>><?=h($st['name'])?></option><?php endforeach; ?></select></div><div class="col-md-3 mb-3"><label class="form-label" for="task-complexity">Complexity</label><input class="form-control" type="number" name="complexity" id="task-complexity" min="1" max="1000" step="1" value="<?=h((string)max(1,min(1000,(int)($task['complexity'] ?? 1))))?>"><div class="form-text">How big the task is, 1 is the default. Sets the block width on the waterflow chart (#44).</div></div><div class="col-md-3 mb-3"><label class="form-label" for="task-priority">Priority</label><input class="form-control" type="number" name="priority" id="task-priority" min="1" max="1000" step="1" value="<?=h((string)max(1,min(1000,(int)($task['priority'] ?? 1))))?>"><div class="form-text">Ordering weight of the task, 1 is the default. Sorts the Priority column on the task list (#45).</div></div><div class="col-md-3 mb-3"><label class="form-label">Parent task</label><select class="form-select" name="parent_id"><option value="">None</option><?php foreach($tasks->fetchAll() as $t): ?><option value="<?=$t['id']?>" <?=$task['parent_id']==$t['id']?'selected':''?>>#<?=$t['id']?> - <?=h($t['title'])?></option><?php endforeach; ?></select></div><div class="col-md-3 mb-3"><label class="form-label" for="task-project">Project</label><select class="form-select" name="project_id" id="task-project"><option value="">None</option><?php foreach($projects as $pr): ?><option value="<?=$pr['id']?>" <?=$task['project_id']==$pr['id']?'selected':''?>>#<?=$pr['id']?> - <?=h($pr['name'])?></option><?php endforeach; ?></select></div></div><div class="mb-3"><label class="form-label">Assignees</label><div class="border rounded p-2" style="max-height:12rem;overflow-y:auto"><?php foreach($users as $u): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="assignees[]" value="<?=$u['id']?>" id="assignee-<?=$u['id']?>" <?=in_array((int)$u['id'],$selected,true)?'checked':''?>><label class="form-check-label" for="assignee-<?=$u['id']?>"><?=tb_user_with_activity($u)?></label></div><?php endforeach; if(!$users): ?><div class="small muted">No users can be assigned yet.</div><?php endif; ?></div><div class="form-text">Tick a name to assign the task. Untick a name to unassign them - leaving everybody unticked keeps the task unassigned.</div></div><button class="btn btn-primary">Save task</button><?php if($id): ?> <a class="btn btn-outline-secondary" href="?page=new&clone=<?=$id?>" title="Open the task create page prefilled from this task (#46)">Clone</a><?php endif; ?> <a class="btn btn-outline-secondary" href="?">Cancel</a></form></div><?php // ---- Scheduler settings (#35): a separated show/hide area of its own. The three
      // scheduler inputs live OUTSIDE the main <form> (in this card) but stay attached to
      // it through the HTML5 form="task-form" attribute, so the one "Save task" button
      // still persists everything and the POST shape is unchanged. The area hides behind
      // a show/hide toggle; on the edit page it starts expanded when the task already has
      // a schedule. Collapsing only hides the inputs visually - they still submit, so a
      // collapsed save never silently drops the schedule. ?>
    <div class="card p-4 mb-4" id="scheduler-card"><div class="d-flex justify-content-between align-items-center"><button class="btn btn-outline-secondary<?= $schedulerActive ? ' border-primary' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#scheduler-area" aria-expanded="<?= $schedulerActive ? 'true' : 'false' ?>" aria-controls="scheduler-area">Auto-reopen schedule<?= $schedulerActive ? ' <span class="badge text-bg-info ms-1">on</span>' : '' ?></button><span class="small muted">Recurring reopen settings for the auto-scheduler - show, edit or hide</span></div><div class="collapse<?= $schedulerActive ? ' show' : '' ?>" id="scheduler-area"><div class="pt-3"><div class="row"><div class="col-md-4 mb-3"><label class="form-label" for="schedule-period">Auto-reopen schedule</label><select class="form-select" name="schedule_period" form="task-form" id="schedule-period"><option value="">None</option><?php foreach(schedule_periods() as $periodKey=>$periodLabel): ?><option value="<?=h($periodKey)?>" <?=$task['schedule_period']===$periodKey?'selected':''?>><?=h($periodLabel)?></option><?php endforeach; ?></select><div class="form-text">When set, the cron job applies the reopen status and assignees below to this task and comments &quot;Reopen by auto-scheduler&quot; if it has not been updated within the selected period.</div></div><div class="col-md-4 mb-3"><label class="form-label" for="schedule-status">Reopen status</label><select class="form-select" name="schedule_status_id" form="task-form" id="schedule-status"><option value="" <?=$scheduleStatusStored===0?'selected':''?>>Default (TODO)</option><?php foreach($statuses as $st): ?><option value="<?=$st['id']?>" <?=$scheduleStatusStored===(int)$st['id']?'selected':''?>><?=h($st['name'])?></option><?php endforeach; ?></select><div class="form-text">Status the cron job sets on reopen. Empty = TODO, the pre-#31 behavior.</div></div><div class="col-md-4 mb-3"><label class="form-label" for="schedule-assignees">Reopen assignees</label><select class="form-select" name="schedule_assignees[]" form="task-form" id="schedule-assignees" multiple size="4"><?php foreach($users as $u): ?><option value="<?=$u['id']?>" <?=in_array((int)$u['id'],$scheduleSelectedIds,true)?'selected':''?>><?=h($u['display_name'])?></option><?php endforeach; ?></select><div class="form-text">Assignees the cron job sets on reopen (Ctrl/Cmd-click for several). Empty = unassigned.</div></div></div><div class="form-text mb-0">Saved together with the task by the same &quot;Save task&quot; button above - the section only controls visibility.</div></div></div></div>
    <?php if(!$id): ?><script>
// #47: saving a NEW task with no Project selected asks for confirmation first.
// The guard sits on the form's onsubmit; a picked project (or a select that is
// not rendered) submits silently, an empty one pops the confirm box - OK saves
// the task without a project, Cancel aborts so a project can be picked. The
// edit form keeps saving as before: there the stored project is visible and
// unsetting it is a deliberate change, not an oversight to warn about.
function tb_confirm_no_project(){
var sel=document.getElementById('task-project');
if(!sel||sel.value!=='')return true;
return confirm('No Project is selected. Create this task without a project?');
}
</script><?php endif; ?>
    <?php if(!$id && $tpls): ?><script>
(function(){
var sel=document.getElementById('task-template');
if(!sel)return;
sel.addEventListener('change',function(){
    var o=sel.options[sel.selectedIndex];
    if(!o||o.value==='')return;
    var d=o.getAttribute('data-description');
    if(d!==null){document.querySelector('textarea[name=description]').value=d;}
});
})();
</script><?php endif; ?>
    <?php render_footer(); exit;
}

if ($page==='task') {
    $id=(int)($_GET['id']??0);$s=$pdo->prepare('SELECT t.*,s.name status_name,s.color status_color,u.display_name creator,u.last_seen_at creator_last_seen,u.logged_out creator_logged_out,prj.name project_name FROM tasks t JOIN statuses s ON s.id=t.status_id LEFT JOIN users u ON u.id=t.created_by LEFT JOIN projects prj ON prj.id=t.project_id WHERE t.id=?');$s->execute([$id]);$t=$s->fetch();
    if(!$t){http_response_code(404);exit('Task not found.');}
    $s=$pdo->prepare('SELECT u.display_name,u.last_seen_at,u.logged_out FROM task_assignees a JOIN users u ON u.id=a.user_id WHERE a.task_id=?');$s->execute([$id]);$assignees=$s->fetchAll();
    $s=$pdo->prepare('SELECT c.*,u.display_name,u.last_seen_at,u.logged_out FROM comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.task_id=? ORDER BY c.created_at');$s->execute([$id]);$comments=$s->fetchAll();
    $s=$pdo->prepare('SELECT a.*,u.display_name,u.last_seen_at,u.logged_out FROM attachments a LEFT JOIN users u ON u.id=a.user_id WHERE a.task_id=? ORDER BY a.created_at DESC');$s->execute([$id]);$attachments=$s->fetchAll();
    $s=$pdo->prepare('SELECT h.*,u.display_name,u.last_seen_at,u.logged_out FROM task_history h LEFT JOIN users u ON u.id=h.user_id WHERE h.task_id=? ORDER BY h.created_at DESC');$s->execute([$id]);$historyRows=$s->fetchAll();
    $s=$pdo->prepare('SELECT l.*,t.title FROM task_links l JOIN tasks t ON t.id=l.related_task_id WHERE l.task_id=? ORDER BY l.link_type');$s->execute([$id]);$links=$s->fetchAll();
    $all=$pdo->prepare('SELECT id,title FROM tasks WHERE id<>? AND archived_at IS NULL ORDER BY id DESC LIMIT 200');$all->execute([$id]);
    $isArchived=task_is_archived($t);
    render_header('Task #'.$id,$config,$error); ?>
    <div class="d-flex justify-content-between align-items-start mb-3"><div><div class="muted">Task #<?=$id?> · <?=tb_status_badge((string)$t['status_name'], $t['status_color'] ?? null)?><?php if($isArchived): ?> <span class="badge text-bg-secondary">Archived</span><?php endif; ?></div><h1 class="h3"><?=h($t['title'])?></h1></div><?php if(!$isArchived): ?><a class="btn btn-primary" href="?page=edit&id=<?=$id?>">Edit task</a><?php endif; ?></div>
    <div class="row g-4"><div class="col-lg-8"><div class="card p-4 mb-4"><div class="prewrap"><?=h($t['description'])?></div><hr><div class="small muted">Created by <?=tb_user_with_activity(['display_name'=>$t['creator'],'last_seen_at'=>$t['creator_last_seen']??null])?> · <?=h(tb_ago($t['created_at']))?> · Updated <?=h(tb_ago($t['updated_at']))?></div><div class="mt-2"><strong>Assignees:</strong> <?php $assigneeHtml=[];foreach($assignees as $au){$assigneeHtml[]=tb_user_with_activity($au);}echo implode(', ',$assigneeHtml)?:'Unassigned';?></div><div><strong>Priority:</strong> <?=h((string)max(1,min(1000,(int)($t['priority'] ?? 1))))?></div><div><strong>Complexity:</strong> <?=h((string)max(1,min(1000,(int)($t['complexity'] ?? 1))))?><?php if((int)($t['complexity'] ?? 1)>1): ?> <span class="small muted">(drives the block width on the <a href="?page=waterflow">waterflow chart</a>)</span><?php endif; ?></div><?php if(!empty($t['schedule_period'])): $schStatusId=(int)($t['schedule_status_id']??0); $schAssignees=schedule_existing_assignees($pdo, schedule_target_assignees($t['schedule_assignees']??null)); $schNames=[]; if($schAssignees){$ph=implode(',',array_fill(0,count($schAssignees),'?'));$sq=$pdo->prepare('SELECT display_name FROM users WHERE id IN ('.$ph.') ORDER BY display_name');$sq->execute($schAssignees);$schNames=array_column($sq->fetchAll(),'display_name');} if($schStatusId){$sq=$pdo->prepare('SELECT name FROM statuses WHERE id=?');$sq->execute([$schStatusId]);$schStatusName=(string)$sq->fetchColumn(); if($schStatusName===''){$schStatusName='TODO';}}else{$schStatusName='TODO';} ?><div><strong>Schedule:</strong> <?=h(schedule_periods()[$t['schedule_period']] ?? $t['schedule_period'])?> <span class="badge text-bg-info">auto-reopen</span> <span class="small muted">· reopens to <?=h($schStatusName)?>, assignees: <?=$schNames?implode(', ', array_map(fn(string $n): string => '<span class="text-nowrap">'.h($n).'</span>', $schNames)):'none'?></span></div><?php endif; ?><?php if($t['parent_id']): ?><div><strong>Parent:</strong> <a href="?page=task&id=<?=$t['parent_id']?>">Task #<?=$t['parent_id']?></a></div><?php endif; ?><?php if($t['project_id']): ?><div><strong>Project:</strong> <a href="?page=project&id=<?=$t['project_id']?>"><?=h($t['project_name'])?></a></div><?php endif; ?></div>
    <div class="card p-4 mb-4" id="comments"><h2 class="h5">Comments</h2><?php foreach($comments as $c): ?><div class="border-bottom py-3" id="comment-<?=$c['id']?>"><strong><?=tb_user_with_activity($c)?></strong> <span class="small muted"><?=h(tb_ago($c['created_at']))?></span><div class="prewrap mt-1"><?=h($c['body'])?></div></div><?php endforeach; ?><form method="post" class="mt-3"><?=csrf_field()?><input type="hidden" name="action" value="comment"><input type="hidden" name="task_id" value="<?=$id?>"><textarea class="form-control mb-2" name="body" rows="3" placeholder="Write a comment..." required></textarea><button class="btn btn-primary">Add comment</button></form></div>
    <div class="card p-4 mb-4"><h2 class="h5">Attachments</h2><?php foreach($attachments as $a): ?><div class="d-flex justify-content-between border-bottom py-2"><a href="?page=download&id=<?=$a['id']?>"><?=h($a['original_name'])?></a><span class="small muted"><?=tb_user_with_activity($a)?> · <?=number_format($a['file_size']/1024,1)?> KB · <?=h(tb_ago($a['created_at']))?></span><?php if(is_admin()||(int)$a['user_id']===(int)user()['id']): ?><form method="post" class="d-inline" onsubmit="return confirm('Delete this attachment permanently?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_attachment"><input type="hidden" name="attachment_id" value="<?=$a['id']?>"><button class="btn btn-sm btn-link text-danger p-0" title="Delete this attachment">delete</button></form><?php endif; ?></div><?php endforeach; ?><form method="post" enctype="multipart/form-data" class="mt-3"><?=csrf_field()?><input type="hidden" name="action" value="upload"><input type="hidden" name="task_id" value="<?=$id?>"><div class="input-group"><input class="form-control" type="file" name="file" required><button class="btn btn-outline-primary">Upload</button></div><div class="form-text">Max <?=round($config['max_upload_bytes']/1024/1024)?> MB. Allowed: common images, PDF, TXT, ZIP, DOCX and XLSX.</div></form></div>
    <div class="card p-4"><h2 class="h5">History</h2><?php foreach($historyRows as $r): ?><div class="border-bottom py-2"><strong><?=h($r['event'])?></strong> <span class="small muted">by <?=tb_user_with_activity($r)?> · <?=h(tb_ago($r['created_at']))?></span> <a class="small text-decoration-none" href="?page=task_history&id=<?=$r['id']?>" title="Full audit of this entry (#54)">details</a><?php if($r['details']): ?><div class="small"><?=h(tb_excerpt((string)$r['details'], 100))?></div><?php endif; ?><?php if($r['field']): ?><div class="small muted">Field &quot;<?=h((string)$r['field']) ?>&quot;: from &quot;<?=h(tb_excerpt((string)($r['old_value'] ?? '—'), 100)) ?>&quot; to &quot;<?=h(tb_excerpt((string)($r['new_value'] ?? '—'), 100)) ?>&quot;</div><?php endif; ?></div><?php endforeach; ?></div></div>
    <div class="col-lg-4"><div class="card p-4"><h2 class="h5">Relationships</h2><?php foreach($links as $l): ?><div class="border-bottom py-2"><span class="badge text-bg-secondary"><?=h($l['link_type'])?></span> <a href="?page=task&id=<?=$l['related_task_id']?>">#<?=$l['related_task_id']?> <?=h($l['title'])?></a><form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="action" value="unlink_task"><input type="hidden" name="task_id" value="<?=$id?>"><input type="hidden" name="related_task_id" value="<?=$l['related_task_id']?>"><input type="hidden" name="link_type" value="<?=h($l['link_type'])?>"><button class="btn btn-sm btn-link text-danger">remove</button></form></div><?php endforeach; ?><form method="post" class="mt-3"><?=csrf_field()?><input type="hidden" name="action" value="link_task"><input type="hidden" name="task_id" value="<?=$id?>"><label class="form-label">Link type</label><select class="form-select mb-2" name="link_type"><?php foreach(TASK_BOARD_LINK_TYPES as $linkTypeValue=>$linkTypeLabel): ?><option value="<?=h($linkTypeValue)?>"><?=h($linkTypeLabel)?></option><?php endforeach; ?></select><label class="form-label">Task</label><select class="form-select mb-2" name="related_task_id" required><?php foreach($all->fetchAll() as $a): ?><option value="<?=$a['id']?>">#<?=$a['id']?> - <?=h($a['title'])?></option><?php endforeach; ?></select><button class="btn btn-outline-primary w-100">Add relationship</button></form></div>
    <div class="card p-4 mt-4"><h2 class="h5">Actions</h2><?php if($isArchived): ?><p class="small muted">This task is archived and hidden from the task list.</p><form method="post" class="mb-2"><?=csrf_field()?><input type="hidden" name="action" value="restore_task"><input type="hidden" name="id" value="<?=$id?>"><button class="btn btn-outline-primary w-100">Restore task</button></form><form method="post" onsubmit="return confirm('Delete this task permanently? This cannot be undone.')"><?=csrf_field()?><input type="hidden" name="action" value="delete_task"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="return" value="?"><button class="btn btn-outline-danger w-100">Delete permanently</button></form><?php else: ?><form method="post" onsubmit="return confirm('Archive this task? It will be hidden from the task list but can be restored from the archive.')"><?=csrf_field()?><input type="hidden" name="action" value="archive_task"><input type="hidden" name="id" value="<?=$id?>"><button class="btn btn-outline-danger w-100">Archive task</button></form><?php endif; ?></div></div></div>
    <?php render_footer(); exit;
}

if ($page==='project_edit' || $page==='project_new') {
    $id=(int)($_GET['id']??0); $project=['id'=>0,'name'=>'','description'=>'','status_id'=>0]; $selected=[];
    if($id){$found=find_project($pdo,$id);
        if(!$found){http_response_code(404);exit('Project not found.');}
        $project=$found;
        $s=$pdo->prepare('SELECT user_id FROM project_assignees WHERE project_id=?');$s->execute([$id]);$selected=array_map('intval',array_column($s->fetchAll(),'user_id'));}
    $users=user_options($pdo);$statuses=all_statuses($pdo);$ptpls=$id?[]:$pdo->query("SELECT id,name,description FROM templates WHERE type='project' ORDER BY name")->fetchAll();
    render_header($id?'Edit project':'New project',$config,$error); ?>
    <div class="card p-4"><h2 class="h4"><?= $id?'Edit project':'Create project' ?></h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_project"><input type="hidden" name="id" value="<?=$id?>"><?php if(!$id && $ptpls): ?><div class="mb-3"><label class="form-label">Template</label><select class="form-select" id="project-template"><option value="">No template</option><?php foreach($ptpls as $tp): ?><option value="<?=$tp['id']?>" data-description="<?=h($tp['description'])?>"><?=h($tp['name'])?></option><?php endforeach; ?></select><div class="form-text">Choosing a template fills the description below with the template text.</div></div><?php endif; ?><div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="name" maxlength="200" value="<?=h($project['name'])?>" required></div><div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="5"><?=h($project['description'])?></textarea></div><div class="row"><div class="col-md-6 mb-3"><label class="form-label">Status</label><select class="form-select" name="status_id" required><?php foreach($statuses as $st): ?><option value="<?=$st['id']?>" <?=$project['status_id']==$st['id']?'selected':''?>><?=h($st['name'])?></option><?php endforeach; ?></select></div></div><div class="mb-3"><label class="form-label">Assignees</label><select class="form-select" name="assignees[]" multiple size="5"><?php foreach($users as $u): ?><option value="<?=$u['id']?>" <?=in_array((int)$u['id'],$selected,true)?'selected':''?>><?=h($u['display_name'])?></option><?php endforeach; ?></select><div class="form-text">Use Ctrl/Cmd to select multiple people.</div></div><button class="btn btn-primary">Save project</button> <a class="btn btn-outline-secondary" href="?page=projects">Cancel</a></form></div>
    <?php if(!$id && $ptpls): ?><script>
(function(){
var sel=document.getElementById('project-template');
if(!sel)return;
sel.addEventListener('change',function(){
    var o=sel.options[sel.selectedIndex];
    if(!o||o.value==='')return;
    var d=o.getAttribute('data-description');
    if(d!==null){document.querySelector('textarea[name=description]').value=d;}
});
})();
</script><?php endif; ?>
    <?php render_footer(); exit;
}

if ($page==='project') {
    $id=(int)($_GET['id']??0);$s=$pdo->prepare('SELECT pr.*,s.name status_name,s.color status_color,u.display_name creator,u.last_seen_at creator_last_seen,u.logged_out creator_logged_out FROM projects pr JOIN statuses s ON s.id=pr.status_id LEFT JOIN users u ON u.id=pr.created_by WHERE pr.id=?');$s->execute([$id]);$pr=$s->fetch();
    if(!$pr){http_response_code(404);exit('Project not found.');}
    $s=$pdo->prepare('SELECT u.display_name,u.last_seen_at,u.logged_out FROM project_assignees pa JOIN users u ON u.id=pa.user_id WHERE pa.project_id=?');$s->execute([$id]);$projectAssignees=$s->fetchAll();
    $s=$pdo->prepare('SELECT t.id,t.title,t.updated_at,s.name status_name,s.color status_color FROM tasks t JOIN statuses s ON s.id=t.status_id WHERE t.project_id=? ORDER BY t.updated_at DESC');$s->execute([$id]);$projectTasks=$s->fetchAll();
    render_header('Project #'.$id,$config,$error); ?>
    <div class="d-flex justify-content-between align-items-start mb-3"><div><div class="muted">Project #<?=$id?> · <?=tb_status_badge((string)$pr['status_name'], $pr['status_color'] ?? null)?></div><h1 class="h3"><?=h($pr['name'])?></h1></div><a class="btn btn-primary" href="?page=project_edit&id=<?=$id?>">Edit project</a></div>
    <div class="row g-4"><div class="col-lg-8"><div class="card p-4 mb-4"><div class="prewrap"><?=h($pr['description'])?></div><hr><div class="small muted">Created by <?=tb_user_with_activity(['display_name'=>$pr['creator'],'last_seen_at'=>$pr['creator_last_seen']??null])?> · <?=h(tb_ago($pr['created_at']))?> · Updated <?=h(tb_ago($pr['updated_at']))?></div><div class="mt-2"><strong>Assignees:</strong> <?php $assigneeHtml=[];foreach($projectAssignees as $au){$assigneeHtml[]=tb_user_with_activity($au);}echo implode(', ',$assigneeHtml)?:'Unassigned';?></div></div>
    <div class="card p-4 mb-4"><h2 class="h5">Tasks in this project</h2><?php foreach($projectTasks as $pt): ?><div class="border-bottom py-2 d-flex justify-content-between"><a class="text-decoration-none" href="?page=task&id=<?=$pt['id']?>">#<?=$pt['id']?> · <?=h($pt['title'])?></a><?=tb_status_badge((string)$pt['status_name'], $pt['status_color'] ?? null)?></div><?php endforeach; ?><?php if(!$projectTasks): ?><p class="muted mb-0">No tasks in this project yet.</p><?php endif; ?><div class="mt-3"><a class="btn btn-sm btn-outline-primary" href="?page=tasks&project=<?=$id?>">Tasks…</a> <span class="small muted">Opens the task list filtered to this project.</span></div></div></div>
    <div class="col-lg-4"><div class="card p-4"><h2 class="h5">Actions</h2><form method="post" onsubmit="return confirm('Delete this project permanently? The tasks will be kept but lose their project.')"><?=csrf_field()?><input type="hidden" name="action" value="delete_project"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="return" value="?page=projects"><button class="btn btn-outline-danger w-100">Delete project</button></form></div></div></div>
    <?php render_footer(); exit;
}

// ---- Projects list v2 (Task #81, the #57 refactor recipe) -----------------
// The projects list rebuilt on the shared CRUD list engine (src/crud.php):
// the count/page/fetch query, the URL builder, the sortable table skeleton,
// the pagination window and the bulk-select wiring come from the abstraction.
// The list stays unfiltered exactly like before (no search card yet - the
// parity tests pin the same feature set), and the shared sort_header() took
// over from the page's private header copy, so the a11y markup matches the
// other lists from now on.
if ($page === 'projects') {
    $projectsPage = 'projects';
    $list=tb_crud_list([
        'pdo'=>$pdo,
        'table'=>'projects pr JOIN statuses s ON s.id=pr.status_id',
        'select'=>'pr.id,pr.name,pr.updated_at,pr.created_by,s.name status_name,s.color status_color,(SELECT COUNT(*) FROM tasks t WHERE t.project_id=pr.id) task_count',
        'where'=>[],
        'params'=>[],
        'sortColumns'=>[
            'name'=>'pr.name {dir}',
            'status'=>'s.sort_order {dir}, s.name {dir}',
            'tasks'=>'task_count {dir}',
            'updated'=>'pr.updated_at {dir}',
        ],
        'defaultSort'=>'updated',
        'defaultDir'=>'desc',
        'orderBySuffix'=>'pr.id {dir}',
    ]);
    $projects=$list['rows'];$projectSort=$list['sort'];$totalProjects=$list['total'];$totalPages=$list['pages'];$projectPage=$list['page'];
    $projectsUrl=tb_crud_url_builder($projectsPage,['sort','dir']);
    $projectAssignees=project_assignees_by_project($pdo, array_column($projects,'id'));
    $projectColumns=[
        ['header'=>'<th class="ps-3" style="width:36px"><input type="checkbox" class="form-check-input" id="check-all-projects" aria-label="Select all projects on this page"></th>','cell'=>fn(array $pr): string => '<td class="ps-3"><input type="checkbox" class="form-check-input project-row-check" name="project_ids[]" value="'.$pr['id'].'" aria-label="Select project #'.$pr['id'].'"></td>'],
        ['label'=>'Project','sortKey'=>'name','sort'=>$projectSort,'url'=>$projectsUrl,'cell'=>fn(array $pr): string => '<td><a class="fw-semibold text-decoration-none" href="?page=project&id='.$pr['id'].'">#'.$pr['id'].' · '.h($pr['name']).'</a></td>'],
        ['label'=>'Status','sortKey'=>'status','sort'=>$projectSort,'url'=>$projectsUrl,'cell'=>fn(array $pr): string => '<td>'.tb_status_badge((string)$pr['status_name'], $pr['status_color'] ?? null).'</td>'],
        ['label'=>'Assignees','cell'=>function(array $pr) use ($projectAssignees): string {
            $puHtml=[];foreach($projectAssignees[(int)$pr['id']]??[] as $pu){$puHtml[]=tb_user_with_activity($pu);}
            return '<td>'.(implode(', ',$puHtml)?:'—').'</td>';
        }],
        ['label'=>'Tasks','sortKey'=>'tasks','sort'=>$projectSort,'url'=>$projectsUrl,'cell'=>fn(array $pr): string => '<td class="text-nowrap"><span class="me-2">'.$pr['task_count'].' task'.($pr['task_count']===1?'':'s').'</span><a class="btn btn-sm btn-outline-primary" href="?page=tasks&project='.$pr['id'].'">Tasks…</a></td>'],
        ['label'=>'Updated','sortKey'=>'updated','sort'=>$projectSort,'url'=>$projectsUrl,'cell'=>fn(array $pr): string => '<td class="small muted">'.h(tb_ago($pr['updated_at'])).'</td>'],
        ['label'=>'Actions','cell'=>function(array $pr): string {
            $canDelete=is_admin()||(int)$pr['created_by']===(int)user()['id'];
            return '<td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?page=project_edit&id='.$pr['id'].'">Edit</a>'.($canDelete ? ' <button type="submit" class="btn btn-sm btn-outline-danger" form="delete-project-'.$pr['id'].'">Delete</button>' : '').'</td>';
        }],
    ];
    ob_start(); ?>
<button type="submit" class="btn btn-outline-danger" id="bulk-projects-btn" disabled>Delete selected (<span id="bulk-projects-count">0</span>)</button>
    <?php $bulkControls=trim(ob_get_clean());
    render_header('Projects',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Projects</h1><div class="muted"><?=$totalProjects?> project<?=$totalProjects===1?'':'s'?></div></div><a class="btn btn-primary" href="?page=project_new">+ New project</a></div>
    <form method="post" id="bulk-projects-form" onsubmit="return confirm('Delete all selected projects permanently?')"><?=csrf_field()?><input type="hidden" name="action" value="bulk_delete_projects"><input type="hidden" name="return" value="<?=h($projectsUrl(['p'=>$projectPage]))?>"><?php foreach (['sort','dir'] as $fk): if(($_GET[$fk]??'')!==''): ?><input type="hidden" name="<?=$fk?>" value="<?=h($_GET[$fk])?>"><?php endif; endforeach; ?>
    <div class="card"><?=tb_crud_table(['columns'=>$projectColumns,'rows'=>$projects,'emptyText'=>'No projects yet.','emptyColspan'=>7])?></div>
    <?php echo tb_crud_pagination(['ariaLabel'=>'Project list pages','page'=>$projectPage,'pages'=>$totalPages,'url'=>$projectsUrl,'trailing'=>$bulkControls]), "\n"; ?>
    </form>
    <?php foreach($projects as $pr): if(is_admin()||(int)$pr['created_by']===(int)user()['id']): ?><form method="post" id="delete-project-<?=$pr['id']?>" class="d-none" onsubmit="return confirm('Delete project #<?=$pr['id']?> permanently?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_project"><input type="hidden" name="id" value="<?=$pr['id']?>"><input type="hidden" name="return" value="<?=h($projectsUrl(['p'=>$projectPage]))?>"></form><?php endif; endforeach; ?>
    <?=tb_crud_bulk_select_script(['selectAllId'=>'check-all-projects','rowClass'=>'project-row-check','bulkButtonId'=>'bulk-projects-btn','countId'=>'bulk-projects-count','regionSwapEvent'=>false])?>
    <?php render_footer(); exit;
}


// ---- Templates list (v2 since #82) ----------------------------------------------------------
// The templates list, rebuilt on the shared CRUD list engine (src/crud.php):
// the count/page/fetch query, the URL builder, the sortable table skeleton
// and the pagination window come from the abstraction. The type[] multi-select
// filter stays exactly as it was (filter_values() in, allowlisted bound
// parameters out) and the feature set is otherwise unchanged: no text search
// yet, no bulk actions, no realtime region. The shared sort_header() took
// over from the plain <th> headers, so the a11y markup matches the other
// lists from now on. The parity proof is tests/E2E/CrudAbstractionTest.php:
// it pinned this page feature by feature against the classic ?page=templates
// contract. The generated links carry the canonical page name, so bookmarks
// and saved links survive.
if ($page === 'templates') {
    $templatesPage = 'templates';
    // Multi-select filter (#25): the type dropdown submits type[] as an array,
    // legacy scalar ?type=task links still work through filter_values().
    // Values outside the two real template types are ignored.
    $typeFilter=array_values(array_intersect(filter_values('type'),['task','project']));
    $conds=[]; $params=[];
    if ($typeFilter) {
        $conds[] = 't.type IN ('.tb_in_placeholders(count($typeFilter)).')';
        array_push($params, ...$typeFilter);
    }
    $list=tb_crud_list([
        'pdo'=>$pdo,
        'table'=>'templates t',
        'select'=>'t.id,t.type,t.name,t.description,t.updated_at,t.created_by',
        'where'=>$conds,
        'params'=>$params,
        'sortColumns'=>[
            'name'=>'t.name {dir}',
            'type'=>'t.type {dir}, t.name {dir}',
            'updated'=>'t.updated_at {dir}',
        ],
        'defaultSort'=>'name',
        'defaultDir'=>'asc',
        'orderBySuffix'=>'t.id {dir}',
    ]);
    $templates=$list['rows'];$templateSort=$list['sort'];$totalTemplates=$list['total'];$totalPages=$list['pages'];$templatePage=$list['page'];
    $templatesUrl=tb_crud_url_builder($templatesPage,['type']);
    $templateColumns=[
        ['label'=>'Name','sortKey'=>'name','sort'=>$templateSort,'url'=>$templatesUrl,'cell'=>fn(array $tp): string => '<td><a class="fw-semibold text-decoration-none" href="?page=template_edit&id='.$tp['id'].'">'.h($tp['name']).'</a></td>'],
        ['label'=>'Type','sortKey'=>'type','sort'=>$templateSort,'url'=>$templatesUrl,'cell'=>fn(array $tp): string => '<td><span class="badge text-bg-light border">'.h($tp['type']).'</span></td>'],
        ['label'=>'Description','cell'=>function(array $tp): string {
            $desc=(string)$tp['description']; $short=preg_replace('/^(.{0,140}).*$/su','$1',$desc);
            return '<td class="small muted" style="max-width:32rem">'.h($short).(strlen($desc)>strlen($short)?'&hellip;':'').'</td>';
        }],
        ['label'=>'Updated','sortKey'=>'updated','sort'=>$templateSort,'url'=>$templatesUrl,'cell'=>fn(array $tp): string => '<td class="small muted">'.h(tb_ago($tp['updated_at'])).'</td>'],
        ['label'=>'Actions','cell'=>function(array $tp): string {
            $canDelete=is_admin()||(int)$tp['created_by']===(int)user()['id'];
            return '<td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?page=template_edit&id='.$tp['id'].'">Edit</a>'.($canDelete ? ' <form method="post" class="d-inline" onsubmit="return confirm(\'Delete this template permanently?\')">'.csrf_field().'<input type="hidden" name="action" value="delete_template"><input type="hidden" name="id" value="'.$tp['id'].'"><button class="btn btn-sm btn-outline-danger">Delete</button></form>' : '').'</td>';
        }],
    ];
    render_header('Templates',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Templates</h1><div class="muted"><?=$totalTemplates?> template<?=$totalTemplates===1?'':'s'?></div></div><a class="btn btn-primary" href="?page=template_new">+ New template</a></div>
    <div class="card p-3 mb-3"><form class="row g-2"><input type="hidden" name="page" value="<?=$templatesPage?>"><div class="col-md-3"><label class="form-label small muted mb-1">Template types</label><select class="form-select" name="type[]" multiple size="2" aria-label="Filter by template types"><option value="task" <?=in_array('task',$typeFilter,true)?'selected':''?>>Task templates</option><option value="project" <?=in_array('project',$typeFilter,true)?'selected':''?>>Project templates</option></select><div class="form-text">Hold Ctrl/Cmd to select several.</div></div><div class="col-md-3 d-grid align-self-end"><button class="btn btn-outline-primary">Filter</button></div></form></div>
    <div class="card"><?=tb_crud_table(['columns'=>$templateColumns,'rows'=>$templates,'emptyText'=>'No templates found.','emptyColspan'=>5])?></div>
    <?php if($totalPages>1): echo tb_crud_pagination(['ariaLabel'=>'Template list pages','page'=>$templatePage,'pages'=>$totalPages,'url'=>$templatesUrl]), "\n"; endif; ?>
    <?php render_footer(); exit;
}

if ($page==='template_new' || $page==='template_edit') {
    $id=(int)($_GET['id']??0); $tpl=['id'=>0,'type'=>'task','name'=>'','description'=>''];
    if($id){$s=$pdo->prepare('SELECT * FROM templates WHERE id=?');$s->execute([$id]);$tpl=$s->fetch() ?: $tpl;}
    render_header($id?'Edit template':'New template',$config,$error); ?>
    <div class="card p-4"><h2 class="h4"><?= $id?'Edit template':'Create template' ?></h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_template"><input type="hidden" name="id" value="<?=$id?>"><div class="row"><div class="col-md-4 mb-3"><label class="form-label">Type</label><select class="form-select" name="type"><option value="task" <?=$tpl['type']==='task'?'selected':''?>>Task</option><option value="project" <?=$tpl['type']==='project'?'selected':''?>>Project</option></select></div><div class="col-md-8 mb-3"><label class="form-label">Name</label><input class="form-control" name="name" maxlength="120" value="<?=h($tpl['name'])?>" required></div></div><div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="6"><?=h($tpl['description'])?></textarea><div class="form-text">Selecting this template on a create page fills the description with this text.</div></div><button class="btn btn-primary">Save template</button> <a class="btn btn-outline-secondary" href="?page=templates">Cancel</a></form></div>
    <?php render_footer(); exit;
}

$statuses=all_statuses($pdo);
$users=user_options($pdo);
$parents=$pdo->query('SELECT DISTINCT t.parent_id, p.title parent_title FROM tasks t JOIN tasks p ON p.id=t.parent_id WHERE t.parent_id IS NOT NULL ORDER BY t.parent_id DESC')->fetchAll();
$projectOptions=$pdo->query('SELECT id,name FROM projects ORDER BY name')->fetchAll();

// ---- Settings (#55) -------------------------------------------------------------------------
// The personal settings page: deliberately minimal for now ("under
// development") - it is the base the future preference screens will build on.
if ($page==='settings') {
    // Timezone preference (#108): every datetime on the board renders in this
    // zone from the stored epoch seconds; the default is UTC (the storage
    // zone), so a fresh user sees exactly the classic board until they pick.
    $tzPref = tb_user_pref_get($pdo, (int)user()['id'], 'timezone', 'UTC');
    if (!in_array($tzPref, DateTimeZone::listIdentifiers(), true)) $tzPref = 'UTC';
    // Date/time format preference (#110): the shape the stamps render in,
    // from the same #66 user_preferences discipline; the default keeps the
    // classic board's exact shape. The preview renders the real current
    // moment in the viewer's zone so the pick is never abstract.
    $fmtPref = tb_user_pref_get($pdo, (int)user()['id'], 'datetime_format', TB_DATETIME_FORMAT_DEFAULT);
    if (!array_key_exists($fmtPref, tb_datetime_formats())) $fmtPref = TB_DATETIME_FORMAT_DEFAULT;
    render_header('Settings',$config,$error); ?>
    <div class="row justify-content-center"><div class="col-lg-7"><div class="card p-4"><h1 class="h4 mb-3">Settings</h1><p class="mb-3">Personal preferences. The theme switch already works from the navbar; everything else gathers here as it arrives.</p>
    <form method="post" class="row g-2 align-items-end" action="?page=settings"><?=csrf_field()?><input type="hidden" name="action" value="save_timezone"><div class="col-md-8"><label class="form-label small muted mb-1" for="timezone-select">Timezone - every date and time on the board shows in this zone (stored times are UTC)</label><select class="form-select" id="timezone-select" name="timezone"><?php foreach (DateTimeZone::listIdentifiers() as $tzName): ?><option value="<?=h($tzName)?>" <?=$tzPref===$tzName?'selected':''?>><?=h($tzName)?></option><?php endforeach; ?></select><div class="form-text">The board stores every moment as a UTC timestamp and renders it in your zone; your current zone renders as <strong><?=h((new DateTime('now', new DateTimeZone($tzPref)))->format($fmtPref))?></strong>.</div></div><div class="col-md-4 d-grid"><button class="btn btn-outline-primary">Save timezone</button></div></form>
    <form method="post" class="row g-2 align-items-end mt-3" action="?page=settings"><?=csrf_field()?><input type="hidden" name="action" value="save_datetime_format"><div class="col-md-8"><label class="form-label small muted mb-1" for="datetime-format-select">Date/time format - the shape every date and time renders in, shown in your timezone with relative "how long ago" notes where a moment has passed</label><select class="form-select" id="datetime-format-select" name="datetime_format"><?php foreach (tb_datetime_formats() as $fmtName => $fmtSample): ?><option value="<?=h($fmtName)?>" <?=$fmtPref===$fmtName?'selected':''?>><?=h($fmtName)?> - e.g. <?=h($fmtSample)?></option><?php endforeach; ?></select><div class="form-text">Every datetime keeps its relative "15 minutes ago" style note next to the formatted time; your format renders the current moment as <strong><?=h((new DateTime('now', new DateTimeZone($tzPref)))->format($fmtPref))?></strong>.</div></div><div class="col-md-4 d-grid"><button class="btn btn-outline-primary">Save format</button></div></form>
    </div></div></div>
    <?php render_footer(); exit;
}

// ---- Know-hows knowledge base (#53) -----------------------------------------
// Documents: standard processes, rule books and every other know-how worth
// keeping, "like a confluence database". CRUD with comments and attachments
// of their own (same shapes and rules as the task page: the same upload MIME
// allowlist, the same uploads folder, creator-or-administrator edit and
// delete). Every signed-in user can read and comment.
if ($page==='document_new' || $page==='document_edit') {
    $id = $page==='document_edit' ? (int)($_GET['id'] ?? 0) : 0;
    $doc = null;
    if ($id > 0) {
        $s=$pdo->prepare('SELECT * FROM documents WHERE id=?'); $s->execute([$id]); $doc=$s->fetch();
        if (!$doc) { http_response_code(404); exit('Document not found.'); }
        if (!document_can_edit($doc)) { http_response_code(403); exit('Forbidden.'); }
    }
    render_header($id?'Edit know-how':'New know-how',$config,$error); ?>
    <div class="row justify-content-center"><div class="col-lg-8"><div class="card p-4"><h2 class="h4"><?=$id?'Edit know-how':'New know-how'?></h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="doc_save"><input type="hidden" name="id" value="<?=$id?>"><div class="mb-3"><label class="form-label" for="document-title">Title</label><input class="form-control" name="title" id="document-title" maxlength="200" value="<?=h($doc['title'] ?? '')?>" required></div><div class="mb-3"><label class="form-label" for="document-content">Content</label><textarea class="form-control" name="content" id="document-content" rows="14" placeholder="The process, rule or know-how..."><?=h($doc['content'] ?? '')?></textarea><div class="form-text">Plain text; line breaks are kept. Leave empty for an attachment-only entry.</div></div><button class="btn btn-primary">Save know-how</button> <a class="btn btn-outline-secondary" href="<?=$id?'?page=document&id='.$id:'?page=documents'?>">Cancel</a></form></div></div></div>
    <?php render_footer(); exit;
}

// ---- Know-hows list (v2 since #83, admin refactor step 6) ----------------------------------
// The know-hows list, rebuilt on the shared CRUD list engine (src/crud.php):
// the count/page/fetch query, the URL builder, the table skeleton and the
// pagination window come from the abstraction; the entity-specific parts (the
// search WHERE, the excerpt/creator/comment/attachment cells, the "+ New
// know-how" entry) stay here as small column definitions. The list keeps its
// classic feature set: the same search with the |, %, _ escaping discipline,
// plain (non-sortable) headers and the fixed recency order - the sort
// allowlist entry carries a fixed DESC fragment without the {dir} placeholder,
// so a crafted ?sort=/&dir= request changes nothing, exactly like before.
// The parity proof is tests/E2E/CrudAbstractionTest.php: it exercises this
// page feature by feature and pins the no-refresh contract (this list has no
// realtime region). The navbar, the back links and the save/delete redirects
// point at ?page=documents, the canonical name of the know-how list.
if ($page === 'documents') {
    $listPage = 'documents';
    $conds=[]; $params=[];
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        // The same |, %, _ escaping the task search uses, so a search for a
        // literal % or _ cannot turn into a wildcard scan.
        $term = str_replace(['|','%','_'],['||','|%','|_'],$q);
        $conds[] = "(d.title LIKE ? ESCAPE '|' OR d.content LIKE ? ESCAPE '|')";
        $params[] = '%'.$term.'%'; $params[] = '%'.$term.'%';
    }
    $list=tb_crud_list([
        'pdo'=>$pdo,
        'table'=>'documents d LEFT JOIN users u ON u.id=d.created_by',
        'select'=>'d.id,d.title,d.content,d.updated_at,u.display_name creator,u.last_seen_at creator_last_seen,(SELECT COUNT(*) FROM document_comments dc WHERE dc.document_id=d.id) comments,(SELECT COUNT(*) FROM document_attachments da WHERE da.document_id=d.id) attachments',
        'where'=>$conds,
        'params'=>$params,
        'sortColumns'=>['updated'=>'d.updated_at DESC'],
        'defaultSort'=>'updated',
        'defaultDir'=>'desc',
        'orderBySuffix'=>'d.id DESC',
    ]);
    $documents=$list['rows'];$totalDocs=$list['total'];
    $docsUrl=tb_crud_url_builder($listPage,['q']);
    $documentColumns=[
        ['header'=>'<th class="ps-3">Title</th>','cell'=>fn(array $d): string => '<td class="ps-3"><a class="fw-semibold text-decoration-none" href="?page=document&id='.$d['id'].'">'.h($d['title']).'</a><div class="small muted">'.h(tb_excerpt((string)$d['content'])).'</div></td>'],
        ['label'=>'Updated','cell'=>fn(array $d): string => '<td class="small muted">'.h(tb_ago($d['updated_at'])).'</td>'],
        ['label'=>'Created by','cell'=>fn(array $d): string => '<td>'.tb_user_with_activity(['display_name'=>$d['creator'],'last_seen_at'=>$d['creator_last_seen']??null]).'</td>'],
        ['label'=>'Comments','cell'=>fn(array $d): string => '<td>'.h((string)$d['comments']).'</td>'],
        ['label'=>'Attachments','cell'=>fn(array $d): string => '<td>'.h((string)$d['attachments']).'</td>'],
    ];
    $emptyText = $q!=='' ? 'No know-hows match the search.' : 'No know-hows yet. Write the first one.';
    render_header('Know-hows',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Know-hows</h1><div class="muted"><?=$totalDocs?> document<?=$totalDocs===1?'':'s'?> · standard processes, rules and everything worth writing down</div></div><a class="btn btn-primary" href="?page=document_new">+ New know-how</a></div>
    <form method="get" class="d-flex gap-2 mb-3"><input type="hidden" name="page" value="<?=$listPage?>"><input class="form-control" type="search" name="q" value="<?=h($q)?>" placeholder="Search title and content..." aria-label="Search know-hows"><button class="btn btn-outline-secondary">Search</button><?php if($q!==''): ?><a class="btn btn-link" href="<?=h($docsUrl())?>&amp;clear_search=1">Clear</a><?php endif; ?></form>
    <div class="card"><?=tb_crud_table(['columns'=>$documentColumns,'rows'=>$documents,'emptyText'=>$emptyText,'emptyColspan'=>5])?></div>
    <?=tb_crud_pagination(['ariaLabel'=>'Know-how list pages','page'=>$list['page'],'pages'=>$list['pages'],'url'=>$docsUrl])?>
    <?php render_footer(); exit;
}

if ($page==='document') {
    $id=(int)($_GET['id'] ?? 0);
    $s=$pdo->prepare('SELECT d.*, u.display_name creator, u.last_seen_at creator_last_seen, u.logged_out creator_logged_out FROM documents d LEFT JOIN users u ON u.id=d.created_by WHERE d.id=?'); $s->execute([$id]); $doc=$s->fetch();
    if(!$doc){ http_response_code(404); exit('Document not found.'); }
    $cs=$pdo->prepare('SELECT dc.*, u.display_name author, u.last_seen_at author_last_seen, u.logged_out author_logged_out FROM document_comments dc LEFT JOIN users u ON u.id=dc.user_id WHERE dc.document_id=? ORDER BY dc.created_at, dc.id'); $cs->execute([$id]); $docComments=$cs->fetchAll();
    $as=$pdo->prepare('SELECT da.*, u.display_name uploader, u.last_seen_at uploader_last_seen, u.logged_out uploader_logged_out FROM document_attachments da LEFT JOIN users u ON u.id=da.user_id WHERE da.document_id=? ORDER BY da.created_at, da.id'); $as->execute([$id]); $docAttachments=$as->fetchAll();
    render_header('Know-how',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0"><?=h($doc['title'])?></h1><div class="muted">Created by <?=tb_user_with_activity(['display_name'=>$doc['creator'],'last_seen_at'=>$doc['creator_last_seen']??null])?> · <?=h(tb_ago($doc['created_at']))?> · Updated <?=h(tb_ago($doc['updated_at']))?></div></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="?page=documents">All know-hows</a><?php if(document_can_edit($doc)): ?><a class="btn btn-outline-primary" href="?page=document_edit&id=<?=$doc['id']?>">Edit</a><form method="post" class="d-inline" onsubmit="return confirm('Delete this know-how permanently? Its comments and attachments go with it.')"><?=csrf_field()?><input type="hidden" name="action" value="doc_delete"><input type="hidden" name="id" value="<?=$doc['id']?>"><button class="btn btn-outline-danger">Delete</button></form><?php endif; ?></div></div>
    <div class="card p-4 mb-4"><div class="prewrap"><?php $docContent=(string)($doc['content'] ?? ''); if(trim($docContent)===''): ?><span class="muted">No content - see the attachments below.</span><?php else: ?><?=h($docContent)?><?php endif; ?></div></div>
    <div class="card p-4 mb-4"><h2 class="h5">Attachments</h2><?php foreach($docAttachments as $a): ?><div class="d-flex justify-content-between border-bottom py-2"><a href="?page=doc_download&id=<?=$a['id']?>"><?=h($a['original_name'])?></a><span class="small muted"><?=tb_user_with_activity(['display_name'=>$a['uploader'],'last_seen_at'=>$a['uploader_last_seen']??null])?> · <?=number_format($a['file_size']/1024,1)?> KB · <?=h(tb_ago($a['created_at']))?></span><?php if(is_admin()||(int)$a['user_id']===(int)user()['id']): ?><form method="post" class="d-inline" onsubmit="return confirm('Delete this attachment permanently?')"><?=csrf_field()?><input type="hidden" name="action" value="doc_delete_attachment"><input type="hidden" name="attachment_id" value="<?=$a['id']?>"><input type="hidden" name="document_id" value="<?=$doc['id']?>"><button class="btn btn-sm btn-link text-danger p-0" title="Delete this attachment">delete</button></form><?php endif; ?></div><?php endforeach; if(!$docAttachments): ?><div class="small muted">No attachments yet.</div><?php endif; ?><form method="post" enctype="multipart/form-data" class="mt-3"><?=csrf_field()?><input type="hidden" name="action" value="doc_upload"><input type="hidden" name="document_id" value="<?=$doc['id']?>"><div class="input-group"><input class="form-control" type="file" name="file" required><button class="btn btn-outline-primary">Upload</button></div><div class="form-text">Max <?=round($config['max_upload_bytes']/1024/1024)?> MB. Allowed: common images, PDF, TXT, ZIP, DOCX and XLSX.</div></form></div>
    <div class="card p-4 mb-4" id="comments"><h2 class="h5">Comments</h2><?php foreach($docComments as $c): ?><div class="border-bottom py-3"><strong><?=tb_user_with_activity(['display_name'=>$c['author'],'last_seen_at'=>$c['author_last_seen']??null])?></strong> <span class="small muted"><?=h(tb_ago($c['created_at']))?></span><div class="prewrap mt-1"><?=h($c['body'])?></div></div><?php endforeach; if(!$docComments): ?><div class="small muted">No comments yet.</div><?php endif; ?><form method="post" class="mt-3"><?=csrf_field()?><input type="hidden" name="action" value="doc_comment"><input type="hidden" name="document_id" value="<?=$doc['id']?>"><textarea class="form-control mb-2" name="body" rows="3" placeholder="Write a comment..." required></textarea><button class="btn btn-primary">Add comment</button></form></div>
    <?php render_footer(); exit;
}

// ---- Kanban board (#43) -------------------------------------------------------------------
// A second view of the same tasks: one column per status, every non-archived
// task listed in its status column. The filter card on top is the task list's
// own component (tb_render_task_filter_card) and the WHERE building is the
// task list's own (tb_task_filters_where), so both pages stay in lockstep —
// a filter or saved search behaves the same no matter which view opened it.
if ($page==='kanban') {
    $filters=tb_task_filters_where();
    $ksql='SELECT t.id,t.title,t.status_id,t.priority,t.parent_id,p.title parent_title,t.project_id,pr.name project_name,s.name status_name,s.color status_color FROM tasks t JOIN statuses s ON s.id=t.status_id LEFT JOIN tasks p ON p.id=t.parent_id LEFT JOIN projects pr ON pr.id=t.project_id WHERE '.implode(' AND ',$filters['where']).' ORDER BY s.sort_order, s.id, t.updated_at DESC, t.id DESC';
    $ks=$pdo->prepare($ksql);$ks->execute($filters['params']);$kanbanTasks=$ks->fetchAll();
    $kanbanAssignees=task_assignees_by_task($pdo, array_column($kanbanTasks,'id'));
    // Kanban toggle (#66): the per-user collapsed column list is a preference,
    // so a closed column stays closed across reloads and the list auto-refresh.
    // Closed columns (#97): the card drops the h-100 row stretch, so a closed
    // column renders as its header only - clearly distinct from an empty open
    // group, which keeps its card body with the "No tasks" placeholder.
    $kanbanCollapsed = array_map('intval', array_filter(explode(',', tb_user_pref_get($pdo, (int)user()['id'], 'kanban_collapsed_statuses'))));
    // One column per status in display order; tasks are grouped into it.
    // #105: the status filter is a selection of which groups stay visible -
    // when statuses are picked, only those columns render (the same ids the
    // WHERE clause filtered on, so the board and the task list can never
    // disagree); with no status filter every status column stays, as before.
    $kanbanSelected=$filters['statusIds'] ? array_flip($filters['statusIds']) : null;
    $kanbanColumns=[];
    foreach ($statuses as $st) {
        if ($kanbanSelected!==null && !isset($kanbanSelected[(int)$st['id']])) continue;
        $kanbanColumns[(int)$st['id']]=['status'=>$st,'tasks'=>[]];
    }
    // The collapsed flags are applied AFTER the columns are rebuilt - the
    // 66v2 merge computed them on the array right before this rebuild wiped
    // them, so a closed column never rendered closed (#65 CI fix run).
    foreach ($kanbanColumns as $kcid => $kcol) $kanbanColumns[$kcid]['collapsed'] = in_array($kcid, $kanbanCollapsed, true);
    foreach ($kanbanTasks as $kt) {
        $sid=(int)$kt['status_id'];
        if (!isset($kanbanColumns[$sid])) $kanbanColumns[$sid]=['status'=>['id'=>$sid,'name'=>$kt['status_name'],'color'=>$kt['status_color']??null],'tasks'=>[]];
        $kanbanColumns[$sid]['tasks'][]=$kt;
    }
    $kanbanTotal=count($kanbanTasks);
    $ssq=$pdo->prepare('SELECT id,name,query FROM saved_searches WHERE user_id=? ORDER BY name,id');
    $ssq->execute([user()['id']]);
    $savedSearches=$ssq->fetchAll();
    $kanbanUrl=function(array $override=[]): string {
        $qs=['page'=>'kanban'];
        foreach (['q','assignee','status','parent','project'] as $fk) {if (($fv=filter_query_value($fk))!==null) $qs[$fk]=$fv;}
        return '?' . http_build_query(array_merge($qs,$override));
    };
    render_header('Kanban board',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Kanban board</h1><div class="muted"><?=$kanbanTotal?> task<?=$kanbanTotal===1?'':'s'?> across <?=count($kanbanColumns)?> status column<?=count($kanbanColumns)===1?'':'s'?></div></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="?page=tasks">Task list</a><a class="btn btn-primary" href="?page=new">+ New task</a></div></div>
    <?php tb_render_task_filter_card(['page'=>'kanban','users'=>$users,'statuses'=>$statuses,'parents'=>$parents,'projectOptions'=>$projectOptions,'savedSearches'=>$savedSearches,'fAssignees'=>$filters['fAssignees'],'fStatuses'=>$filters['fStatuses'],'fParents'=>$filters['fParents'],'fProjects'=>$filters['fProjects'],'returnUrl'=>$kanbanUrl()]); ?>
    <div id="tb-list-region" data-tb-refresh="10"><div class="row g-3 row-cols-1 row-cols-md-2 row-cols-xl-3"><?php foreach($kanbanColumns as $col): $st=$col['status']; ?><div class="col"><div class="card<?=$col['collapsed']??false?'':' h-100'?>"><div class="card-header bg-transparent d-flex justify-content-between align-items-center py-2"><div class="d-flex align-items-center gap-2"><?=tb_status_badge((string)$st['name'], $st['color'] ?? null)?><span class="badge text-bg-light border"><?=count($col['tasks'])?></span></div><form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="action" value="toggle_kanban_column"><input type="hidden" name="status_id" value="<?=$st['id']?>"><button class="btn btn-sm btn-outline-secondary py-0 px-2" aria-label="<?=$col['collapsed']??false?'Expand column':'Collapse column'?>" title="<?=$col['collapsed']??false?'Expand column':'Collapse column'?>"><?=$col['collapsed']??false?'+':'&minus;'?></button></form></div><div class="card-body p-2<?=$col['collapsed']??false?' d-none':''?>"><?php if(!($col['collapsed']??false)): ?><?php foreach($col['tasks'] as $kt): ?><div class="border rounded p-2 mb-2"><div class="d-flex justify-content-between align-items-start gap-2"><a class="fw-semibold text-decoration-none" href="?page=task&id=<?=$kt['id']?>">#<?=$kt['id']?> · <?=h($kt['title'])?></a><span class="badge text-bg-light border" title="Priority">&times;<?=h((string)max(1,min(1000,(int)($kt['priority'] ?? 1))))?></span></div><div class="small muted mt-1"><?php $ka=[];foreach($kanbanAssignees[(int)$kt['id']]??[] as $au){$ka[]=tb_user_with_activity($au);}echo $ka?implode(', ',$ka):'Unassigned'; if($kt['project_id']): ?> · <a href="?page=project&id=<?=$kt['project_id']?>">#<?=$kt['project_id']?> · <?=h($kt['project_name'])?></a><?php endif; if($kt['parent_id']): ?> · parent <a href="?page=task&id=<?=$kt['parent_id']?>">#<?=$kt['parent_id']?></a><?php endif; ?></div></div><?php endforeach; endif; if(!$col['tasks']): ?><div class="text-center muted small py-3">No tasks</div><?php endif; ?></div></div></div><?php endforeach; ?></div></div>
    <p class="small muted mt-3">Same filters and saved searches as the task list — the two views share one implementation. Archived tasks only show up in the archive.</p>
    <?php tb_render_list_refresh_script(); ?>
    <?php render_footer(); exit;
}

// ---- Dependency waterflow chart (#44) ------------------------------------------------------
// A third view of the tasks: every non-archived task as a block whose width
// follows its complexity estimation, arranged in dependency levels - level 0
// holds the tasks nothing in the chart depends on, level N the tasks whose
// prerequisites all sit in earlier levels ("depends on" links; edges to
// archived or filtered-out tasks cannot block). The filter card is the task
// list's own shared component, like on the kanban board.
if ($page==='waterflow') {
    $filters=tb_task_filters_where();
    $wsql='SELECT t.id,t.title,t.status_id,t.complexity,t.parent_id,p.title parent_title,t.project_id,pr.name project_name,s.name status_name,s.color status_color FROM tasks t JOIN statuses s ON s.id=t.status_id LEFT JOIN tasks p ON p.id=t.parent_id LEFT JOIN projects pr ON pr.id=t.project_id WHERE '.implode(' AND ',$filters['where']).' ORDER BY t.id DESC';
    $ws=$pdo->prepare($wsql);$ws->execute($filters['params']);$waterTasks=$ws->fetchAll();
    // Dependency edges between tasks that are both inside the chart: who
    // depends on whom ("depends on" links; "blocks" links are their inverse).
    $waterIds=array_map('intval', array_column($waterTasks,'id'));
    $waterDeps=[];
    if ($waterIds) {
        $ph=tb_in_placeholders(count($waterIds));
        $es=$pdo->prepare("SELECT l.task_id, l.related_task_id FROM task_links l JOIN tasks lf ON lf.id=l.task_id JOIN tasks lt ON lt.id=l.related_task_id WHERE l.link_type='depends_on' AND lf.archived_at IS NULL AND lt.archived_at IS NULL AND l.task_id IN ($ph) AND l.related_task_id IN ($ph)");
        $es->execute(array_merge($waterIds,$waterIds));
        foreach ($es->fetchAll() as $edge) {
            $waterDeps[(int)$edge['task_id']][]=(int)$edge['related_task_id'];
        }
    }
    $waterLevels=tb_waterflow_levels($waterIds,$waterDeps);
    // Group the rows by level and compute the width share of every block.
    $waterRows=[];
    foreach ($waterTasks as $wt) {
        $waterRows[(int)($waterLevels[(int)$wt['id']] ?? 0)][]=$wt;
    }
    ksort($waterRows);
    $maxComplexity=1;
    foreach ($waterTasks as $wt) $maxComplexity=max($maxComplexity,(int)($wt['complexity'] ?? 1));
    $waterTotal=count($waterTasks);
    $ssq=$pdo->prepare('SELECT id,name,query FROM saved_searches WHERE user_id=? ORDER BY name,id');
    $ssq->execute([user()['id']]);
    $savedSearches=$ssq->fetchAll();
    $waterUrl=function(array $override=[]): string {
        $qs=['page'=>'waterflow'];
        foreach (['q','assignee','status','parent','project'] as $fk) {if (($fv=filter_query_value($fk))!==null) $qs[$fk]=$fv;}
        return '?' . http_build_query(array_merge($qs,$override));
    };
    render_header('Dependency waterflow',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Dependency waterflow</h1><div class="muted"><?=$waterTotal?> task<?=$waterTotal===1?'':'s'?> flowing through <?=count($waterRows)?> level<?=count($waterRows)===1?'':'s'?> · widest block = the largest complexity in view (<?=$maxComplexity?>)</div></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="?page=tasks">Task list</a><a class="btn btn-primary" href="?page=new">+ New task</a></div></div>
    <?php tb_render_task_filter_card(['page'=>'waterflow','users'=>$users,'statuses'=>$statuses,'parents'=>$parents,'projectOptions'=>$projectOptions,'savedSearches'=>$savedSearches,'fAssignees'=>$filters['fAssignees'],'fStatuses'=>$filters['fStatuses'],'fParents'=>$filters['fParents'],'fProjects'=>$filters['fProjects'],'returnUrl'=>$waterUrl()]); ?>
<div id="tb-list-region" data-tb-refresh="10"><?php if(!$waterRows): ?><div class="card p-4 text-center muted">No tasks match the current filters.</div><?php endif; ?>
    <div class="card p-3 mb-2"><?php foreach($waterRows as $level=>$rows): ?><div class="border-bottom py-2" data-level="<?=$level?>"><div class="d-flex align-items-center gap-2 mb-2"><span class="badge text-bg-secondary">Level <?=$level?></span><span class="small muted"><?=count($rows)?> task<?=count($rows)===1?'':'s'?></span></div><div class="d-flex flex-wrap gap-2"><?php foreach($rows as $wt): $w=max(10,(int)round(((int)($wt['complexity'] ?? 1))/$maxComplexity*100)); ?><div class="border rounded p-2 waterflow-task" style="width:<?=$w?>%;min-width:14rem"><div class="d-flex justify-content-between align-items-center gap-2"><a class="fw-semibold text-decoration-none" href="?page=task&id=<?=$wt['id']?>">#<?=$wt['id']?> · <?=h($wt['title'])?></a><span class="badge text-bg-light border" title="Complexity">&times;<?=h((string)max(1,min(1000,(int)($wt['complexity'] ?? 1))))?></span></div><div class="small muted mt-1"><?=tb_status_badge((string)$wt['status_name'], $wt['status_color'] ?? null)?><?php if($wt['project_id']): ?> · <a href="?page=project&id=<?=$wt['project_id']?>"><?=h($wt['project_name'])?></a><?php endif; if($wt['parent_id']): ?> · parent <a href="?page=task&id=<?=$wt['parent_id']?>">#<?=$wt['parent_id']?></a><?php endif; ?></div></div><?php endforeach; ?></div></div><?php endforeach; ?></div></div>
    <p class="small muted">Every block's width follows the task's complexity estimation (set on the task form, default 1) relative to the widest task in view. Tasks flow from level 0 downwards: a task sits on the first level where all its &quot;Depends on&quot; prerequisites are already placed; dependencies on archived or filtered-out tasks do not block. Tasks in a dependency cycle are parked below the flow.</p>
    <?php tb_render_list_refresh_script(); ?>
    <?php render_footer(); exit;
}

// ---- Task list (on the CRUD engine since #57) -------------------------------
// The task list, rebuilt on the shared CRUD list engine (src/crud.php): the
// count/page/fetch query, the URL builder, the sortable table skeleton, the
// pagination window and the bulk-select wiring come from the abstraction;
// the task-specific parts (the filter WHERE, the assignee map, the row
// cells, the per-row archive forms) stay here as small closures. The admin's
// step 4 parity proof is tests/E2E/CrudAbstractionTest.php: it pinned this
// page feature by feature against the old task list contract (the rendered
// v1 and v2 lists were pinned byte-equal before the links switched). This
// block stays the fall-through end of the page router, so ?page=tasks (and
// any unknown page, as before) renders the task list - the canonical name
// the links point at.
$listPage = 'tasks';
$filters=tb_task_filters_where();
    $where=$filters['where'];$params=$filters['params'];
    $fAssignees=$filters['fAssignees'];$fStatuses=$filters['fStatuses'];$fParents=$filters['fParents'];$fProjects=$filters['fProjects'];
    $projectIds=$filters['projectIds'];
    $sortColumns=[
        'id'=>'t.id {dir}',
        'title'=>'t.title {dir}',
        'status'=>'s.sort_order {dir}, s.name {dir}',
        'assignees'=>'(SELECT MIN(a2u.display_name) FROM task_assignees a2 JOIN users a2u ON a2u.id=a2.user_id WHERE a2.task_id=t.id) {dir}',
        'parent'=>'t.parent_id {dir}',
        'project'=>'pr.name {dir}',
        'priority'=>'t.priority {dir}',
        'updated'=>'t.updated_at {dir}',
    ];
    $list=tb_crud_list([
        'pdo'=>$pdo,
        'table'=>'tasks t JOIN statuses s ON s.id=t.status_id LEFT JOIN tasks p ON p.id=t.parent_id LEFT JOIN projects pr ON pr.id=t.project_id',
        'select'=>'t.id,t.title,t.updated_at,t.created_by,t.parent_id,p.title parent_title,s.name status_name,s.color status_color,t.priority,t.project_id,pr.name project_name',
        'where'=>$where,
        'params'=>$params,
        'sortColumns'=>$sortColumns,
        'defaultSort'=>'updated',
        'defaultDir'=>'desc',
        'orderBySuffix'=>'t.id {dir}',
    ]);
    $tasks=$list['rows'];$sort=$list['sort'];$totalTasks=$list['total'];$totalPages=$list['pages'];$taskPage=$list['page'];
    $taskAssignees=task_assignees_by_task($pdo, array_column($tasks,'id'));
    $listUrl=tb_crud_url_builder($listPage,['q','assignee','status','parent','project','sort','dir']);
    // Header badge for the project filter: resolve every selected project id to
    // its name so the badge line can show more than one (#25).
    $projectFilterNames=[];
    if ($projectIds) {
        $pfq=$pdo->prepare('SELECT id,name FROM projects WHERE id IN ('.tb_in_placeholders(count($projectIds)).')');
        $pfq->execute($projectIds);
        $projectNamesById=array_column($pfq->fetchAll(),'name','id');
        foreach ($projectIds as $pid) {if (isset($projectNamesById[$pid])) $projectFilterNames[]=$projectNamesById[$pid];}
    }
    // Saved searches (#29): the signed-in user's own stored list-view search
    // links, shown in the dropdown under the filter form.
    $ssq=$pdo->prepare('SELECT id,name,query FROM saved_searches WHERE user_id=? ORDER BY name,id');
    $ssq->execute([user()['id']]);
    $savedSearches=$ssq->fetchAll();
    $taskColumns=[
        ['header'=>'<th class="ps-3" style="width:36px"><input type="checkbox" class="form-check-input" id="check-all" aria-label="Select all tasks on this page"></th>','cell'=>fn(array $t): string => '<td class="ps-3"><input type="checkbox" class="form-check-input row-check" name="task_ids[]" value="'.$t['id'].'" aria-label="Select task #'.$t['id'].'"></td>'],
        ['label'=>'Task','sortKey'=>'id','sort'=>$sort,'url'=>$listUrl,'cell'=>fn(array $t): string => '<td><a class="fw-semibold text-decoration-none" href="?page=task&id='.$t['id'].'">#'.$t['id'].' · '.h($t['title']).'</a></td>'],
        ['label'=>'Status','sortKey'=>'status','sort'=>$sort,'url'=>$listUrl,'cell'=>fn(array $t): string => '<td>'.tb_status_badge((string)$t['status_name'], $t['status_color'] ?? null).'</td>'],
        ['label'=>'Assignees','sortKey'=>'assignees','sort'=>$sort,'url'=>$listUrl,'cell'=>function(array $t) use ($taskAssignees): string {
            $tuHtml=[];foreach($taskAssignees[(int)$t['id']]??[] as $tu){$tuHtml[]=tb_user_with_activity($tu);}
            return '<td>'.(implode(', ',$tuHtml)?:'—').'</td>';
        }],
        ['label'=>'Parent','sortKey'=>'parent','sort'=>$sort,'url'=>$listUrl,'cell'=>fn(array $t): string => '<td>'.($t['parent_id'] ? '<a class="text-decoration-none" href="?page=task&id='.$t['parent_id'].'">#'.$t['parent_id'].(($t['parent_title']??'')!==''&&$t['parent_title']!==null?' · '.h($t['parent_title']):'').'</a>' : '<span class="muted">—</span>').'</td>'],
        ['label'=>'Project','sortKey'=>'project','sort'=>$sort,'url'=>$listUrl,'cell'=>fn(array $t): string => '<td>'.($t['project_id'] ? '<a class="text-decoration-none" href="?page=project&id='.$t['project_id'].'">#'.$t['project_id'].' · '.h($t['project_name']).'</a>' : '<span class="muted">—</span>').'</td>'],
        ['label'=>'Priority','sortKey'=>'priority','sort'=>$sort,'url'=>$listUrl,'cell'=>fn(array $t): string => '<td><span class="badge text-bg-light border">&times;'.h((string)max(1,min(1000,(int)($t['priority'] ?? 1)))).'</span></td>'],
        ['label'=>'Updated','sortKey'=>'updated','sort'=>$sort,'url'=>$listUrl,'cell'=>fn(array $t): string => '<td class="small muted">'.h(tb_ago($t['updated_at'])).'</td>'],
        ['label'=>'Actions','cell'=>function(array $t): string {
            $canArchive=is_admin()||(int)$t['created_by']===(int)user()['id'];
            return '<td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?page=edit&id='.$t['id'].'">Edit</a>'.($canArchive ? ' <button type="submit" class="btn btn-sm btn-outline-danger" form="archive-'.$t['id'].'">Archive</button>' : '').'</td>';
        }],
    ];
    // The bulk controls live right of the pagination window in the shared
    // footer (tb_crud_pagination takes them as its trailing content).
    ob_start(); ?>
<div class="d-flex align-items-center gap-2 flex-wrap ms-auto"><select class="form-select" name="project_id" id="bulk-move-project" aria-label="Target project for the selected tasks"><option value="">Choose project&hellip;</option><?php foreach($projectOptions as $po): ?><option value="<?=$po['id']?>">#<?=$po['id']?> &middot; <?=h($po['name'])?></option><?php endforeach; ?></select><button type="submit" class="btn btn-outline-primary" name="action" value="bulk_move_tasks" id="bulk-move-btn" disabled data-confirm="Move all selected tasks to the chosen project?">Move to project</button><button type="submit" class="btn btn-outline-danger" name="action" value="bulk_archive_tasks" id="bulk-delete-btn" disabled data-confirm="Archive all selected tasks? They can be restored from the archive.">Archive selected (<span id="bulk-count">0</span>)</button></div>
    <?php $bulkControls=trim(ob_get_clean());
    render_header('Tasks',$config,$error); ?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Tasks</h1><div class="muted"><?=$totalTasks?> task<?=$totalTasks===1?'':'s'?></div><?php if($projectFilterNames): ?><div class="mt-1"><span class="badge text-bg-info">Project<?=count($projectFilterNames)===1?'':'s'?>: <?=h(implode(', ',$projectFilterNames))?></span> <a class="small" href="<?=h($listUrl(['project'=>null]))?>">Show all tasks</a></div><?php endif; ?></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="?page=archive">Archive</a><a class="btn btn-primary" href="?page=new">+ New task</a></div></div>
    <?php tb_render_task_filter_card(['page'=>$listPage,'users'=>$users,'statuses'=>$statuses,'parents'=>$parents,'projectOptions'=>$projectOptions,'savedSearches'=>$savedSearches,'fAssignees'=>$fAssignees,'fStatuses'=>$fStatuses,'fParents'=>$fParents,'fProjects'=>$fProjects,'returnUrl'=>$listUrl()]); ?>
<form method="post" id="bulk-form" onsubmit="return confirm((event.submitter&&event.submitter.dataset.confirm)||'Apply this bulk action to all selected tasks?')"><?=csrf_field()?><input type="hidden" name="return" value="<?=h($listUrl(['p'=>$taskPage]))?>"><?php foreach (['q','assignee','status','parent','project','sort','dir'] as $fk): foreach (filter_values($fk) as $fv): ?><input type="hidden" name="<?=$fk?>[]" value="<?=h($fv)?>"><?php endforeach; endforeach; ?>
<div id="tb-list-region" data-tb-refresh="10"><div class="card"><?=tb_crud_table(['columns'=>$taskColumns,'rows'=>$tasks,'emptyText'=>'No tasks found.','emptyColspan'=>9])?></div>
<?=tb_crud_pagination(['ariaLabel'=>'Task list pages','page'=>$taskPage,'pages'=>$totalPages,'url'=>$listUrl,'trailing'=>$bulkControls])?></div>
</form>
<?php foreach($tasks as $t): if(is_admin()||(int)$t['created_by']===(int)user()['id']): ?><form method="post" id="archive-<?=$t['id']?>" class="d-none" onsubmit="return confirm('Archive task #<?=$t['id']?>? It can be restored from the archive.')"><?=csrf_field()?><input type="hidden" name="action" value="archive_task"><input type="hidden" name="id" value="<?=$t['id']?>"><input type="hidden" name="return" value="<?=h($listUrl(['p'=>$taskPage]))?>"></form><?php endif; endforeach; ?>
<?=tb_crud_bulk_select_script(['selectAllId'=>'check-all','rowClass'=>'row-check','bulkButtonId'=>'bulk-delete-btn','countId'=>'bulk-count','moveButtonId'=>'bulk-move-btn','moveSelectId'=>'bulk-move-project'])?>
<?php tb_render_list_refresh_script(); ?>
<?php render_footer(); exit;
