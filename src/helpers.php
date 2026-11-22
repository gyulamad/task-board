<?php
declare(strict_types=1);

// Shared helper functions for the task board application.
// Extracted verbatim from index.php so they can be unit tested and reused
// by other entry points. No behavior change: these are the exact definitions
// that previously lived inline in index.php.

function h(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function user(): ?array { return $_SESSION['user'] ?? null; }
function is_admin(): bool { return user() && user()['role'] === 'admin'; }
function require_login(): void { if (!user()) { header('Location: ?page=login'); exit; } }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf'] ?? '') . '">'; }
function verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(403); exit('Invalid CSRF token.');
    }
}
function flash(string $message): void { $_SESSION['flash'] = $message; }
function redirect(string $url = '?'): never { header('Location: ' . $url); exit; }
function history(PDO $pdo, int $taskId, string $event, string $details = ''): void {
    $s = $pdo->prepare('INSERT INTO task_history(task_id,user_id,event,details) VALUES(?,?,?,?)');
    $s->execute([$taskId, user()['id'], $event, $details]);
}
function task_exists(PDO $pdo, int $id): bool {
    $s = $pdo->prepare('SELECT 1 FROM tasks WHERE id=?'); $s->execute([$id]); return (bool)$s->fetchColumn();
}

// ---- Shared data-access helpers -----------------------------------------------------------
// Small, single-purpose lookups that used to be copy-pasted as raw SQL across
// the pages. Keeping them here gives one place to read and unit test them.

/** Relationship link types allowed for tasks; mirrors the task_links ENUM in schema.sql. */
const TASK_BOARD_LINK_TYPES = [
    'blocks' => 'Blocks',
    'depends_on' => 'Depends on',
    'relates_to' => 'Relates to',
];

