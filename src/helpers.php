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
    // last_seen_at rides along (#30) so name renderings that can carry markup
    // (the assignee checkboxes on the edit pages) show the activity dot;
    // <option>-based pickers keep using display_name only.
    return $pdo->query('SELECT id,display_name,last_seen_at FROM users ORDER BY display_name')->fetchAll();
}

/**
 * Assignees for several tasks in one query, keyed by task id: each entry is a
 * list of rows (display_name + last_seen_at) ready for tb_user_with_activity().
 * Tasks without any assignee are simply absent from the result. Replaces the
 * per-row query the task list used to run for every rendered row.
 *
 * @return array<int, array<int, array<string,mixed>>>
 */
function task_assignees_by_task(PDO $pdo, array $taskIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $taskIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare('SELECT a.task_id, u.display_name, u.last_seen_at FROM task_assignees a JOIN users u ON u.id=a.user_id WHERE a.task_id IN (' . $placeholders . ')');
    $s->execute($ids);
    $assignees = [];
    foreach ($s->fetchAll() as $row) {
        $assignees[(int)$row['task_id']][] = $row;
    }
    return $assignees;
}

/**
 * Assignees for several projects in one query, keyed by project id (the
 * project list equivalent of task_assignees_by_task()).
 *
 * @return array<int, array<int, array<string,mixed>>>
 */
function project_assignees_by_project(PDO $pdo, array $projectIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $projectIds), fn($v) => $v > 0)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare('SELECT pa.project_id, u.display_name, u.last_seen_at FROM project_assignees pa JOIN users u ON u.id=pa.user_id WHERE pa.project_id IN (' . $placeholders . ')');
    $s->execute($ids);
    $assignees = [];
    foreach ($s->fetchAll() as $row) {
        $assignees[(int)$row['project_id']][] = $row;
    }
    return $assignees;
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

// --- Multi-select list filters (Task #25) -----------------------------------
//
// The CRUD list pages (Tasks, Templates) filter their rows through dropdowns.
// Since #25 those dropdowns accept several values at once: the form fields
// submit arrays (?assignee[]=2&assignee[]=5). These helpers normalize filter
// input, keep it safe (ids are cast, keywords are matched exactly, everything
// else is ignored) and preserve multi-value filters across the generated
// list URLs (pagination, sort headers, bulk-action return links).

/**
 * Reads one list-filter query parameter from $_GET and normalizes it to a
 * clean array of scalar strings, regardless of how it arrived:
 *  - missing or empty                        -> []
 *  - a scalar ("?assignee=5")                -> ['5']      (legacy links)
 *  - an array ("?assignee[]=5&assignee[]=7") -> ['5','7']  (multi-select form)
 * Nulls, empty strings and duplicates are dropped and array keys are ignored,
 * so "?assignee[]=5" and "?assignee[0]=5" behave identically. The values stay
 * strings; casting to ids or matching keywords happens in filter_split().
 *
 * @return string[]
 */
function filter_values(string $key): array {
    $raw = $_GET[$key] ?? [];
    if (!is_array($raw)) {
        $raw = [$raw];
    }
    $values = [];
    foreach ($raw as $value) {
        if ($value === null) {
            continue;
        }
        $value = (string)$value;
        if ($value === '') {
            continue;
        }
        $values[] = $value;
    }
    return array_values(array_unique($values));
}

/**
 * Splits normalized filter values (see filter_values()) into recognized
 * special keywords and positive integer ids, keeping first-seen order.
 * Anything else is ignored, so a hand-crafted URL can never inject anything
 * unexpected: every id reaches the query as a bound parameter and every
 * keyword is compared as an exact string. Ids are validated strictly with
 * FILTER_VALIDATE_INT (no numeric-prefix surprises like (int)"1) OR 1=1--"
 * silently becoming 1) and capped to the INT UNSIGNED range of the id
 * columns, so even an out-of-range value can never upset a strict MySQL.
 *
 * @param string[] $values   normalized filter values
 * @param string[] $keywords special values to recognize, e.g. ['unassigned', 'none']
 * @return array{keywords: string[], ids: int[]}
 */
