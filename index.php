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
if (!is_file(__DIR__ . '/src/helpers.php')) {
    http_response_code(500);
    exit('Application files are incomplete: src/helpers.php was not found on the server. Upload the full repository, including the src/ directory, and try again.');
}
require __DIR__ . '/src/helpers.php';
ensure_users_theme_column($pdo);

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

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
verify_csrf();

$page = $_GET['page'] ?? 'tasks';
$error = '';

// Sign-out is POST-only and CSRF-protected, so a crafted <img src="?page=logout">
// link or other cross-site GET request can no longer force users to log out.
if ($page === 'logout') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $_SESSION['user'] = ['id' => (int)$u['id'], 'username' => $u['username'], 'display_name' => $u['display_name'], 'role' => $u['role'], 'theme' => normalize_theme($u['theme'] ?? null)];
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
            $s = $pdo->prepare("INSERT INTO users(username,password_hash,display_name,role) VALUES(?,?,?,'admin')");
            $s->execute([trim($_POST['username']), password_hash($_POST['password'], PASSWORD_DEFAULT), trim($_POST['display_name'])]);
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
                if ($title === '') throw new RuntimeException('Task title is required.');
                if (tb_char_length($title) > 200) throw new RuntimeException('Task title must be 200 characters or fewer.');
                if ($parentId && ($parentId === $id || !task_exists($pdo, $parentId))) throw new RuntimeException('Invalid parent task.');
                if (!status_exists($pdo, $statusId)) throw new RuntimeException('Choose a valid status.');
                $projectId = (int)($_POST['project_id'] ?? 0) ?: null;
                if ($projectId && !project_exists($pdo, $projectId)) throw new RuntimeException('Invalid project.');
                $assignees = array_values(array_unique(array_map('intval', $_POST['assignees'] ?? [])));
                if ($id) {
                    $old = find_task($pdo, $id);
                    if (!$old) throw new RuntimeException('Task not found.');
                    $pdo->prepare('UPDATE tasks SET title=?,description=?,status_id=?,parent_id=?,schedule_period=?,project_id=? WHERE id=?')->execute([$title,$description,$statusId,$parentId,$schedulePeriod,$projectId,$id]);
                    $pdo->prepare('DELETE FROM task_assignees WHERE task_id=?')->execute([$id]);
                    history($pdo, $id, 'Task updated', 'Title/status/description/parent/schedule or assignees may have changed.');
                } else {
                    $pdo->prepare('INSERT INTO tasks(title,description,status_id,parent_id,schedule_period,project_id,created_by) VALUES(?,?,?,?,?,?,?)')->execute([$title,$description,$statusId,$parentId,$schedulePeriod,$projectId,user()['id']]);
                    $id = (int)$pdo->lastInsertId();
                    history($pdo, $id, 'Task created', $title);
                }
                $a = $pdo->prepare('INSERT IGNORE INTO task_assignees(task_id,user_id) VALUES(?,?)');
                foreach ($assignees as $uid) $a->execute([$id,$uid]);
                redirect('?page=task&id=' . $id);
            }
            if ($action === 'archive_task') {
                // Soft delete (#12): the task leaves the lists but stays restorable.
                $id = (int)$_POST['id'];
                $t = find_task($pdo, $id);
                if (!$t) throw new RuntimeException('Task not found.');
                if (!is_admin() && (int)$t['created_by'] !== (int)user()['id']) throw new RuntimeException('Only an administrator or task creator can archive this task.');
                $pdo->prepare('UPDATE tasks SET archived_at=CURRENT_TIMESTAMP WHERE id=? AND archived_at IS NULL')->execute([$id]);
                history($pdo, $id, 'Task archived', 'The task was moved to the archive. Restore it from the archive page.');
                flash('Task archived. Find it under Archive.');
                $returnUrl = $_POST['return'] ?? '';
                redirect(is_string($returnUrl) && str_starts_with($returnUrl, '?page=tasks') ? $returnUrl : '?page=task&id=' . $id);
            }
            if ($action === 'restore_task') {
                $id = (int)$_POST['id'];
                $pdo->prepare('UPDATE tasks SET archived_at=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=? AND archived_at IS NOT NULL')->execute([$id]);
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
                $pdo->prepare('DELETE FROM tasks WHERE id=?')->execute([$id]);
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
                    'UPDATE tasks SET archived_at=CURRENT_TIMESTAMP WHERE id=? AND archived_at IS NULL'
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
                    $pdo->prepare('INSERT INTO templates(type,name,description,created_by) VALUES(?,?,?,?)')->execute([$type,$name,$description,user()['id']]);
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
            if ($action === 'comment') {
                $id = (int)$_POST['task_id']; $body = trim($_POST['body'] ?? '');
                if (!task_exists($pdo,$id) || $body === '') throw new RuntimeException('Comment cannot be empty.');
                $pdo->prepare('INSERT INTO comments(task_id,user_id,body) VALUES(?,?,?)')->execute([$id,user()['id'],$body]);
                history($pdo,$id,'Comment added','A comment was added.'); redirect('?page=task&id='.$id.'#comments');
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
                $allowed=['image/jpeg','image/png','image/gif','application/pdf','text/plain','application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                if (!in_array($mime,$allowed,true)) throw new RuntimeException('This file type is not allowed.');
                $dir=__DIR__.'/uploads'; if (!is_dir($dir)) mkdir($dir,0750,true);
                $stored=bin2hex(random_bytes(20));
                if (!move_uploaded_file($file['tmp_name'],$dir.'/'.$stored)) throw new RuntimeException('Could not save uploaded file.');
                $pdo->prepare('INSERT INTO attachments(task_id,user_id,stored_name,original_name,mime_type,file_size) VALUES(?,?,?,?,?,?)')->execute([$id,user()['id'],$stored,basename($file['name']),$mime,$file['size']]);
                history($pdo,$id,'Attachment added',basename($file['name'])); redirect('?page=task&id='.$id);
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
                $pdo->prepare('INSERT INTO statuses(name,sort_order,is_done) VALUES(?,?,?)')->execute([$name,(int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM statuses')->fetchColumn(),isset($_POST['is_done'])?1:0]);
                flash('Status added.'); redirect('?page=admin');
            }
            if ($action === 'edit_status' && is_admin()) {
                $id=(int)($_POST['id'] ?? 0);
                $s=$pdo->prepare('SELECT id FROM statuses WHERE id=?'); $s->execute([$id]);
                if (!$s->fetch()) throw new RuntimeException('Status not found.');
                $name=trim($_POST['name'] ?? '');
                if ($e=status_validation_error($pdo,$name,$id)) throw new RuntimeException($e);
                $pdo->prepare('UPDATE statuses SET name=?,sort_order=?,is_done=? WHERE id=?')->execute([$name,(int)($_POST['sort_order'] ?? 0),isset($_POST['is_done'])?1:0,$id]);
                flash('Status updated.'); redirect('?page=admin');
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
                flash('Status deleted.'); redirect('?page=admin');
            }
            if ($action === 'add_user' && is_admin()) {
                $name=trim($_POST['username'] ?? ''); $display=trim($_POST['display_name'] ?? ''); $pass=$_POST['password'] ?? '';
                if ($name==='' || $display==='' || strlen($pass)<10) throw new RuntimeException('Enter username, display name and a password of at least 10 characters.');
                if (tb_char_length($name) > 80 || tb_char_length($display) > 120) throw new RuntimeException('Username is limited to 80 and display name to 120 characters.');
                $pdo->prepare('INSERT INTO users(username,password_hash,display_name,role) VALUES(?,?,?,?)')->execute([$name,password_hash($pass,PASSWORD_DEFAULT),$display,$_POST['role']==='admin'?'admin':'user']);
                flash('User created.'); redirect('?page=admin');
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
                    $_SESSION['user'] = ['id'=>$id,'username'=>$name,'display_name'=>$display,'role'=>$role];
                }
                flash('User updated.'); redirect('?page=admin');
            }
            if ($action === 'delete_user' && is_admin()) {
                $id=(int)($_POST['id'] ?? 0);
                if ($id===(int)user()['id']) throw new RuntimeException('You cannot delete your own account.');
                $s=$pdo->prepare('SELECT role FROM users WHERE id=?'); $s->execute([$id]); $target=$s->fetch();
                if (!$target) throw new RuntimeException('User not found.');
                if ($target['role']==='admin' && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn()<=1) throw new RuntimeException('At least one administrator must remain.');
                $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
                flash('User deleted. Their tasks, comments and attachments were kept.'); redirect('?page=admin');
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
                    $pdo->prepare('INSERT INTO projects(name,description,status_id,created_by) VALUES(?,?,?,?)')->execute([$name,$description,$statusId,user()['id']]);
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#f5f6f8}.card{border:0;box-shadow:0 1px 5px #0000000d}.muted{color:#687386}.prewrap{white-space:pre-wrap}.navbar-brand{font-weight:700}html[data-bs-theme="dark"] body{background:#14181f}html[data-bs-theme="dark"] .card{box-shadow:0 1px 6px #00000059}html[data-bs-theme="dark"] .muted{color:#8b96a5}</style></head><body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark"><div class="container"><a class="navbar-brand" href="?"><?=h($config['app_name'])?></a><?php if(user()): ?><div class="navbar-nav ms-auto align-items-center"><a class="nav-link" href="?">Tasks</a><a class="nav-link" href="?page=projects">Projects</a><a class="nav-link" href="?page=templates">Templates</a><?php if(is_admin()): ?><a class="nav-link" href="?page=admin">Administration</a><a class="nav-link" href="?page=updates">Software updates</a><?php endif; ?><form method="post" class="d-flex align-items-center mx-2"><?=csrf_field()?><input type="hidden" name="action" value="save_theme"><select name="theme" class="form-select form-select-sm bg-dark text-light border-secondary" aria-label="Theme" title="Theme" onchange="this.form.submit()"><option value="light" <?=$theme==='light'?'selected':''?>>Light</option><option value="dark" <?=$theme==='dark'?'selected':''?>>Dark</option></select><noscript><button class="btn btn-sm btn-outline-light ms-1">Save</button></noscript></form><span class="navbar-text mx-2"><?=h(user()['display_name'])?></span><form method="post" action="?page=logout" class="d-inline m-0"><?=csrf_field()?><button type="submit" class="btn btn-link nav-link p-0 text-white text-decoration-none">Sign out</button></form></div><?php endif; ?></div></nav><main class="container py-4">
    <?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success"><?=h($_SESSION['flash'])?></div><?php unset($_SESSION['flash']); endif; ?>
    <?php if($error): ?><div class="alert alert-danger"><?=h($error)?></div><?php endif;
}
function render_footer(): void { ?></main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html><?php }

// ---- Task scheduler (#18) -----------------------------------------------------------------
// Tasks may opt in to a recurring schedule. An external cron job calls ?page=cronjob
// every few minutes; whenever a scheduled task has not been updated for longer than its
// period, it is reset to TODO, unassigned, and an automatic comment is recorded.

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

/** SQL expression (per driver) that computes the database clock minus the period. */
function schedule_interval_sql(string $driver, string $period): ?string {
    switch ($period) {
        case 'hourly':   return $driver === 'mysql' ? 'NOW() - INTERVAL 1 HOUR'  : "datetime('now','-1 hour')";
        case 'daily':    return $driver === 'mysql' ? 'NOW() - INTERVAL 1 DAY'   : "datetime('now','-1 day')";
        case 'weekly':   return $driver === 'mysql' ? 'NOW() - INTERVAL 1 WEEK'  : "datetime('now','-7 days')";
        case 'biweekly': return $driver === 'mysql' ? 'NOW() - INTERVAL 2 WEEK'  : "datetime('now','-14 days')";
        case 'monthly':  return $driver === 'mysql' ? 'NOW() - INTERVAL 1 MONTH' : "datetime('now','-1 month')";
    }
    return null;
}

/** The id of the status used to reopen tasks: the "TODO" status, or the first non-completion one. */
function scheduler_todo_status_id(PDO $pdo): ?int {
    $s = $pdo->query("SELECT id FROM statuses WHERE name='TODO' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$s) $s = $pdo->query('SELECT id FROM statuses WHERE is_done=0 ORDER BY sort_order,id LIMIT 1')->fetchColumn();
    return $s ? (int)$s : null;
}

/**
 * Reopens every scheduled task that was not updated within its period.
 * Returns the ids of the reopened tasks.
 */
function reopen_due_tasks(PDO $pdo): array {
    $todoId = scheduler_todo_status_id($pdo);
    if ($todoId === null) return [];
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $reopened = [];
    foreach (array_keys(schedule_periods()) as $period) {
        $cutoff = schedule_interval_sql($driver, $period);
        if ($cutoff === null) continue;
        // $cutoff only ever comes from schedule_interval_sql(), never from user input.
        $find = $pdo->prepare('SELECT t.id, t.title, t.created_by, t.status_id, (SELECT COUNT(*) FROM task_assignees ax WHERE ax.task_id=t.id) assignee_count FROM tasks t WHERE t.schedule_period=? AND t.archived_at IS NULL AND t.updated_at < ' . $cutoff);
        $find->execute([$period]);
        foreach ($find->fetchAll() as $task) {
            // A task that is already TODO and has no assignees would not change:
            // leave it (and its comment stream) alone instead of pinging it every run.
            if ((int)$task['status_id'] === $todoId && (int)$task['assignee_count'] === 0) continue;
            $pdo->prepare('UPDATE tasks SET status_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$todoId, (int)$task['id']]);
            $pdo->prepare('DELETE FROM task_assignees WHERE task_id=?')->execute([(int)$task['id']]);
            $pdo->prepare('INSERT INTO comments(task_id,user_id,body) VALUES(?,?,?)')->execute([(int)$task['id'], (int)$task['created_by'], 'Reopen by auto-scheduler']);
            $pdo->prepare('INSERT INTO task_history(task_id,user_id,event,details) VALUES(?,?,?,?)')->execute([
                (int)$task['id'], (int)$task['created_by'], 'Reopened by auto-scheduler',
                'Not updated within the schedule period ('.$period.'). Status reset to TODO and assignees removed.',
            ]);
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

if ($page === 'updates') {
    if (!is_admin()) { http_response_code(403); exit('Forbidden.'); }
    require __DIR__ . '/updater.php';
    task_board_update_page($pdo, $config);
    exit;
}

// ---- Task archive (#12) -------------------------------------------------------------------
// Archived (soft-deleted) tasks: view, restore or delete them permanently.
if ($page==='archive') {
    $archivedTasks=$pdo->query('SELECT t.id,t.title,t.archived_at,t.updated_at,t.created_by,s.name status_name,u.display_name creator FROM tasks t JOIN statuses s ON s.id=t.status_id LEFT JOIN users u ON u.id=t.created_by WHERE t.archived_at IS NOT NULL ORDER BY t.archived_at DESC')->fetchAll();
    render_header('Archive',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Archive</h1><div class="muted"><?=count($archivedTasks)?> archived task<?=count($archivedTasks)===1?'':'s'?></div></div><a class="btn btn-outline-secondary" href="?">Back to tasks</a></div>
    <p class="small muted">Archived tasks are hidden from the task list but kept here. Restore brings a task back unchanged; Delete removes it and its comments, attachments, history and links permanently.</p>
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th class="ps-3">Task</th><th>Status</th><th>Creator</th><th>Archived</th><th>Actions</th></tr></thead><tbody><?php foreach($archivedTasks as $t): $canDelete=is_admin()||(int)$t['created_by']===(int)user()['id']; ?><tr><td class="ps-3"><a class="fw-semibold text-decoration-none" href="?page=task&id=<?=$t['id']?>">#<?=$t['id']?> · <?=h($t['title'])?></a></td><td><span class="badge text-bg-light border"><?=h($t['status_name'])?></span></td><td><?=h($t['creator'] ?: 'Deleted user')?></td><td class="small muted"><?=h($t['archived_at'])?></td><td class="text-nowrap"><form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="action" value="restore_task"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="btn btn-sm btn-outline-primary">Restore</button></form><?php if($canDelete): ?> <form method="post" class="d-inline" onsubmit="return confirm('Delete task #<?=$t['id']?> permanently? This cannot be undone.')"><?=csrf_field()?><input type="hidden" name="action" value="delete_task"><input type="hidden" name="id" value="<?=$t['id']?>"><input type="hidden" name="return" value="?page=archive"><button class="btn btn-sm btn-outline-danger">Delete</button></form><?php endif; ?></td></tr><?php endforeach; if(!$archivedTasks): ?><tr><td colspan="5" class="text-center muted py-4">The archive is empty.</td></tr><?php endif; ?></tbody></table></div></div>
    <?php render_footer(); exit;
}
// ---- End task archive ---------------------------------------------------------------------

if ($page==='admin' && is_admin()) {
    $users=$pdo->query('SELECT id,username,display_name,role,created_at FROM users ORDER BY display_name')->fetchAll();
    $statuses=all_statuses($pdo);
    render_header('Administration',$config,$error); ?>
    <div class="row g-4"><div class="col-lg-6"><div class="card p-4"><h2 class="h5">Create user</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="add_user"><div class="mb-2"><input class="form-control" name="username" placeholder="Username" required></div><div class="mb-2"><input class="form-control" name="display_name" placeholder="Display name" required></div><div class="mb-2"><input class="form-control" type="password" name="password" placeholder="Password (10+ characters)" minlength="10" required></div><div class="mb-3"><select class="form-select" name="role"><option value="user">User</option><option value="admin">Administrator</option></select></div><button class="btn btn-primary">Create user</button></form></div>
    <div class="card p-4 mt-4"><h2 class="h5">Users</h2><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Display name</th><th>Username</th><th>Role</th><th>Created</th><th>Actions</th></tr></thead><tbody><?php foreach($users as $u): ?><tr><td class="fw-semibold"><?=h($u['display_name'])?></td><td><?=h($u['username'])?></td><td><span class="badge text-bg-secondary"><?=h($u['role'])?></span></td><td class="small muted"><?=h($u['created_at'])?></td><td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?page=user&id=<?=$u['id']?>">Edit</a> <form method="post" class="d-inline" onsubmit="return confirm('Delete this user permanently? Their tasks, comments and attachments will be kept.')"><?=csrf_field()?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?=$u['id']?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div>
        <div class="col-lg-6"><div class="card p-4"><h2 class="h5">Task statuses</h2><p class="small muted">Rename statuses, change their display order, mark them as completion statuses or delete the ones no task uses.</p><ul class="list-group mb-3"><?php foreach($statuses as $st): ?><li class="list-group-item"><form method="post" class="row g-2 align-items-center"><?=csrf_field()?><input type="hidden" name="action" value="edit_status"><input type="hidden" name="id" value="<?=$st['id']?>"><div class="col-12 col-md-5"><label class="visually-hidden" for="status-name-<?=$st['id']?>">Status name</label><input class="form-control form-control-sm" name="name" id="status-name-<?=$st['id']?>" value="<?=h($st['name'])?>" maxlength="40" required></div><div class="col-4 col-md-2"><label class="visually-hidden" for="status-sort-<?=$st['id']?>">Sort order</label><input class="form-control form-control-sm" type="number" name="sort_order" id="status-sort-<?=$st['id']?>" value="<?=$st['sort_order']?>" title="Display order, lower numbers first"></div><div class="col-8 col-md-3 form-check ms-md-1 mb-0"><input class="form-check-input" type="checkbox" name="is_done" id="status-done-<?=$st['id']?>" <?=$st['is_done']?'checked':''?>><label class="form-check-label small" for="status-done-<?=$st['id']?>">Completed</label><?php if($st['is_done']): ?><span class="badge text-bg-success ms-1">Completion status</span><?php endif; ?></div><div class="col-12 col-md-2 d-flex gap-1 justify-content-md-end"><button class="btn btn-sm btn-outline-primary">Save</button><button type="submit" class="btn btn-sm btn-outline-danger" form="delete-status-<?=$st['id']?>">Delete</button></div></form><form method="post" id="delete-status-<?=$st['id']?>" class="d-none" onsubmit="return confirm('Delete this status?');"><?=csrf_field()?><input type="hidden" name="action" value="delete_status"><input type="hidden" name="id" value="<?=$st['id']?>"></form></li><?php endforeach; ?></ul><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="add_status"><div class="mb-2"><input class="form-control" name="name" placeholder="New status label" required></div><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_done" id="done"><label class="form-check-label" for="done">Treat as completed</label></div><button class="btn btn-primary">Add status</button></form><p class="small muted mt-3">Statuses are shown in display order. A status that tasks still use and the last remaining status cannot be deleted.</p></div></div></div>    <?php render_footer(); exit;
}

if ($page==='user' && is_admin()) {
    $id=(int)($_GET['id']??0);
    $s=$pdo->prepare('SELECT id,username,display_name,role,created_at FROM users WHERE id=?'); $s->execute([$id]); $u=$s->fetch();
    if(!$u){ http_response_code(404); exit('User not found.'); }
    render_header('Edit user',$config); ?>
    <div class="row justify-content-center"><div class="col-md-6"><div class="card p-4"><h2 class="h4">Edit user</h2><p class="muted small mb-3">Account created <?=h($u['created_at'])?>.</p><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="edit_user"><input type="hidden" name="id" value="<?=$u['id']?>"><div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" maxlength="80" value="<?=h($u['username'])?>" required></div><div class="mb-3"><label class="form-label">Display name</label><input class="form-control" name="display_name" maxlength="120" value="<?=h($u['display_name'])?>" required></div><div class="mb-3"><label class="form-label">Role</label><select class="form-select" name="role"><option value="user" <?=$u['role']==='user'?'selected':''?>>User</option><option value="admin" <?=$u['role']==='admin'?'selected':''?>>Administrator</option></select><div class="form-text">At least one administrator must remain on the board.</div></div><div class="mb-3"><label class="form-label">New password (leave empty to keep the current one)</label><input class="form-control" type="password" name="password" autocomplete="new-password"><div class="form-text">Minimum 10 characters.</div></div><button class="btn btn-primary">Save user</button> <a class="btn btn-outline-secondary" href="?page=admin">Cancel</a></form></div></div></div>
    <?php render_footer(); exit;
}

if ($page==='edit' || $page==='new') {
    $id=(int)($_GET['id']??0); $task=['id'=>0,'title'=>'','description'=>'','status_id'=>0,'parent_id'=>null,'schedule_period'=>null,'project_id'=>null,'archived_at'=>null]; $selected=[];
    if($id){$task=find_task($pdo,$id) ?: $task;$s=$pdo->prepare('SELECT user_id FROM task_assignees WHERE task_id=?');$s->execute([$id]);$selected=array_map('intval',array_column($s->fetchAll(),'user_id'));}
    // Archived tasks (#12) must be restored before they can be edited again.
    if(!empty($task['id']) && task_is_archived($task)) {
        render_header('Edit task',$config,$error); ?>
        <div class="card p-4"><h2 class="h4">This task is archived</h2><p class="muted mb-3">Archived tasks are read-only. Restore task #<?=$task['id']?> from the archive first if you want to edit it.</p><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="?page=task&id=<?=$task['id']?>">View the task</a><a class="btn btn-outline-primary" href="?page=archive">Open the archive</a><form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="action" value="restore_task"><input type="hidden" name="id" value="<?=$task['id']?>"><button class="btn btn-primary">Restore now</button></form></div></div>
        <?php render_footer(); exit;
    }
    $users=user_options($pdo);$statuses=all_statuses($pdo);$tasks=$pdo->prepare('SELECT id,title FROM tasks WHERE id<>? AND archived_at IS NULL ORDER BY id DESC LIMIT 200');$tasks->execute([$id]);$projects=$pdo->query('SELECT id,name FROM projects ORDER BY name')->fetchAll();$tpls=$id?[]:$pdo->query("SELECT id,name,description FROM templates WHERE type='task' ORDER BY name")->fetchAll();
    render_header($id?'Edit task':'New task',$config,$error); ?>
    <div class="card p-4"><h2 class="h4"><?= $id?'Edit task':'Create task' ?></h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_task"><input type="hidden" name="id" value="<?=$id?>"><?php if(!$id && $tpls): ?><div class="mb-3"><label class="form-label">Template</label><select class="form-select" id="task-template"><option value="">No template</option><?php foreach($tpls as $tp): ?><option value="<?=$tp['id']?>" data-description="<?=h($tp['description'])?>"><?=h($tp['name'])?></option><?php endforeach; ?></select><div class="form-text">Choosing a template fills the description below with the template text.</div></div><?php endif; ?><div class="mb-3"><label class="form-label">Title</label><input class="form-control" name="title" maxlength="200" value="<?=h($task['title'])?>" required></div><div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="5"><?=h($task['description'])?></textarea></div><div class="row"><div class="col-md-3 mb-3"><label class="form-label">Status</label><select class="form-select" name="status_id" required><?php foreach($statuses as $st): ?><option value="<?=$st['id']?>" <?=$task['status_id']==$st['id']?'selected':''?>><?=h($st['name'])?></option><?php endforeach; ?></select></div><div class="col-md-3 mb-3"><label class="form-label">Parent task</label><select class="form-select" name="parent_id"><option value="">None</option><?php foreach($tasks->fetchAll() as $t): ?><option value="<?=$t['id']?>" <?=$task['parent_id']==$t['id']?'selected':''?>>#<?=$t['id']?> - <?=h($t['title'])?></option><?php endforeach; ?></select></div><div class="col-md-3 mb-3"><label class="form-label">Auto-reopen schedule</label><select class="form-select" name="schedule_period"><option value="">None</option><?php foreach(schedule_periods() as $periodKey=>$periodLabel): ?><option value="<?=h($periodKey)?>" <?=$task['schedule_period']===$periodKey?'selected':''?>><?=h($periodLabel)?></option><?php endforeach; ?></select><div class="form-text">When set, the cron job resets this task to TODO, unassigns it and comments &quot;Reopen by auto-scheduler&quot; if it has not been updated within the selected period.</div></div><div class="col-md-3 mb-3"><label class="form-label">Project</label><select class="form-select" name="project_id"><option value="">None</option><?php foreach($projects as $pr): ?><option value="<?=$pr['id']?>" <?=$task['project_id']==$pr['id']?'selected':''?>>#<?=$pr['id']?> - <?=h($pr['name'])?></option><?php endforeach; ?></select></div></div><div class="mb-3"><label class="form-label">Assignees</label><div class="border rounded p-2" style="max-height:12rem;overflow-y:auto"><?php foreach($users as $u): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="assignees[]" value="<?=$u['id']?>" id="assignee-<?=$u['id']?>" <?=in_array((int)$u['id'],$selected,true)?'checked':''?>><label class="form-check-label" for="assignee-<?=$u['id']?>"><?=h($u['display_name'])?></label></div><?php endforeach; if(!$users): ?><div class="small muted">No users can be assigned yet.</div><?php endif; ?></div><div class="form-text">Tick a name to assign the task. Untick a name to unassign them - leaving everybody unticked keeps the task unassigned.</div></div><button class="btn btn-primary">Save task</button> <a class="btn btn-outline-secondary" href="?">Cancel</a></form></div>
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
    $id=(int)($_GET['id']??0);$s=$pdo->prepare('SELECT t.*,s.name status_name,u.display_name creator,prj.name project_name FROM tasks t JOIN statuses s ON s.id=t.status_id LEFT JOIN users u ON u.id=t.created_by LEFT JOIN projects prj ON prj.id=t.project_id WHERE t.id=?');$s->execute([$id]);$t=$s->fetch();
    if(!$t){http_response_code(404);exit('Task not found.');}
    $s=$pdo->prepare('SELECT u.display_name FROM task_assignees a JOIN users u ON u.id=a.user_id WHERE a.task_id=?');$s->execute([$id]);$assignees=$s->fetchAll();
    $s=$pdo->prepare('SELECT c.*,u.display_name FROM comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.task_id=? ORDER BY c.created_at');$s->execute([$id]);$comments=$s->fetchAll();
    $s=$pdo->prepare('SELECT a.*,u.display_name FROM attachments a LEFT JOIN users u ON u.id=a.user_id WHERE a.task_id=? ORDER BY a.created_at DESC');$s->execute([$id]);$attachments=$s->fetchAll();
    $s=$pdo->prepare('SELECT h.*,u.display_name FROM task_history h LEFT JOIN users u ON u.id=h.user_id WHERE h.task_id=? ORDER BY h.created_at DESC');$s->execute([$id]);$historyRows=$s->fetchAll();
    $s=$pdo->prepare('SELECT l.*,t.title FROM task_links l JOIN tasks t ON t.id=l.related_task_id WHERE l.task_id=? ORDER BY l.link_type');$s->execute([$id]);$links=$s->fetchAll();
    $all=$pdo->prepare('SELECT id,title FROM tasks WHERE id<>? AND archived_at IS NULL ORDER BY id DESC LIMIT 200');$all->execute([$id]);
    $isArchived=task_is_archived($t);
    render_header('Task #'.$id,$config,$error); ?>
    <div class="d-flex justify-content-between align-items-start mb-3"><div><div class="muted">Task #<?=$id?> · <?=h($t['status_name'])?><?php if($isArchived): ?> <span class="badge text-bg-secondary">Archived</span><?php endif; ?></div><h1 class="h3"><?=h($t['title'])?></h1></div><?php if(!$isArchived): ?><a class="btn btn-primary" href="?page=edit&id=<?=$id?>">Edit task</a><?php endif; ?></div>
    <div class="row g-4"><div class="col-lg-8"><div class="card p-4 mb-4"><div class="prewrap"><?=h($t['description'])?></div><hr><div class="small muted">Created by <?=h($t['creator'] ?: 'Deleted user')?> · <?=h($t['created_at'])?> · Updated <?=h($t['updated_at'])?></div><div class="mt-2"><strong>Assignees:</strong> <?=h(implode(', ',array_column($assignees,'display_name')) ?: 'Unassigned')?></div><?php if(!empty($t['schedule_period'])): ?><div><strong>Schedule:</strong> <?=h(schedule_periods()[$t['schedule_period']] ?? $t['schedule_period'])?> <span class="badge text-bg-info">auto-reopen</span></div><?php endif; ?><?php if($t['parent_id']): ?><div><strong>Parent:</strong> <a href="?page=task&id=<?=$t['parent_id']?>">Task #<?=$t['parent_id']?></a></div><?php endif; ?><?php if($t['project_id']): ?><div><strong>Project:</strong> <a href="?page=project&id=<?=$t['project_id']?>"><?=h($t['project_name'])?></a></div><?php endif; ?></div>
    <div class="card p-4 mb-4" id="comments"><h2 class="h5">Comments</h2><?php foreach($comments as $c): ?><div class="border-bottom py-3"><strong><?=h($c['display_name'] ?: 'Deleted user')?></strong> <span class="small muted"><?=h($c['created_at'])?></span><div class="prewrap mt-1"><?=h($c['body'])?></div></div><?php endforeach; ?><form method="post" class="mt-3"><?=csrf_field()?><input type="hidden" name="action" value="comment"><input type="hidden" name="task_id" value="<?=$id?>"><textarea class="form-control mb-2" name="body" rows="3" placeholder="Write a comment..." required></textarea><button class="btn btn-primary">Add comment</button></form></div>
    <div class="card p-4 mb-4"><h2 class="h5">Attachments</h2><?php foreach($attachments as $a): ?><div class="d-flex justify-content-between border-bottom py-2"><a href="?page=download&id=<?=$a['id']?>"><?=h($a['original_name'])?></a><span class="small muted"><?=h($a['display_name'] ?: 'Deleted user')?> · <?=number_format($a['file_size']/1024,1)?> KB</span></div><?php endforeach; ?><form method="post" enctype="multipart/form-data" class="mt-3"><?=csrf_field()?><input type="hidden" name="action" value="upload"><input type="hidden" name="task_id" value="<?=$id?>"><div class="input-group"><input class="form-control" type="file" name="file" required><button class="btn btn-outline-primary">Upload</button></div><div class="form-text">Max <?=round($config['max_upload_bytes']/1024/1024)?> MB. Allowed: common images, PDF, TXT, ZIP, DOCX and XLSX.</div></form></div>
    <div class="card p-4"><h2 class="h5">History</h2><?php foreach($historyRows as $r): ?><div class="border-bottom py-2"><strong><?=h($r['event'])?></strong> <span class="small muted">by <?=h($r['display_name'] ?: 'Deleted user')?> · <?=h($r['created_at'])?></span><?php if($r['details']): ?><div class="small"><?=h($r['details'])?></div><?php endif; ?></div><?php endforeach; ?></div></div>
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
    $id=(int)($_GET['id']??0);$s=$pdo->prepare('SELECT pr.*,s.name status_name,u.display_name creator FROM projects pr JOIN statuses s ON s.id=pr.status_id LEFT JOIN users u ON u.id=pr.created_by WHERE pr.id=?');$s->execute([$id]);$pr=$s->fetch();
    if(!$pr){http_response_code(404);exit('Project not found.');}
    $s=$pdo->prepare('SELECT u.display_name FROM project_assignees pa JOIN users u ON u.id=pa.user_id WHERE pa.project_id=?');$s->execute([$id]);$projectAssignees=$s->fetchAll();
    $s=$pdo->prepare('SELECT t.id,t.title,t.updated_at,s.name status_name FROM tasks t JOIN statuses s ON s.id=t.status_id WHERE t.project_id=? ORDER BY t.updated_at DESC');$s->execute([$id]);$projectTasks=$s->fetchAll();
    render_header('Project #'.$id,$config,$error); ?>
    <div class="d-flex justify-content-between align-items-start mb-3"><div><div class="muted">Project #<?=$id?> · <?=h($pr['status_name'])?></div><h1 class="h3"><?=h($pr['name'])?></h1></div><a class="btn btn-primary" href="?page=project_edit&id=<?=$id?>">Edit project</a></div>
    <div class="row g-4"><div class="col-lg-8"><div class="card p-4 mb-4"><div class="prewrap"><?=h($pr['description'])?></div><hr><div class="small muted">Created by <?=h($pr['creator'] ?: 'Deleted user')?> · <?=h($pr['created_at'])?> · Updated <?=h($pr['updated_at'])?></div><div class="mt-2"><strong>Assignees:</strong> <?=h(implode(', ',array_column($projectAssignees,'display_name')) ?: 'Unassigned')?></div></div>
    <div class="card p-4 mb-4"><h2 class="h5">Tasks in this project</h2><?php foreach($projectTasks as $pt): ?><div class="border-bottom py-2 d-flex justify-content-between"><a class="text-decoration-none" href="?page=task&id=<?=$pt['id']?>">#<?=$pt['id']?> · <?=h($pt['title'])?></a><span class="badge text-bg-light border"><?=h($pt['status_name'])?></span></div><?php endforeach; ?><?php if(!$projectTasks): ?><p class="muted mb-0">No tasks in this project yet.</p><?php endif; ?><div class="mt-3"><a class="btn btn-sm btn-outline-primary" href="?page=tasks&project=<?=$id?>">Tasks…</a> <span class="small muted">Opens the task list filtered to this project.</span></div></div></div>
    <div class="col-lg-4"><div class="card p-4"><h2 class="h5">Actions</h2><form method="post" onsubmit="return confirm('Delete this project permanently? The tasks will be kept but lose their project.')"><?=csrf_field()?><input type="hidden" name="action" value="delete_project"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="return" value="?page=projects"><button class="btn btn-outline-danger w-100">Delete project</button></form></div></div></div>
    <?php render_footer(); exit;
}

if ($page==='projects') {
    // Sorting for the projects list. Same conventions as the sortable task
    // list (?sort=/?dir= query parameters, allowlist mapping, {dir}
    // placeholders) but deliberately standalone here: this page works with
    // or without the shared sort helpers from the order-by change.
    $projectSortColumns=[
        'name'=>'pr.name {dir}',
        'status'=>'s.sort_order {dir}, s.name {dir}',
        'tasks'=>'task_count {dir}',
        'updated'=>'pr.updated_at {dir}',
    ];
    $projectSortKey=(string)($_GET['sort']??'');
    if (!array_key_exists($projectSortKey,$projectSortColumns)) $projectSortKey='updated';
    $projectDir=strtolower(trim((string)($_GET['dir']??'')));
    if (!in_array($projectDir,['asc','desc'],true)) $projectDir='desc';
    $projectOrder=str_replace('{dir}',strtoupper($projectDir),$projectSortColumns[$projectSortKey]).', pr.id '.strtoupper($projectDir);
    $perPage=20;
    $projectPage=max(1,(int)($_GET['p']??1));
    $totalProjects=(int)$pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
    $totalPages=max(1,(int)ceil($totalProjects/$perPage));
    $projectPage=min($projectPage,$totalPages);
    $projects=$pdo->query('SELECT pr.id,pr.name,pr.updated_at,pr.created_by,s.name status_name,(SELECT COUNT(*) FROM tasks t WHERE t.project_id=pr.id) task_count FROM projects pr JOIN statuses s ON s.id=pr.status_id ORDER BY '.$projectOrder.' LIMIT '.$perPage.' OFFSET '.(($projectPage-1)*$perPage))->fetchAll();
    $projectsUrl=function(array $override=[]): string {
        $qs=['page'=>'projects'];
        foreach (['sort','dir'] as $fk) {if (isset($_GET[$fk]) && $_GET[$fk]!=='' && $_GET[$fk]!==null) $qs[$fk]=(string)$_GET[$fk];}
        return '?' . http_build_query(array_merge($qs,$override));
    };
    $projectSort=['key'=>$projectSortKey,'dir'=>$projectDir];
    $projectToggle=function(string $key,array $sort): array {return ['sort'=>$key,'dir'=>($sort['key']===$key && $sort['dir']==='asc')?'desc':'asc'];};
    $projectHeader=function(string $label,string $key,array $sort,string $url): string {
        $isActive=$sort['key']===$key;
        $aria=$isActive?' aria-sort="'.($sort['dir']==='asc'?'ascending':'descending').'"':'';
        $arrow=$isActive?'<span aria-hidden="true"> '.($sort['dir']==='asc'?'&#9650;':'&#9660;').'</span>':'';
        return '<th'.$aria.'><a class="text-decoration-none" href="'.h($url).'" title="Order by '.h($label).'" aria-label="Order by '.h($label).'">'.h($label).$arrow.'</a></th>';
    };
    $projectAssigneeNames=project_assignee_names_by_project($pdo, array_column($projects,'id'));
    render_header('Projects',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Projects</h1><div class="muted"><?=$totalProjects?> project<?=$totalProjects===1?'':'s'?></div></div><a class="btn btn-primary" href="?page=project_new">+ New project</a></div>
    <form method="post" id="bulk-projects-form" onsubmit="return confirm('Delete all selected projects permanently?')"><?=csrf_field()?><input type="hidden" name="action" value="bulk_delete_projects"><input type="hidden" name="return" value="<?=h($projectsUrl(['p'=>$projectPage]))?>"><?php foreach (['sort','dir'] as $fk): if(($_GET[$fk]??'')!==''): ?><input type="hidden" name="<?=$fk?>" value="<?=h($_GET[$fk])?>"><?php endif; endforeach; ?>
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th class="ps-3" style="width:36px"><input type="checkbox" class="form-check-input" id="check-all-projects" aria-label="Select all projects on this page"></th><?=$projectHeader('Project','name',$projectSort,$projectsUrl($projectToggle('name',$projectSort)))?><?=$projectHeader('Status','status',$projectSort,$projectsUrl($projectToggle('status',$projectSort)))?><th>Assignees</th><?=$projectHeader('Tasks','tasks',$projectSort,$projectsUrl($projectToggle('tasks',$projectSort)))?><?=$projectHeader('Updated','updated',$projectSort,$projectsUrl($projectToggle('updated',$projectSort)))?><th>Actions</th></tr></thead><tbody><?php foreach($projects as $pr): $canDelete=is_admin()||(int)$pr['created_by']===(int)user()['id']; ?><tr><td class="ps-3"><input type="checkbox" class="form-check-input project-row-check" name="project_ids[]" value="<?=$pr['id']?>" aria-label="Select project #<?=$pr['id']?>"></td><td><a class="fw-semibold text-decoration-none" href="?page=project&id=<?=$pr['id']?>">#<?=$pr['id']?> · <?=h($pr['name'])?></a></td><td><span class="badge text-bg-light border"><?=h($pr['status_name'])?></span></td><td><?php echo h(implode(', ', $projectAssigneeNames[(int)$pr['id']] ?? []) ?: '—'); ?></td><td class="text-nowrap"><span class="me-2"><?=$pr['task_count']?> task<?=$pr['task_count']===1?'':'s'?></span><a class="btn btn-sm btn-outline-primary" href="?page=tasks&project=<?=$pr['id']?>">Tasks…</a></td><td class="small muted"><?=h($pr['updated_at'])?></td><td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?page=project_edit&id=<?=$pr['id']?>">Edit</a><?php if($canDelete): ?> <button type="submit" class="btn btn-sm btn-outline-danger" form="delete-project-<?=$pr['id']?>">Delete</button><?php endif; ?></td></tr><?php endforeach; if(!$projects): ?><tr><td colspan="7" class="text-center muted py-4">No projects yet.</td></tr><?php endif; ?></tbody></table></div></div>
    <div class="d-flex justify-content-between align-items-center mt-3 gap-2 flex-wrap"><?php if($totalPages>1): ?><nav aria-label="Project list pages"><ul class="pagination mb-0"><li class="page-item <?=$projectPage<=1?'disabled':''?>"><a class="page-link" href="<?=h($projectsUrl(['p'=>max(1,$projectPage-1)]))?>">&laquo;</a></li><?php $pWinStart=max(1,$projectPage-2);$pWinEnd=min($totalPages,$pWinStart+4);if($pWinEnd-$pWinStart<4)$pWinStart=max(1,$pWinEnd-4);if($pWinStart>1): ?><li class="page-item"><a class="page-link" href="<?=h($projectsUrl(['p'=>1]))?>">1</a></li><?php if($pWinStart>2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; endif; for($i=$pWinStart;$i<=$pWinEnd;$i++): ?><li class="page-item <?=$i===$projectPage?'active':''?>"><a class="page-link" href="<?=h($projectsUrl(['p'=>$i]))?>"><?=$i?></a></li><?php endfor; if($pWinEnd<$totalPages): ?><?php if($pWinEnd<$totalPages-1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?=h($projectsUrl(['p'=>$totalPages]))?>"><?=$totalPages?></a></li><?php endif; ?><li class="page-item <?=$projectPage>=$totalPages?'disabled':''?>"><a class="page-link" href="<?=h($projectsUrl(['p'=>min($totalPages,$projectPage+1)]))?>">&raquo;</a></li></ul></nav><span class="small muted">Page <?=$projectPage?> of <?=$totalPages?></span><?php endif; ?><button type="submit" class="btn btn-outline-danger" id="bulk-projects-btn" disabled>Delete selected (<span id="bulk-projects-count">0</span>)</button></div>
    </form>
    <?php foreach($projects as $pr): if(is_admin()||(int)$pr['created_by']===(int)user()['id']): ?><form method="post" id="delete-project-<?=$pr['id']?>" class="d-none" onsubmit="return confirm('Delete project #<?=$pr['id']?> permanently?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_project"><input type="hidden" name="id" value="<?=$pr['id']?>"><input type="hidden" name="return" value="<?=h($projectsUrl(['p'=>$projectPage]))?>"></form><?php endif; endforeach; ?>
    <script>
    (function(){
    var all=document.getElementById('check-all-projects');
    var btn=document.getElementById('bulk-projects-btn');
    function rows(){return Array.prototype.slice.call(document.querySelectorAll('.project-row-check'));}
    function sync(){var bs=rows();var n=bs.filter(function(b){return b.checked;}).length;if(btn)btn.disabled=n===0;if(all){all.checked=n>0&&n===bs.length;all.indeterminate=n>0&&n<bs.length;}var lbl=document.getElementById('bulk-projects-count');if(lbl)lbl.textContent=String(n);}
    if(all)all.addEventListener('change',function(){var v=all.checked;rows().forEach(function(b){b.checked=v;});sync();});
    document.addEventListener('change',function(e){if(e.target&&e.target.classList&&e.target.classList.contains('project-row-check'))sync();});
    sync();
    })();
    </script>
    <?php render_footer(); exit;
}

if ($page==='templates') {
    $typeFilter = $_GET['type'] ?? '';
    if (!in_array($typeFilter, ['task','project'], true)) $typeFilter = '';
    if ($typeFilter !== '') {
        $s = $pdo->prepare('SELECT t.*,u.display_name creator FROM templates t JOIN users u ON u.id=t.created_by WHERE t.type=? ORDER BY t.name'); $s->execute([$typeFilter]);
    } else {
        $s = $pdo->query('SELECT t.*,u.display_name creator FROM templates t JOIN users u ON u.id=t.created_by ORDER BY t.type,t.name');
    }
    $templates = $s->fetchAll();
    render_header('Templates',$config,$error); ?>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Templates</h1><div class="muted"><?=count($templates)?> template<?=count($templates)===1?'':'s'?></div></div><a class="btn btn-primary" href="?page=template_new">+ New template</a></div>
    <div class="card p-3 mb-3"><form class="row g-2"><input type="hidden" name="page" value="templates"><div class="col-md-3"><select class="form-select" name="type"><option value="">All types</option><option value="task" <?=$typeFilter==='task'?'selected':''?>>Task templates</option><option value="project" <?=$typeFilter==='project'?'selected':''?>>Project templates</option></select></div><div class="col-md-3 d-grid"><button class="btn btn-outline-primary">Filter</button></div></form></div>
    <div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Name</th><th>Type</th><th>Description</th><th>Updated</th><th>Actions</th></tr></thead><tbody><?php foreach($templates as $tp): $canDelete=is_admin()||(int)$tp['created_by']===(int)user()['id']; $desc=(string)$tp['description']; $short=preg_replace('/^(.{0,140}).*$/su','$1',$desc); ?><tr><td><a class="fw-semibold text-decoration-none" href="?page=template_edit&id=<?=$tp['id']?>"><?=h($tp['name'])?></a></td><td><span class="badge text-bg-light border"><?=h($tp['type'])?></span></td><td class="small muted" style="max-width:32rem"><?=h($short)?><?=strlen($desc)>strlen($short)?'&hellip;':''?></td><td class="small muted"><?=h($tp['updated_at'])?></td><td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?page=template_edit&id=<?=$tp['id']?>">Edit</a><?php if($canDelete): ?> <form method="post" class="d-inline" onsubmit="return confirm('Delete this template permanently?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_template"><input type="hidden" name="id" value="<?=$tp['id']?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form><?php endif; ?></td></tr><?php endforeach; if(!$templates): ?><tr><td colspan="5" class="text-center muted py-4">No templates found.</td></tr><?php endif; ?></tbody></table></div></div>
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
$where=[];$params=[];
$where[]='t.archived_at IS NULL'; // archived tasks (#12) are shown only in the archive
if (!empty($_GET['assignee'])) {if ($_GET['assignee']==='unassigned') {$where[]='NOT EXISTS(SELECT 1 FROM task_assignees ax WHERE ax.task_id=t.id)';} else {$where[]='EXISTS(SELECT 1 FROM task_assignees ax WHERE ax.task_id=t.id AND ax.user_id=?)';$params[]=(int)$_GET['assignee'];}}
if (!empty($_GET['status'])) {$where[]='t.status_id=?';$params[]=(int)$_GET['status'];}
if (!empty($_GET['parent'])) {if ($_GET['parent']==='none') {$where[]='t.parent_id IS NULL';} else {$where[]='t.parent_id=?';$params[]=(int)$_GET['parent'];}}
if (!empty($_GET['project'])) {$where[]='t.project_id=?';$params[]=(int)$_GET['project'];}
if (!empty($_GET['q'])) {$tbTerm=str_replace(['|','%','_'],['||','|%','|_'],(string)$_GET['q']);$where[]="(t.title LIKE ? ESCAPE '|' OR t.description LIKE ? ESCAPE '|')";$params[]='%'.$tbTerm.'%';$params[]='%'.$tbTerm.'%';}
$perPage=20;
$taskPage=max(1,(int)($_GET['p']??1));
$cs=$pdo->prepare('SELECT COUNT(*) FROM tasks t'.($where?' WHERE '.implode(' AND ',$where):''));$cs->execute($params);$totalTasks=(int)$cs->fetchColumn();
$totalPages=max(1,(int)ceil($totalTasks/$perPage));
$taskPage=min($taskPage,$totalPages);
// Clicking a column name orders the list. The keys map to safe SQL fragments
// and are validated by sort_state(), so query parameters can never inject SQL.
// The same helpers are meant to be reused by the other CRUD lists (Projects...).
$sortColumns=[
    'id'=>'t.id {dir}',
    'title'=>'t.title {dir}',
    'status'=>'s.sort_order {dir}, s.name {dir}',
    'assignees'=>'(SELECT MIN(a2u.display_name) FROM task_assignees a2 JOIN users a2u ON a2u.id=a2.user_id WHERE a2.task_id=t.id) {dir}',
    'parent'=>'t.parent_id {dir}',
    'updated'=>'t.updated_at {dir}',
];
$sort=sort_state($sortColumns,'updated','desc');
$sql='SELECT t.id,t.title,t.updated_at,t.created_by,t.parent_id,p.title parent_title,s.name status_name FROM tasks t JOIN statuses s ON s.id=t.status_id LEFT JOIN tasks p ON p.id=t.parent_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY '.$sort['sql'].', t.id '.strtoupper($sort['dir']).' LIMIT '.$perPage.' OFFSET '.(($taskPage-1)*$perPage);
$s=$pdo->prepare($sql);$s->execute($params);$tasks=$s->fetchAll();
$taskAssigneeNames=task_assignee_names_by_task($pdo, array_column($tasks,'id'));
$listUrl=function(array $override=[]): string {
    $qs=['page'=>'tasks'];
    foreach (['q','assignee','status','parent','project','sort','dir'] as $fk) {if (isset($_GET[$fk]) && $_GET[$fk]!=='' && $_GET[$fk]!==null) $qs[$fk]=(string)$_GET[$fk];}
    return '?' . http_build_query(array_merge($qs,$override));
};
render_header('Tasks',$config,$error); ?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Tasks</h1><div class="muted"><?=$totalTasks?> task<?=$totalTasks===1?'':'s'?></div><?php if(($_GET['project']??'')!==''): $pfq=$pdo->prepare('SELECT name FROM projects WHERE id=?');$pfq->execute([(int)$_GET['project']]);$projectFilterName=$pfq->fetchColumn(); ?><?php if($projectFilterName!==false): ?><div class="mt-1"><span class="badge text-bg-info">Project: <?=h($projectFilterName)?></span> <a class="small" href="<?=h($listUrl(['project'=>null]))?>">Show all tasks</a></div><?php endif; endif; ?></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="?page=archive">Archive</a><a class="btn btn-primary" href="?page=new">+ New task</a></div></div>
<div class="card p-3 mb-3"><form class="row g-2"><input type="hidden" name="page" value="tasks"><div class="col-md-2"><input class="form-control" name="q" value="<?=h($_GET['q']??'')?>" placeholder="Search tasks"></div><div class="col-md-2"><select class="form-select" name="assignee"><option value="">All assignees</option><option value="unassigned" <?=($_GET['assignee']??'')==='unassigned'?'selected':''?>>Unassigned</option><?php foreach($users as $u): ?><option value="<?=$u['id']?>" <?=($_GET['assignee']??'')==$u['id']?'selected':''?>><?=h($u['display_name'])?></option><?php endforeach; ?></select></div><div class="col-md-2"><select class="form-select" name="status"><option value="">All statuses</option><?php foreach($statuses as $st): ?><option value="<?=$st['id']?>" <?=($_GET['status']??'')==$st['id']?'selected':''?>><?=h($st['name'])?></option><?php endforeach; ?></select></div><div class="col-md-2"><select class="form-select" name="parent"><option value="">All parents</option><option value="none" <?=($_GET['parent']??'')==='none'?'selected':''?>>No parent</option><?php foreach($parents as $pt): ?><option value="<?=$pt['parent_id']?>" <?=($_GET['parent']??'')==(string)$pt['parent_id']?'selected':''?>>#<?=$pt['parent_id']?> · <?=h($pt['parent_title'])?></option><?php endforeach; ?></select></div><div class="col-md-2"><select class="form-select" name="project"><option value="">All projects</option><?php foreach($projectOptions as $po): ?><option value="<?=$po['id']?>" <?=($_GET['project']??'')==(string)$po['id']?'selected':''?>>#<?=$po['id']?> · <?=h($po['name'])?></option><?php endforeach; ?></select></div><div class="col-md-2 d-grid"><button class="btn btn-outline-primary">Filter</button></div></form></div>
<form method="post" id="bulk-form" onsubmit="return confirm((event.submitter&&event.submitter.dataset.confirm)||'Apply this bulk action to all selected tasks?')"><?=csrf_field()?><input type="hidden" name="return" value="<?=h($listUrl(['p'=>$taskPage]))?>"><?php foreach (['q','assignee','status','parent','project','sort','dir'] as $fk): if(($_GET[$fk]??'')!==''): ?><input type="hidden" name="<?=$fk?>" value="<?=h($_GET[$fk])?>"><?php endif; endforeach; ?>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th class="ps-3" style="width:36px"><input type="checkbox" class="form-check-input" id="check-all" aria-label="Select all tasks on this page"></th><?=sort_header('Task','id',$sort,$listUrl(sort_toggle('id',$sort)))?><?=sort_header('Status','status',$sort,$listUrl(sort_toggle('status',$sort)))?><?=sort_header('Assignees','assignees',$sort,$listUrl(sort_toggle('assignees',$sort)))?><?=sort_header('Parent','parent',$sort,$listUrl(sort_toggle('parent',$sort)))?><?=sort_header('Updated','updated',$sort,$listUrl(sort_toggle('updated',$sort)))?><th>Actions</th></tr></thead><tbody><?php foreach($tasks as $t): $canArchive=is_admin()||(int)$t['created_by']===(int)user()['id']; ?><tr><td class="ps-3"><input type="checkbox" class="form-check-input row-check" name="task_ids[]" value="<?=$t['id']?>" aria-label="Select task #<?=$t['id']?>"></td><td><a class="fw-semibold text-decoration-none" href="?page=task&id=<?=$t['id']?>">#<?=$t['id']?> · <?=h($t['title'])?></a></td><td><span class="badge text-bg-light border"><?=h($t['status_name'])?></span></td><td><?php echo h(implode(', ', $taskAssigneeNames[(int)$t['id']] ?? []) ?: '—'); ?></td><td><?php if($t['parent_id']): ?><a class="text-decoration-none" href="?page=task&id=<?=$t['parent_id']?>">#<?=$t['parent_id']?><?=($t['parent_title']??'')!==''&&$t['parent_title']!==null?' · '.h($t['parent_title']):''?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td><td class="small muted"><?=h($t['updated_at'])?></td><td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="?page=edit&id=<?=$t['id']?>">Edit</a><?php if($canArchive): ?> <button type="submit" class="btn btn-sm btn-outline-danger" form="archive-<?=$t['id']?>">Archive</button><?php endif; ?></td></tr><?php endforeach; if(!$tasks): ?><tr><td colspan="7" class="text-center muted py-4">No tasks found.</td></tr><?php endif; ?></tbody></table></div></div>
<div class="d-flex justify-content-between align-items-center mt-3 gap-2 flex-wrap"><?php if($totalPages>1): ?><nav aria-label="Task list pages"><ul class="pagination mb-0"><li class="page-item <?=$taskPage<=1?'disabled':''?>"><a class="page-link" href="<?=h($listUrl(['p'=>max(1,$taskPage-1)]))?>">&laquo;</a></li><?php $winStart=max(1,$taskPage-2);$winEnd=min($totalPages,$winStart+4);if($winEnd-$winStart<4)$winStart=max(1,$winEnd-4);if($winStart>1): ?><li class="page-item"><a class="page-link" href="<?=h($listUrl(['p'=>1]))?>">1</a></li><?php if($winStart>2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; endif; for($i=$winStart;$i<=$winEnd;$i++): ?><li class="page-item <?=$i===$taskPage?'active':''?>"><a class="page-link" href="<?=h($listUrl(['p'=>$i]))?>"><?=$i?></a></li><?php endfor; if($winEnd<$totalPages): ?><?php if($winEnd<$totalPages-1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?><li class="page-item"><a class="page-link" href="<?=h($listUrl(['p'=>$totalPages]))?>"><?=$totalPages?></a></li><?php endif; ?><li class="page-item <?=$taskPage>=$totalPages?'disabled':''?>"><a class="page-link" href="<?=h($listUrl(['p'=>min($totalPages,$taskPage+1)]))?>">&raquo;</a></li></ul></nav><span class="small muted">Page <?=$taskPage?> of <?=$totalPages?></span><?php endif; ?><div class="d-flex align-items-center gap-2 flex-wrap ms-auto"><select class="form-select" name="project_id" id="bulk-move-project" aria-label="Target project for the selected tasks"><option value="">Choose project&hellip;</option><?php foreach($projectOptions as $po): ?><option value="<?=$po['id']?>">#<?=$po['id']?> &middot; <?=h($po['name'])?></option><?php endforeach; ?></select><button type="submit" class="btn btn-outline-primary" name="action" value="bulk_move_tasks" id="bulk-move-btn" disabled data-confirm="Move all selected tasks to the chosen project?">Move to project</button><button type="submit" class="btn btn-outline-danger" name="action" value="bulk_archive_tasks" id="bulk-delete-btn" disabled data-confirm="Archive all selected tasks? They can be restored from the archive.">Archive selected (<span id="bulk-count">0</span>)</button></div></div>
</form>
<?php foreach($tasks as $t): if(is_admin()||(int)$t['created_by']===(int)user()['id']): ?><form method="post" id="archive-<?=$t['id']?>" class="d-none" onsubmit="return confirm('Archive task #<?=$t['id']?>? It can be restored from the archive.')"><?=csrf_field()?><input type="hidden" name="action" value="archive_task"><input type="hidden" name="id" value="<?=$t['id']?>"><input type="hidden" name="return" value="<?=h($listUrl(['p'=>$taskPage]))?>"></form><?php endif; endforeach; ?>
<script>
(function(){
var all=document.getElementById('check-all');
var btn=document.getElementById('bulk-delete-btn');
var moveBtn=document.getElementById('bulk-move-btn');
var moveSelect=document.getElementById('bulk-move-project');
function rows(){return Array.prototype.slice.call(document.querySelectorAll('.row-check'));}
function sync(){var bs=rows();var n=bs.filter(function(b){return b.checked;}).length;if(btn)btn.disabled=n===0;if(moveBtn)moveBtn.disabled=n===0;if(moveSelect)moveSelect.disabled=n===0;if(all){all.checked=n>0&&n===bs.length;all.indeterminate=n>0&&n<bs.length;}var lbl=document.getElementById('bulk-count');if(lbl)lbl.textContent=String(n);}
if(all)all.addEventListener('change',function(){var v=all.checked;rows().forEach(function(b){b.checked=v;});sync();});
document.addEventListener('change',function(e){if(e.target&&e.target.classList&&e.target.classList.contains('row-check'))sync();});
sync();
})();
</script>
<?php render_footer(); ?>