/** Number of registered accounts; used by the installer gate and login hint. */
function user_count(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

/** Full task row by id, or null when the task does not exist. */
function find_task(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare('SELECT * FROM tasks WHERE id=?');
    $s->execute([$id]);
    $task = $s->fetch();
    return $task === false ? null : $task;
}

/** Full project row by id, or null when the project does not exist. */
function find_project(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare('SELECT * FROM projects WHERE id=?');
    $s->execute([$id]);
    $project = $s->fetch();
    return $project === false ? null : $project;
}

function status_exists(PDO $pdo, int $id): bool {
    $s = $pdo->prepare('SELECT 1 FROM statuses WHERE id=?');
    $s->execute([$id]);
    return (bool)$s->fetchColumn();
}

function project_exists(PDO $pdo, int $id): bool {
    $s = $pdo->prepare('SELECT 1 FROM projects WHERE id=?');
    $s->execute([$id]);
    return (bool)$s->fetchColumn();
}

/** Every status in display order; drives the status dropdowns and the admin page. */
function all_statuses(PDO $pdo): array {
    return $pdo->query('SELECT * FROM statuses ORDER BY sort_order,id')->fetchAll();
}

/** Assignable users in display-name order; drives the assignee pickers and filters. */
function user_options(PDO $pdo): array {
    return $pdo->query('SELECT id,display_name FROM users ORDER BY display_name')->fetchAll();
}

/**
 * Assignee display names for several tasks in one query, keyed by task id.
 * Tasks without any assignee are simply absent from the result. Replaces the
 * per-row query the task list used to run for every rendered row.
 *
 * @return array<int, string[]>
 */
function task_assignee_names_by_task(PDO $pdo, array $taskIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $taskIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare('SELECT a.task_id, u.display_name FROM task_assignees a JOIN users u ON u.id=a.user_id WHERE a.task_id IN (' . $placeholders . ')');
    $s->execute($ids);
    $names = [];
    foreach ($s->fetchAll() as $row) {
        $names[(int)$row['task_id']][] = $row['display_name'];
    }
    return $names;
}

/**
 * Assignee display names for several projects in one query, keyed by project
 * id (the project list equivalent of task_assignee_names_by_task()).
 *
 * @return array<int, string[]>
 */
function project_assignee_names_by_project(PDO $pdo, array $projectIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $projectIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare('SELECT pa.project_id, u.display_name FROM project_assignees pa JOIN users u ON u.id=pa.user_id WHERE pa.project_id IN (' . $placeholders . ')');
    $s->execute($ids);
    $names = [];
    foreach ($s->fetchAll() as $row) {
        $names[(int)$row['project_id']][] = $row['display_name'];
    }
    return $names;
}

/** A task is archived when its archived_at column carries a non-empty value. */
function task_is_archived(array $task): bool {
    $archivedAt = $task['archived_at'] ?? null;
    return $archivedAt !== null && $archivedAt !== '';
}

/**
 * Applies a creator-or-admin guarded action to a list of ids, one query pair
 * reused for the whole batch. Returns how many were applied and skipped.
 *
 * @return array{done: int, skipped: int}
 */
function tb_bulk_apply(PDO $pdo, array $ids, string $checkSql, string $actionSql): array {
    $check = $pdo->prepare($checkSql);
    $act = $pdo->prepare($actionSql);
    $done = 0;
    $skipped = 0;
    foreach ($ids as $id) {
        $check->execute([(int)$id]);
        $creator = (int)$check->fetchColumn();
        if (is_admin() || $creator === (int)user()['id']) {
            $act->execute([(int)$id]);
            $done++;
        } else {
            $skipped++;
        }
    }
    return ['done' => $done, 'skipped' => $skipped];
}

/** Flash text for a bulk action, e.g. "2 tasks archived. 1 skipped: ...". */
function tb_bulk_flash(int $done, int $skipped, string $noun, string $doneLabel, string $skipHint): string {
    return $done . ' ' . $noun . ($done === 1 ? '' : 's') . ' ' . $doneLabel . '.'
        . ($skipped ? ' ' . $skipped . ' skipped: ' . $skipHint : '');
}

/**
 * Destroys the current session completely: clears the session data, removes
 * the server-side storage and expires the session cookie on the client.
 */
function tb_session_destroy(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $p['path'],
            'domain' => $p['domain'],
            'secure' => (bool)$p['secure'],
            'httponly' => (bool)$p['httponly'],
            'samesite' => 'Lax',
        ]);
    }
}

/**
 * Character length that does not hard-require ext-mbstring: falls back to
 * iconv and finally to byte length when neither Unicode extension exists.
 */
function tb_char_length(string $value): int {
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($value, 'UTF-8');
    }
    if (function_exists('iconv_strlen')) {
        return (int)(iconv_strlen($value, 'UTF-8') ?: strlen($value));
    }
    return strlen($value);
}

/** Storage location for the login throttle bucket of one client key. */
function tb_login_throttle_file(string $key): string {
    return sys_get_temp_dir() . '/tb-login-' . hash('sha256', $key);
}

/**
 * Sign-in brute-force throttle: returns true when 10 or more failed sign-in
 * attempts were recorded for this client key within the last 10 minutes.
 */
function tb_login_throttled(string $key): bool {
    $raw = @file_get_contents(tb_login_throttle_file($key));
    if ($raw === false) return false;
    $data = json_decode($raw, true);
    $fails = is_array($data) && is_array($data['fails'] ?? null) ? $data['fails'] : [];
    $cutoff = time() - 600;
    $recent = array_filter($fails, fn($t) => is_int($t) && $t >= $cutoff);
    return count($recent) >= 10;
}

/** Records one failed sign-in attempt for the given client key. */
function tb_login_throttle_fail(string $key): void {
    $file = tb_login_throttle_file($key);
    $raw = @file_get_contents($file);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $fails = is_array($data) && is_array($data['fails'] ?? null) ? $data['fails'] : [];
    $cutoff = time() - 600;
    $fails = array_values(array_filter($fails, fn($t) => is_int($t) && $t >= $cutoff));
    $fails[] = time();
    @file_put_contents($file, json_encode(['fails' => $fails]), LOCK_EX);
}