function filter_split(array $values, array $keywords): array {
    $keywords = array_flip($keywords);
    $out = ['keywords' => [], 'ids' => []];
    foreach ($values as $value) {
        if (isset($keywords[$value])) {
            $out['keywords'][] = $value;
            continue;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id !== false && $id > 0 && $id <= 4294967295) {
            $out['ids'][] = $id;
        }
    }
    $out['keywords'] = array_values(array_unique($out['keywords']));
    $out['ids'] = array_values(array_unique($out['ids']));
    return $out;
}

/**
 * Builds the "?,?,?" placeholder list for an IN (...) clause. $count below 1
 * yields a single placeholder so the generated SQL is always well-formed;
 * callers only use the result when they actually have ids to bind.
 */
function tb_in_placeholders(int $count): string {
    return implode(',', array_fill(0, max(1, $count), '?'));
}

/**
 * Carries one filter parameter from a request into a query string builder:
 * scalars pass through as strings, arrays pass through as arrays so
 * http_build_query() re-emits them as filter[0]=...&filter[1]=... (which PHP
 * parses back into an array). Empty and blank values are dropped entirely.
 * $source defaults to $_GET (the list page URLs); the saved-search save
 * handler (#29) passes $_POST so the same normalization builds the stored
 * query string.
 *
 * @return string[]|string|null
 */
function filter_query_value(string $key, ?array $source = null) {
    $value = ($source ?? $_GET)[$key] ?? null;
    if ($value === null || $value === '') {
        return null;
    }
    if (is_array($value)) {
        $value = array_values(array_filter(array_map('strval', $value), fn($v) => $v !== ''));
        return $value === [] ? null : $value;
    }
    return (string)$value;
}

// --- Saved searches (Task #29) ----------------------------------------------
//
// The task list is fully URL-driven: every dropdown and the search box submit
// into ?q=/?assignee[]=/... and nothing about a filtered list lives in the
// session or a cookie. A saved search therefore stores exactly that URL part
// under a name (the user's "actual search link"), and the list page renders
// the stored links in a dropdown. These helpers build the canonical query
// string that gets stored; the list page itself keeps validating every
// parameter when the link is opened, so a stored value can never do more than
// a hand-typed URL could.

/** Request keys a saved search stores, in canonical order. */
const TASK_BOARD_SEARCH_KEYS = ['q', 'assignee', 'status', 'parent', 'project', 'sort', 'dir'];

/** The subset of TASK_BOARD_SEARCH_KEYS that actually filters the list; at least one must be present for a search to be worth saving. */
const TASK_BOARD_SEARCH_FILTER_KEYS = ['q', 'assignee', 'status', 'parent', 'project'];

/** Keys that carry a single value on the list page (the rest submit arrays). */
const TASK_BOARD_SEARCH_SCALAR_KEYS = ['q', 'sort', 'dir'];

/**
 * Builds the canonical saved-search query string from request-shaped values
 * ($_POST in the save handler, but any array works for unit tests): every
 * TASK_BOARD_SEARCH_KEYS entry passes through filter_query_value(), scalar
 * keys collapse a crafted array to its first value so the stored link can
 * never hit the list page with an array ?q=. Unknown keys (page, p, csrf,
 * action, ...) are dropped. Returns null when none of the real filter keys
 * is present, e.g. only the sort direction was picked - such a link would
 * just re-open the plain list, which is not a search.
 */
