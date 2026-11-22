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