/** Clears the throttle bucket for the given client key after a successful sign-in. */
function tb_login_throttle_clear(string $key): void {
    @unlink(tb_login_throttle_file($key));
}

// Sortable list helpers, shared by every CRUD list page (Tasks today,
// Projects and future CRUDs later). Clicking a column name reorders the
// list; the sort column/direction travel in the ?sort= and ?dir= query
// parameters and are validated against an allowlist, so the values can
// never inject SQL.

/**
 * Reads the requested sort from $_GET and validates it against $columns
 * (user-facing key => raw SQL fragment). Each fragment must contain the
 * {dir} placeholder, which is replaced with ASC or DESC so every part of
 * a multi-column fragment follows the chosen direction. Returns the safe
 * state to render headers with: the chosen key, the direction, and the
 * ORDER BY fragment. Anything unknown falls back to the given defaults.
 */
function sort_state(array $columns, string $defaultKey, string $defaultDir = 'asc'): array {
    $key = (string)($_GET['sort'] ?? '');
    if (!array_key_exists($key, $columns)) $key = $defaultKey;
    $dir = strtolower(trim((string)($_GET['dir'] ?? '')));
    if (!in_array($dir, ['asc', 'desc'], true)) $dir = $defaultDir;
    return ['key' => $key, 'dir' => $dir, 'sql' => str_replace('{dir}', strtoupper($dir), $columns[$key])];
}

/**
 * Returns the query parameter override that a column header link needs to
 * order the list by $key: clicking an unsorted (or ascending) column sorts
 * ascending first, clicking it again flips to descending. Merge the result
 * into the page's own URL builder so existing filters are kept; the page
 * number is intentionally not carried over so a new sort starts at page 1.
 */
function sort_toggle(string $key, array $current): array {
    $dir = ($current['key'] === $key && $current['dir'] === 'asc') ? 'desc' : 'asc';
    return ['sort' => $key, 'dir' => $dir];
}

/**
 * Renders a sortable <th> header cell. $url is the fully built link the
 * column header should point to (typically the page's list URL merged with
 * sort_toggle()). The active column shows a direction arrow and exposes
 * aria-sort for assistive technology.
 */
function sort_header(string $label, string $key, array $current, string $url): string {
    $isActive = ($current['key'] === $key);
    $ariaSort = $isActive ? ' aria-sort="' . ($current['dir'] === 'asc' ? 'ascending' : 'descending') . '"' : '';
    $arrow = $isActive ? '<span aria-hidden="true"> ' . ($current['dir'] === 'asc' ? '&#9650;' : '&#9660;') . '</span>' : '';
    $nextDir = (!$isActive || $current['dir'] === 'asc') ? 'descending' : 'ascending';
    return '<th' . $ariaSort . '><a class="text-decoration-none" href="' . h($url) . '" title="Order by ' . h($label) . '" aria-label="Order by ' . h($label) . ', currently ' . ($isActive ? $current['dir'] : 'unsorted') . ', activate to sort ' . $nextDir . '">' . h($label) . $arrow . '</a></th>';
}

// --- Theme settings (Task #11) -------------------------------------------

/** Themes a user can pick from. 'light' is the original look of the board. */
function valid_themes(): array { return ['light', 'dark']; }

/** Maps any stored or submitted value to a valid theme, falling back to 'light'. */
function normalize_theme(?string $theme): string { return in_array($theme, valid_themes(), true) ? $theme : 'light'; }

/** The theme of the signed-in user (falls back to 'light' for anonymous visitors). */
function current_theme(): string { return normalize_theme(user()['theme'] ?? null); }

/**
 * Adds the users.theme column in place for boards whose database was created
 * before the theme feature existed, so the feature works without a manual
 * schema migration on the live board. No-op when the column already exists
 * (fresh installs get it from schema.sql).
 */