function tb_saved_search_query(array $source): ?string {
    $qs = [];
    foreach (TASK_BOARD_SEARCH_KEYS as $key) {
        $value = filter_query_value($key, $source);
        if ($value === null) {
            continue;
        }
        if (in_array($key, TASK_BOARD_SEARCH_SCALAR_KEYS, true) && is_array($value)) {
            $value = $value[0] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
        }
        $qs[$key] = $value;
    }
    if (!array_intersect_key($qs, array_flip(TASK_BOARD_SEARCH_FILTER_KEYS))) {
        return null;
    }
    return http_build_query($qs);
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

// How stale a stored last_seen_at may be before an authenticated request
// refreshes it (#30). The write is the only cost of tracking activity, and on
// a board where every click is a request an unconditional UPDATE would churn
// the users table for no visible change: the admin page reads minutes, so a
// two-minute window still lands the one write that matters (the first of a
// burst, and the first request after a login) while the rest are no-ops.
const TB_LAST_SEEN_MIN_INTERVAL_MINUTES = 2;

/**
 * Refreshes the signed-in user's last_seen_at (#30). Called once per request
 * from index.php for authenticated sessions. The UPDATE is self-throttling:
 * the WHERE clause only matches when the stored stamp is missing or older
 * than TB_LAST_SEEN_MIN_INTERVAL_MINUTES, so most requests cost one indexed
 * no-op statement and the column stays good enough for minute-resolution
 * labels. The stamp is written and compared on the database's own clock
 * (CURRENT_TIMESTAMP / NOW() / datetime('now')), so PHP's timezone setting
 * can never skew it.
 */
function tb_touch_last_seen(PDO $pdo): void {
    $u = user();
    if ($u === null) return;   // anonymous requests are not user activity
    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $sql = $isMysql
        ? 'UPDATE users SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < NOW() - INTERVAL ' . TB_LAST_SEEN_MIN_INTERVAL_MINUTES . ' MINUTE)'
        : "UPDATE users SET last_seen_at = CURRENT_TIMESTAMP WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at <= datetime('now', '-" . TB_LAST_SEEN_MIN_INTERVAL_MINUTES . " minutes'))";
    $st = $pdo->prepare($sql);
    $st->execute([$u['id']]);
    // Keep the session copy in step with the column: the navbar renders the
    // signed-in user's activity dot from the session, so whenever the stored
    // stamp is actually refreshed, the session learns the new value too.
    if ($st->rowCount() > 0 && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
        $_SESSION['user']['last_seen_at'] = $pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
    }
}

/**
 * The 'active: 23 minutes ago' label of the admin page's Last active column
 * (#30). Pure: pass $now (a unix timestamp) to pin the clock — the page feeds
 * it the database's own current timestamp, so a label is always computed on
 * the same clock the stamp was written with; tests pass a fixed one. NULL (a
 * user not seen since the feature landed) reads 'never'.
 */
function tb_last_seen_label(?string $lastSeenAt, ?int $now = null): string {
    if ($lastSeenAt === null || $lastSeenAt === '') return 'never';
    $ts = strtotime($lastSeenAt);
    if ($ts === false) return 'unknown';
    $s = ($now ?? tb_default_now_ts()) - $ts;
    if ($s < 0) $s = 0;   // a clock hiccup must not read negative
    if ($s < 60) return 'just now';
    if ($s < 3600) {   // whole minutes: the task's '23 minutes ago'
        $m = intdiv($s, 60);
        return $m === 1 ? '1 minute ago' : $m . ' minutes ago';
    }
    if ($s < 86400) {  // the task's '1h 12m ago'
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);
        return $h . 'h' . ($m > 0 ? ' ' . $m . 'm' : '') . ' ago';
    }
    if ($s < 30 * 86400) {   // '3d 4h ago' for the recent past
        $d = intdiv($s, 86400);
        $h = intdiv($s % 86400, 3600);
        return $d . 'd' . ($h > 0 ? ' ' . $h . 'h' : '') . ' ago';
    }
    return intdiv($s, 86400) . 'd ago';   // and plain days for the long tail
}

// The traffic-light thresholds of the activity dot (#30): green = seen within
// the last 15 minutes, yellow = seen 15-60 minutes ago, red = seen longer ago.
// Named constants so the admin wording and the tests stay in lockstep.
const TB_ACTIVITY_GREEN_AFTER_MINUTES = 15;
const TB_ACTIVITY_YELLOW_AFTER_MINUTES = 60;

/**
 * The clock the activity ages are computed against (#14): the database's own
 * wall clock, captured once per request in index.php — every last_seen_at
 * stamp is written with CURRENT_TIMESTAMP on that clock, so comparing against
 * PHP's time() instead makes a PHP/database timezone mismatch age every user
 * by the offset (admin's "the dot is always red" report on #30: labels said
 * '1 minute ago' while the same stamp read as hours old). Tests and other
 * callers that need a pinned clock pass $now explicitly; anything that does
 * not gets the DB clock when the page captured one, else PHP's clock.
 */
function tb_default_now_ts(): int {
    $db = $GLOBALS['TB_DB_NOW_TS'] ?? null;
    return is_int($db) ? $db : time();
}

/**
 * The activity level of a user stamp: 'green', 'yellow' or 'red' (#30). Pure:
 * pass $now (a unix timestamp) to pin the clock — call sites that already read
 * the database's clock feed it here; tests pass a fixed one. A user without a
 * stamp (never seen since the feature landed) or an unparsable one sits at the
 * red end of the scale, which is what "inactive for over an hour" includes.
 */
function tb_activity_level(?string $lastSeenAt, ?int $now = null): string {
    if ($lastSeenAt === null || $lastSeenAt === '') return 'red';
    $ts = strtotime($lastSeenAt);
    if ($ts === false) return 'red';
    // Second granularity (#14): the level changes at exactly 15:00 and 60:00
    // minutes, and minute-truncation makes 59:59 and 60:01 the same bucket as
    // the boundaries themselves, which contradicts the documented thresholds.
    $s = max(0, ($now ?? tb_default_now_ts()) - $ts);
    if ($s <= TB_ACTIVITY_GREEN_AFTER_MINUTES * 60) return 'green';
    if ($s <= TB_ACTIVITY_YELLOW_AFTER_MINUTES * 60) return 'yellow';
    return 'red';
}

/**
 * The activity dot next to a user name (#30): a small green/yellow/red disc
 * with a title attribute naming the state, so the color is never the only
 * carrier of the meaning.
 */
function tb_activity_dot(?string $lastSeenAt, ?int $now = null): string {
    $titles = [
        'green' => 'Active in the last 15 minutes',
        'yellow' => 'Not active in the last hour',
        'red' => 'Inactive for over an hour',
    ];
    $level = tb_activity_level($lastSeenAt, $now);
    return '<span class="tb-activity-dot tb-activity-' . $level . '" title="' . $titles[$level] . '"></span>';
}

/**
 * THE standard way to render a user name anywhere on the board (#30): the
 * activity dot followed by the escaped display name. Deleted users (a LEFT
 * JOIN miss or an empty name) render as "Deleted user" without a dot — there
 * is no account left whose activity could be measured. $user is any row or
 * array that carries display_name and (optionally) last_seen_at.
 */
function tb_user_with_activity(?array $user, ?int $now = null): string {
    if ($user === null || !isset($user['display_name']) || $user['display_name'] === null || $user['display_name'] === '') {
        return h('Deleted user');
    }
    return tb_activity_dot($user['last_seen_at'] ?? null, $now) . h($user['display_name']);
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

    // Saved searches (#29): the task list page reads the saved_searches table
    // unconditionally, so a deployment that lands without the upgrade SQL
    // recreates the table on the first request instead of fataling.
    $hasSavedSearches = $isMysql
        ? (bool)$pdo->query("SHOW TABLES LIKE 'saved_searches'")->fetchColumn()
        : (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='saved_searches'")->fetchColumn();
    if (!$hasSavedSearches) {
        if ($isMysql) {
            $pdo->exec("CREATE TABLE saved_searches (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                name VARCHAR(200) NOT NULL,
                query TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_saved_search_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE saved_searches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                query TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_saved_search_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )");
        }
    }

    // Last activity (#30): the admin page reads users.last_seen_at
    // unconditionally, so a deployment that lands without the upgrade SQL
    // adds the column on the first request instead of fataling. Skipped when
    // the users table is missing entirely: a board without a users table is
    // pre-install and gets the full schema.sql (column included) from the
    // install guard.
    $hasUsers = $isMysql
        ? (bool)$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn()
        : (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    if ($hasUsers) {
        $userColumns = $isMysql
            ? array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC), 'Field')
            : array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('last_seen_at', $userColumns, true)) {
            $pdo->exec($isMysql
                ? 'ALTER TABLE users ADD COLUMN last_seen_at TIMESTAMP NULL DEFAULT NULL AFTER created_at'
                : 'ALTER TABLE users ADD COLUMN last_seen_at TEXT NULL DEFAULT NULL');
        }
    }
}