function ensure_users_theme_column(PDO $pdo): void {
    try {
        $pdo->query('SELECT theme FROM users LIMIT 1');
        return;
    } catch (Throwable $e) {
        // Column missing: fall through and add it.
    }
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec("ALTER TABLE users ADD COLUMN theme TEXT NOT NULL DEFAULT 'light'");
    } else {
        $pdo->exec("ALTER TABLE users ADD COLUMN theme VARCHAR(20) NOT NULL DEFAULT 'light'");
    }
}

/**
 * Idempotent schema self-heal (#18 scheduler, #12 archive, #24 projects).
 * Fresh installs get everything from schema.sql; this check upgrades existing
 * databases in place on the first request after a deployment, so shipping an
 * update never requires manual SQL. It runs a handful of cheap catalog reads
 * per request (SHOW COLUMNS / PRAGMA table_info / sqlite_master) and only
 * writes to the database when a schema part is actually missing.
 *
 * The projects part (#24) exists because a deployment that lands without the
 * documented upgrade SQL used to fatal on every list page with "table
 * projects doesn't exist" (the task pages query the projects schema
 * unconditionally). With this in place the first request recreates the
 * missing tables and column instead.
 */
function tb_migrate_schema(PDO $pdo): void {
    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $taskColumns = $isMysql
        ? array_column($pdo->query('SHOW COLUMNS FROM tasks')->fetchAll(PDO::FETCH_ASSOC), 'Field')
        : array_column($pdo->query('PRAGMA table_info(tasks)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('schedule_period', $taskColumns, true)) {
        $pdo->exec($isMysql
            ? 'ALTER TABLE tasks ADD COLUMN schedule_period VARCHAR(20) NULL DEFAULT NULL AFTER parent_id'
            : 'ALTER TABLE tasks ADD COLUMN schedule_period TEXT NULL DEFAULT NULL');
    }
    if (!in_array('archived_at', $taskColumns, true)) {
        $pdo->exec($isMysql
            ? 'ALTER TABLE tasks ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at'
            : 'ALTER TABLE tasks ADD COLUMN archived_at TEXT NULL DEFAULT NULL');
    }

    $hasProjects = $isMysql
        ? (bool)$pdo->query("SHOW TABLES LIKE 'projects'")->fetchColumn()
        : (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='projects'")->fetchColumn();
    if (!$hasProjects) {
        if ($isMysql) {
            $pdo->exec("CREATE TABLE projects (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                description TEXT NULL,
                status_id INT UNSIGNED NOT NULL,
                created_by INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_project_status FOREIGN KEY (status_id) REFERENCES statuses(id),
                CONSTRAINT fk_project_creator FOREIGN KEY (created_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE project_assignees (
                project_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (project_id, user_id),
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE projects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT NULL,
                status_id INTEGER NOT NULL,
                created_by INTEGER NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_project_status FOREIGN KEY (status_id) REFERENCES statuses(id),
                CONSTRAINT fk_project_creator FOREIGN KEY (created_by) REFERENCES users(id)
            )");
            $pdo->exec("CREATE TABLE project_assignees (
                project_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                PRIMARY KEY (project_id, user_id),
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )");
        }
    }

    if (!in_array('project_id', $taskColumns, true)) {
        if ($isMysql) {
            $pdo->exec('ALTER TABLE tasks ADD COLUMN project_id INT UNSIGNED NULL AFTER schedule_period');
            $pdo->exec('ALTER TABLE tasks ADD CONSTRAINT fk_task_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL');
        } else {
            // SQLite cannot attach a foreign key to an existing column, so the
            // self-healed column is a plain nullable one; fresh installs get the
            // real constraint from schema.sql and deletes keep the tasks intact
            // because the list queries tolerate a missing project reference.
            $pdo->exec('ALTER TABLE tasks ADD COLUMN project_id INTEGER NULL DEFAULT NULL');
        }
    }
}
