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
    // Notifications (#58): every event-level history row lands in the stream
    // of the task's assignees, minus whoever made the change. The per-field
    // audit rows written by history_change() deliberately stay silent, so one
    // task save pings each assignee exactly once.
    tb_notify_task_assignees($pdo, $taskId, (int)(user()['id'] ?? 0), $event, (int)$pdo->lastInsertId());
}

/**
 * Resolves the comment a "Comment added" history row refers to, or null when
 * it cannot be determined. Rows written since #62 embed the exact reference
 * as "(comment:<id>)" at the end of the details; rows written before that
 * carry the comment body itself (#54 v2) and are matched positionally: every
 * "Comment added" row is recorded immediately after its comment insert, so
 * among this task's comments written by the same author up to the row's
 * timestamp the newest one is the match. The task page uses this to link its
 * history rows to the comment itself instead of repeating the comment text
 * on the page (#62).
 *
 * @param array<string,mixed> $row One row from task_history.
 * @param list<array<string,mixed>> $comments The comments of the same task.
 */
function history_comment_id(array $row, array $comments): ?int {
    if (($row['event'] ?? '') !== 'Comment added') return null;
    if (preg_match('/\(comment:(\d+)\)\s*$/', (string)($row['details'] ?? ''), $m)) return (int)$m[1];
    $best = null;
    foreach ($comments as $c) {
        if ((int)($c['user_id'] ?? 0) !== (int)($row['user_id'] ?? 0)) continue;
        if ((string)($c['created_at'] ?? '') > (string)($row['created_at'] ?? '')) continue;
        // Lexicographic order on "Y-m-d H:i:s" equals chronological order, and
        // the tie-break on the id keeps the newest inserted comment winning.
        if ($best === null || [$c['created_at'], (int)$c['id']] > [$best['created_at'], (int)$best['id']]) $best = $c;
    }
    return $best === null ? null : (int)$best['id'];
}

/** Full audit
 * field name and its from/to values verbatim (human-readable: status and
 * project names, task titles, assignee display names). The change details
 * page (?page=task_history&id=N) renders exactly that. */
function history_change(PDO $pdo, int $taskId, string $field, string $label, string $oldValue, string $newValue): void {
    $s = $pdo->prepare('INSERT INTO task_history(task_id,user_id,event,details,field,old_value,new_value) VALUES(?,?,?,?,?,?,?)');
    $s->execute([$taskId, user()['id'], 'Field changed', $label . ' changed', $field, $oldValue, $newValue]);
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

function user_exists(PDO $pdo, int $id): bool {
    $s = $pdo->prepare('SELECT 1 FROM users WHERE id=?');
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
 * Builds the payload stored in $_SESSION['user'] from a users row. The single
 * place that shapes the signed-in user's session: whenever the session is
 * rebuilt (sign-in, self-edit on the admin Users page) every field survives,
 * including the theme preference - dropping it used to flip dark mode back to
 * light after renaming your own account (#61).
 *
 * @param array<string,mixed> $u A row from the users table.
 * @return array{id:int,username:string,display_name:string,role:string,theme:string,last_seen_at:mixed}
 */
function tb_session_user(array $u): array {
    return [
        'id' => (int)$u['id'],
        'username' => (string)$u['username'],
        'display_name' => (string)$u['display_name'],
        'role' => (string)$u['role'],
        'theme' => normalize_theme($u['theme'] ?? null),
        'last_seen_at' => $u['last_seen_at'] ?? null,
    ];
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
 * Builds the task filter WHERE fragment the task list and the kanban board
 * (#43) share, straight from the request URL: archived tasks are always
 * hidden, the assignee/status/parent/project multi-select filters combine
 * OR inside one dropdown and AND across dropdowns, and ?q= searches the
 * title and description. Every id reaches the query as a bound parameter
 * and unknown values are ignored, so a URL can never inject SQL.
 *
 * Returns the SQL fragment (starting with a condition, no leading WHERE)
 * plus the normalized filter values, so the shared filter card can mark the
 * selected options and the page can build links that keep the filters.
 *
 * @return array{where: string[], params: array<int,string|int>, fAssignees: string[], fStatuses: string[], fParents: string[], fProjects: string[], projectIds: int[]}
 */
function tb_task_filters_where(): array {
    $where = ['t.archived_at IS NULL']; // archived tasks (#12) are shown only in the archive
    $params = [];
    $fAssignees = filter_values('assignee');
    $assigneeFilter = filter_split($fAssignees, ['unassigned']);
    if ($assigneeFilter['keywords'] || $assigneeFilter['ids']) {
        $assigneeConds = [];
        if ($assigneeFilter['keywords']) $assigneeConds[] = 'NOT EXISTS(SELECT 1 FROM task_assignees ax WHERE ax.task_id=t.id)';
        if ($assigneeFilter['ids']) {
            $assigneeConds[] = 'EXISTS(SELECT 1 FROM task_assignees ax WHERE ax.task_id=t.id AND ax.user_id IN (' . tb_in_placeholders(count($assigneeFilter['ids'])) . '))';
            array_push($params, ...$assigneeFilter['ids']);
        }
        $where[] = '(' . implode(' OR ', $assigneeConds) . ')';
    }
    $fStatuses = filter_values('status');
    $statusIds = filter_split($fStatuses, [])['ids'];
    if ($statusIds) {
        $where[] = 't.status_id IN (' . tb_in_placeholders(count($statusIds)) . ')';
        array_push($params, ...$statusIds);
    }
    $fParents = filter_values('parent');
    $parentFilter = filter_split($fParents, ['none']);
    if ($parentFilter['keywords'] || $parentFilter['ids']) {
        $parentConds = [];
        if ($parentFilter['keywords']) $parentConds[] = 't.parent_id IS NULL';
        if ($parentFilter['ids']) {
            $parentConds[] = 't.parent_id IN (' . tb_in_placeholders(count($parentFilter['ids'])) . ')';
            array_push($params, ...$parentFilter['ids']);
        }
        $where[] = '(' . implode(' OR ', $parentConds) . ')';
    }
    $fProjects = filter_values('project');
    // 'No project' option (#28): the keyword filters tasks without a project,
    // ids filter by project; both may be combined (OR), like the parent filter.
    $projectFilter = filter_split($fProjects, ['none']);
    $projectIds = $projectFilter['ids'];
    if ($projectFilter['keywords'] || $projectFilter['ids']) {
        $projectConds = [];
        if ($projectFilter['keywords']) $projectConds[] = 't.project_id IS NULL';
        if ($projectFilter['ids']) {
            $projectConds[] = 't.project_id IN (' . tb_in_placeholders(count($projectFilter['ids'])) . ')';
            array_push($params, ...$projectFilter['ids']);
        }
        $where[] = '(' . implode(' OR ', $projectConds) . ')';
    }
    if (!empty($_GET['q'])) {
        $tbTerm = str_replace(['|', '%', '_'], ['||', '|%', '|_'], (string)$_GET['q']);
        $where[] = "(t.title LIKE ? ESCAPE '|' OR t.description LIKE ? ESCAPE '|')";
        $params[] = '%' . $tbTerm . '%';
        $params[] = '%' . $tbTerm . '%';
    }
    return ['where' => $where, 'params' => $params, 'fAssignees' => $fAssignees, 'fStatuses' => $fStatuses, 'fParents' => $fParents, 'fProjects' => $fProjects, 'projectIds' => $projectIds];
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

// Upload allowlist (#53): one list for the task attachments and the
// know-how attachments, so both upload surfaces accept exactly the same
// file types and the finfo-checked MIME must match it before a file is
// stored.
const TASK_BOARD_UPLOAD_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'text/plain', 'application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

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

/**
 * Renders the task filter card with the saved searches row, shared by the
 * task list, the kanban board (#43) and the waterflow chart (#44) so all
 * pages stay in lockstep (DRY). Since #48 only the free text search bar
 * shows by default; an Advanced filter toggle reveals the dropdowns, the
 * Filter button and the saved searches, and the area starts expanded when
 * the current view already filters by one of the advanced keys.
 * The GET form submits into ?page=<page>&...; the saved-search links open on
 * the same page kind they were saved from, and the save form posts the
 * current filters (the same whitelisted keys the save handler stores).
 *
 * $ctx keys:
 *   page           - page the filters target and saved searches open ('tasks' or 'kanban')
 *   users          - assignable users (id, display_name) for the assignee dropdown
 *   statuses       - all statuses (id, name) for the status dropdown
 *   parents        - parent task options (parent_id, parent_title)
 *   projectOptions - project options (id, name)
 *   savedSearches  - the signed-in user's saved searches (id, name, query)
 *   returnUrl      - where the delete-search action returns to (allowlisted page prefix)
 */
function tb_render_task_filter_card(array $ctx): void {
    $page = $ctx['page'];
    $users = $ctx['users'];
    $statuses = $ctx['statuses'];
    $parents = $ctx['parents'];
    $projectOptions = $ctx['projectOptions'];
    $savedSearches = $ctx['savedSearches'];
    $fAssignees = $ctx['fAssignees'];
    $fStatuses = $ctx['fStatuses'];
    $fParents = $ctx['fParents'];
    $fProjects = $ctx['fProjects'];
    $returnUrl = $ctx['returnUrl'];
    // #48: the advanced area (the four dropdowns, the Filter button and the
    // saved searches) hides behind the Advanced filter toggle - the free text
    // search bar is the only thing on the card by default. The area starts
    // expanded when the current view is already filtered by one of the advanced
    // keys, so an active filter is never hidden from the operator (the same
    // idea as the scheduler area on the task form, #35).
    $advancedActive = $fAssignees !== [] || $fStatuses !== [] || $fParents !== [] || $fProjects !== [];
    ?>
<div class="card p-3 mb-3"><form class="row g-2 align-items-end"><input type="hidden" name="page" value="<?=h($page)?>"><?php foreach (array_diff(TASK_BOARD_SEARCH_FILTER_KEYS, ['q']) as $fk): foreach (filter_values($fk) as $fv): ?><input type="hidden" name="<?=$fk?>[]" value="<?=h($fv)?>"><?php endforeach; endforeach; ?><div class="col-md-2"><input class="form-control" name="q" value="<?=h($_GET['q']??'')?>" placeholder="Search tasks" aria-label="Search tasks"></div><div class="col-md-auto d-grid"><button class="btn btn-outline-secondary<?= $advancedActive ? ' border-primary' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#advanced-filters" aria-expanded="<?= $advancedActive ? 'true' : 'false' ?>" aria-controls="advanced-filters">Advanced filter<?= $advancedActive ? ' <span class="badge text-bg-info ms-1">on</span>' : '' ?></button></div></form><?php // #48: the advanced area (dropdowns, Filter button, saved searches) is the toggleable rest of the card - hidden by default, expanded when one of the advanced filters is active in the current view. ?><div class="collapse<?= $advancedActive ? ' show' : '' ?>" id="advanced-filters"><div><form class="row g-2 align-items-end"><input type="hidden" name="page" value="<?=h($page)?>"><input type="hidden" name="q" value="<?=h($_GET['q']??'')?>"><div class="col-md-2"><label class="form-label small muted mb-1" for="filter-assignee">Assignees</label><select class="form-select" id="filter-assignee" name="assignee[]" multiple size="4"><option value="unassigned" <?=in_array('unassigned',$fAssignees,true)?'selected':''?>>Unassigned</option><?php foreach($users as $u): ?><option value="<?=$u['id']?>" <?=in_array((string)$u['id'],$fAssignees,true)?'selected':''?>><?=h($u['display_name'])?></option><?php endforeach; if(!$users): ?><option value="" disabled>No users yet</option><?php endif; ?></select></div><div class="col-md-2"><label class="form-label small muted mb-1" for="filter-status">Statuses</label><select class="form-select" id="filter-status" name="status[]" multiple size="4"><?php foreach($statuses as $st): ?><option value="<?=$st['id']?>" <?=in_array((string)$st['id'],$fStatuses,true)?'selected':''?>><?=h($st['name'])?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label small muted mb-1" for="filter-parent">Parents</label><select class="form-select" id="filter-parent" name="parent[]" multiple size="4"><option value="none" <?=in_array('none',$fParents,true)?'selected':''?>>No parent</option><?php foreach($parents as $pt): ?><option value="<?=$pt['parent_id']?>" <?=in_array((string)$pt['parent_id'],$fParents,true)?'selected':''?>>#<?=$pt['parent_id']?> · <?=h($pt['parent_title'])?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label small muted mb-1" for="filter-project">Projects</label><select class="form-select" id="filter-project" name="project[]" multiple size="4"><option value="none" <?=in_array('none',$fProjects,true)?'selected':''?>>No project</option><?php foreach($projectOptions as $po): ?><option value="<?=$po['id']?>" <?=in_array((string)$po['id'],$fProjects,true)?'selected':''?>>#<?=$po['id']?> · <?=h($po['name'])?></option><?php endforeach; ?></select></div><div class="col-md-2 d-grid"><button class="btn btn-outline-primary">Filter</button></div><div class="col-12"><div class="form-text mb-0">Hold Ctrl/Cmd to pick several values in a dropdown; an empty dropdown filters nothing. Values inside one dropdown match any of them, different dropdowns combine.</div></div></form>
<div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mt-3 pt-3 border-top"><?php if($savedSearches): ?><div class="dropdown"><button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">Saved searches <span class="badge text-bg-secondary"><?=count($savedSearches)?></span></button><div class="dropdown-menu"><?php foreach($savedSearches as $sv): ?><div class="dropdown-item d-flex align-items-center justify-content-between gap-3 text-nowrap"><a class="text-decoration-none flex-grow-1" href="<?=h('?page='.$page.'&'.$sv['query'])?>" title="Open the saved search <?=h($sv['name'])?>"><?=h($sv['name'])?></a><form method="post" class="d-inline" onsubmit="return confirm('Delete this saved search?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_search"><input type="hidden" name="id" value="<?=$sv['id']?>"><input type="hidden" name="return" value="<?=h($returnUrl)?>"><button class="btn btn-sm btn-link text-danger p-0 lh-1" title="Delete this saved search" aria-label="Delete the saved search <?=h($sv['name'])?>">&times;</button></form></div><?php endforeach; ?></div></div><?php else: ?><span class="small muted" id="saved-searches-empty">No saved searches yet.</span><?php endif; ?>
    <form method="post" class="d-flex align-items-center gap-2"><?=csrf_field()?><input type="hidden" name="action" value="save_search"><input type="hidden" name="save_page" value="<?=h($page)?>"><label class="visually-hidden" for="save-search-name">Name this search</label><input class="form-control" id="save-search-name" name="name" maxlength="200" placeholder="Name this search" aria-label="Name for the saved search" required><button class="btn btn-outline-primary text-nowrap" title="Save the current list filters as a search">Save search</button><?php foreach (TASK_BOARD_SEARCH_KEYS as $fk): if (($fv=filter_query_value($fk))===null) continue; if (is_array($fv)): foreach ($fv as $fvOne): ?><input type="hidden" name="<?=$fk?>[]" value="<?=h($fvOne)?>"><?php endforeach; else: ?><input type="hidden" name="<?=$fk?>" value="<?=h($fv)?>"><?php endif; endforeach; ?></form></div></div></div></div>
    <?php
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
// Named constants so the admin wording and the tests stay in lockstep. Since
// #34 they double as the DEFAULTS of the configurable periods: the settings
// page (?page=activity_settings) stores an administrator-defined period list
// in the app_settings table, and these constants keep the pre-#34 behaviour
// byte-identical until somebody actually saves their own periods.
const TB_ACTIVITY_GREEN_AFTER_MINUTES = 15;
const TB_ACTIVITY_YELLOW_AFTER_MINUTES = 60;

/** Colours a period may use for its dot (#34); one CSS class each in the page style block. */
const TB_ACTIVITY_COLORS = ['green', 'yellow', 'red', 'grey'];

/** app_settings key (#34) under which the configured period list is stored as JSON. */
const TB_ACTIVITY_SETTINGS_KEY = 'activity_levels';

/**
 * The built-in activity periods (#34): the exact pre-#34 behaviour expressed
 * as a period list, so an untouched board renders the same dots as before.
 * Shape of every period (here and in the stored JSON):
 *   ['until_minutes' => ?int, 'color' => ?string, 'character' => ?string]
 * until_minutes is the inclusive upper age bound in minutes; null (allowed
 * only on the last period) means "forever". A period renders as a coloured
 * dot when it carries a colour, else as its character glyph.
 */
function tb_activity_levels_default(): array {
    return [
        ['until_minutes' => TB_ACTIVITY_GREEN_AFTER_MINUTES, 'color' => 'green', 'character' => null],
        ['until_minutes' => TB_ACTIVITY_YELLOW_AFTER_MINUTES, 'color' => 'yellow', 'character' => null],
        ['until_minutes' => null, 'color' => 'red', 'character' => null],
    ];
}

/**
 * Validates a period list (#34). Returns null when the list is usable, else
 * the message for the settings form. Rules: 2-5 periods; every period shows
 * exactly one of a known colour or a 1-3 character glyph; every period but
 * the last has a positive minute bound; the bounds increase strictly, so the
 * periods tile the timeline without gaps or overlaps.
 */
function tb_activity_levels_validation_error(array $levels): ?string {
    $count = count($levels);
    if ($count < 2) return 'Define at least two periods.';
    if ($count > 5) return 'At most five periods are supported.';
    $previous = null;
    foreach ($levels as $i => $level) {
        $label = 'Period ' . ($i + 1);
        if (!is_array($level)) return $label . ' is malformed.';
        $minutes = $level['until_minutes'] ?? null;
        $color = $level['color'] ?? null;
        $character = $level['character'] ?? null;
        $isLast = $i === $count - 1;
        if ($minutes === null) {
            if (!$isLast) return $label . ' needs an upper minute bound - only the last period may run forever.';
        } else {
            if (!is_int($minutes) || $minutes < 1 || $minutes > 525600) return $label . ' needs a bound between 1 and 525600 minutes (or an empty bound on the last period).';
            if ($previous !== null && $minutes <= $previous) return $label . ' must start after the previous period ends: the bounds have to increase.';
        }
        if ($color !== null && $character !== null) return $label . ' must pick one: a colour or a character.';
        if ($color !== null) {
            if (!in_array($color, TB_ACTIVITY_COLORS, true)) return $label . ' uses an unknown colour.';
        } elseif ($character !== null) {
            if (trim($character) === '') return $label . ' cannot show an empty character.';
            if (preg_match_all('/./us', $character) > 3) return $label . ' character is limited to 3 characters.';
        } else {
            return $label . ' needs a colour or a character.';
        }
        $previous = $minutes;
    }
    return null;
}

/**
 * Builds the period list from the settings form's rows (#34). $rows is the
 * levels[minutes][] / levels[style][] / levels[character][] arrays PHP
 * assembles from the posted form. Fully unused rows are dropped; a
 * 'color:<name>' style value becomes that colour, the 'character' style
 * takes the row's character field. Validate the result with
 * tb_activity_levels_validation_error() before storing it.
 */
function tb_activity_levels_from_form(array $rows): array {
    $count = is_array($rows['minutes'] ?? null) ? count($rows['minutes']) : 0;
    $levels = [];
    for ($i = 0; $i < $count; $i++) {
        $minutes = trim((string)($rows['minutes'][$i] ?? ''));
        $style = (string)($rows['style'][$i] ?? '');
        $character = trim((string)($rows['character'][$i] ?? ''));
        if ($minutes === '' && $style === '' && $character === '') continue;
        $level = ['until_minutes' => null, 'color' => null, 'character' => null];
        if ($minutes !== '' && preg_match('/^\d+$/', $minutes)) $level['until_minutes'] = (int)$minutes;
        if ($style === 'character') {
            $level['character'] = $character;
        } elseif (str_starts_with($style, 'color:')) {
            $level['color'] = substr($style, 6);
        }
        $levels[] = $level;
    }
    return $levels;
}

/**
 * Reads the configured activity periods from the app_settings table (#34).
 * A missing row, corrupt JSON or an invalid stored list all fall back to the
 * built-in periods - the dots never disappear because of a bad save. This is
 * also the loader index.php uses to fill the request-wide configuration.
 */
function tb_load_activity_levels(PDO $pdo): array {
    try {
        $s = $pdo->prepare('SELECT value FROM app_settings WHERE name=?');
        $s->execute([TB_ACTIVITY_SETTINGS_KEY]);
        $raw = $s->fetchColumn();
    } catch (Throwable $e) {
        return tb_activity_levels_default();   // the table is not there yet: defaults
    }
    if ($raw === false || $raw === null || $raw === '') return tb_activity_levels_default();
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) return tb_activity_levels_default();
    if (tb_activity_levels_validation_error($decoded) !== null) return tb_activity_levels_default();
    return $decoded;
}

/**
 * Stores the configured activity periods (#34). null removes the stored list
 * (the board falls back to the built-in periods). Stored as JSON; a later
 * read re-validates before use, so a hand-edited row cannot break the board.
 */
function tb_save_activity_levels(PDO $pdo, ?array $levels): void {
    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    if ($levels === null) {
        $pdo->prepare('DELETE FROM app_settings WHERE name=?')->execute([TB_ACTIVITY_SETTINGS_KEY]);
        return;
    }
    $json = json_encode(array_values($levels));
    if ($json === false) throw new RuntimeException('Could not encode the activity periods.');
    if ($isMysql) {
        $pdo->prepare('INSERT INTO app_settings(name,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)')->execute([TB_ACTIVITY_SETTINGS_KEY, $json]);
    } else {
        $pdo->prepare('INSERT OR REPLACE INTO app_settings(name,value) VALUES(?,?)')->execute([TB_ACTIVITY_SETTINGS_KEY, $json]);
    }
}

/**
 * The periods a request renders with (#34): an explicit $levels argument
 * wins, else the request-wide configuration index.php loaded from
 * app_settings, else the built-in defaults.
 */
function tb_activity_levels_current(?array $levels = null): array {
    if ($levels !== null) return $levels;
    $global = $GLOBALS['TB_ACTIVITY_LEVELS'] ?? null;
    return is_array($global) && $global !== [] ? $global : tb_activity_levels_default();
}

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
 * The index of the period a last_seen stamp falls into (#34). Pure: pass
 * $now to pin the clock (the pages feed the database's own current
 * timestamp; tests a fixed one) and $levels to pin the configuration. A
 * missing or unparsable stamp lands in the last period - the "long gone"
 * end of the scale, exactly where #30 put it.
 */
function tb_activity_bucket(?string $lastSeenAt, ?int $now = null, ?array $levels = null): int {
    $levels = tb_activity_levels_current($levels);
    if ($lastSeenAt === null || $lastSeenAt === '') return count($levels) - 1;
    $ts = strtotime($lastSeenAt);
    if ($ts === false) return count($levels) - 1;
    // Second granularity (#14): the bucket changes at exactly the boundary
    // seconds, and minute-truncation would make 59:59 and 60:01 the same
    // bucket as the boundaries themselves, which contradicts the thresholds.
    $s = max(0, ($now ?? tb_default_now_ts()) - $ts);
    foreach ($levels as $i => $level) {
        $until = is_array($level) ? ($level['until_minutes'] ?? null) : null;
        if ($until === null || $s <= $until * 60) return (int)$i;
    }
    return count($levels) - 1;
}

/**
 * The activity level of a user stamp (#34): the colour of the period the
 * stamp falls into — 'green', 'yellow', 'red' and, since the settings page
 * can add one, 'grey'. A period configured to render as a character instead
 * of a colour reports 'red' (the safe end of the scale) so callers that only
 * know colours keep working. With the default periods in effect the result
 * is exactly the pre-#34 green/yellow/red (#30). Pure: pass $now to pin the
 * clock; pass $levels to pin the configuration.
 */
function tb_activity_level(?string $lastSeenAt, ?int $now = null, ?array $levels = null): string {
    $levels = tb_activity_levels_current($levels);
    $bucket = tb_activity_bucket($lastSeenAt, $now, $levels);
    $color = is_array($levels[$bucket] ?? null) ? ($levels[$bucket]['color'] ?? null) : null;
    return $color ?? 'red';
}

/**
 * The human title of one period (#34), for the dot's title attribute so the
 * colour is never the only carrier of the meaning. The built-in periods keep
 * the exact #30 wording; administrator-configured periods get generated
 * wording from their bounds.
 */
function tb_activity_level_title(int $bucket, ?array $levels = null): string {
    $levels = tb_activity_levels_current($levels);
    $isDefault = $levels === tb_activity_levels_default();
    if ($isDefault) {
        $legacy = ['Active in the last 15 minutes', 'Not active in the last hour', 'Inactive for over an hour'];
        return $legacy[$bucket] ?? 'Inactive for over an hour';
    }
    $until = is_array($levels[$bucket] ?? null) ? ($levels[$bucket]['until_minutes'] ?? null) : null;
    if ($until === null) return 'Last seen longer ago than every period above';
    if ($until < 60) return 'Seen within the last ' . $until . ' minutes';
    if ($until === 60) return 'Seen within the last hour';
    if ($until === 1440) return 'Seen within the last day';
    if ($until % 60 === 0) return 'Seen within the last ' . intdiv($until, 60) . ' hours';
    return 'Seen within the last ' . $until . ' minutes';
}

/**
 * The activity indicator for one period index (#34) — tb_activity_dot()
 * renders this, and the settings page's legend previews each period with it
 * without fabricating stamps. A period with a colour renders as a coloured
 * disc; a character-only period renders its glyph. Both carry a title naming
 * the state, so the colour is never the only carrier of the meaning.
 */
function tb_activity_dot_for_bucket(int $bucket, ?array $levels = null): string {
    $levels = tb_activity_levels_current($levels);
    $bucket = max(0, min($bucket, count($levels) - 1));
    $title = tb_activity_level_title($bucket, $levels);
    $level = is_array($levels[$bucket] ?? null) ? $levels[$bucket] : [];
    if (($level['character'] ?? null) !== null && ($level['color'] ?? null) === null) {
        return '<span class="tb-activity-char" title="' . h($title) . '">' . h((string)$level['character']) . '</span>';
    }
    return '<span class="tb-activity-dot tb-activity-' . ($level['color'] ?? 'red') . '" title="' . h($title) . '"></span>';
}

/**
 * The activity dot next to a user name (#30): since #34 it renders the
 * request's configured periods (see tb_activity_levels_current()); with the
 * built-in ones in effect the markup is byte-identical to the #30 output.
 */
function tb_activity_dot(?string $lastSeenAt, ?int $now = null, ?array $levels = null): string {
    return tb_activity_dot_for_bucket(tb_activity_bucket($lastSeenAt, $now, $levels), $levels);
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
    // Complexity estimation (#44): the waterflow chart and the task form read
    // tasks.complexity unconditionally, so a deployment that lands without the
    // upgrade SQL adds the column on the first request. Fresh installs get it
    // from schema.sql.
    if (!in_array('complexity', $taskColumns, true)) {
        $pdo->exec($isMysql
            ? 'ALTER TABLE tasks ADD COLUMN complexity INT UNSIGNED NOT NULL DEFAULT 1 AFTER status_id'
            : 'ALTER TABLE tasks ADD COLUMN complexity INTEGER NOT NULL DEFAULT 1');
    }
    // Priority (#45): the task list's sortable Priority column and the task
    // form read tasks.priority unconditionally, so a deployment that lands
    // without the upgrade SQL adds the column on the first request. Fresh
    // installs get it from schema.sql. Runs after the complexity ALTER so the
    // MySQL "AFTER complexity" placement finds its anchor column even on a
    // table that needed both upgrades in the same request.
    if (!in_array('priority', $taskColumns, true)) {
        $pdo->exec($isMysql
            ? 'ALTER TABLE tasks ADD COLUMN priority INT UNSIGNED NOT NULL DEFAULT 1 AFTER complexity'
            : 'ALTER TABLE tasks ADD COLUMN priority INTEGER NOT NULL DEFAULT 1');
    }
    // Scheduler targets (#31): must run after the schedule_period ALTER so the
    // MySQL "AFTER schedule_period" placement finds its anchor column even on
    // a table that needed both upgrades in the same request.
    if (!in_array('schedule_status_id', $taskColumns, true)) {
        $pdo->exec($isMysql
            ? 'ALTER TABLE tasks ADD COLUMN schedule_status_id INT UNSIGNED NULL DEFAULT NULL AFTER schedule_period'
            : 'ALTER TABLE tasks ADD COLUMN schedule_status_id INTEGER NULL DEFAULT NULL');
    }
    if (!in_array('schedule_assignees', $taskColumns, true)) {
        $pdo->exec($isMysql
            ? 'ALTER TABLE tasks ADD COLUMN schedule_assignees TEXT NULL DEFAULT NULL AFTER schedule_status_id'
            : 'ALTER TABLE tasks ADD COLUMN schedule_assignees TEXT NULL DEFAULT NULL');
    }
    if (!in_array('archived_at', $taskColumns, true)) {
        $pdo->exec($isMysql
            ? 'ALTER TABLE tasks ADD COLUMN archived_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at'
            : 'ALTER TABLE tasks ADD COLUMN archived_at TEXT NULL DEFAULT NULL');
    }

    // Full audit (#54): task_history gains the field/old_value/new_value
    // columns on existing boards, so the change details page can show who
    // changed what from what for every save. Fresh installs get them from
    // schema.sql; the older rows keep NULL and render as event entries.
    $historyColumns = $isMysql
        ? array_column($pdo->query('SHOW COLUMNS FROM task_history')->fetchAll(PDO::FETCH_ASSOC), 'Field')
        : array_column($pdo->query('PRAGMA table_info(task_history)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['field' => 'VARCHAR(40) NULL DEFAULT NULL', 'old_value' => 'TEXT NULL DEFAULT NULL', 'new_value' => 'TEXT NULL DEFAULT NULL'] as $auditColumn => $auditSql) {
        if (!in_array($auditColumn, $historyColumns, true)) {
            $pdo->exec($isMysql
                ? 'ALTER TABLE task_history ADD COLUMN ' . $auditColumn . ' ' . $auditSql
                : 'ALTER TABLE task_history ADD COLUMN ' . $auditColumn . ' TEXT NULL DEFAULT NULL');
        }
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

    // Status colours (#42): the statuses page reads/writes statuses.color and
    // the badge renderings select it unconditionally, so a deployment that
    // lands without the upgrade SQL adds the column on the first request.
    // Skipped when the statuses table is missing entirely: a board without a
    // statuses table is pre-install and gets the full schema.sql (column
    // included) from the install guard.
    $hasStatuses = $isMysql
        ? (bool)$pdo->query("SHOW TABLES LIKE 'statuses'")->fetchColumn()
        : (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='statuses'")->fetchColumn();
    if ($hasStatuses) {
        $statusColumns = $isMysql
            ? array_column($pdo->query('SHOW COLUMNS FROM statuses')->fetchAll(PDO::FETCH_ASSOC), 'Field')
            : array_column($pdo->query('PRAGMA table_info(statuses)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('color', $statusColumns, true)) {
            $pdo->exec($isMysql
                ? 'ALTER TABLE statuses ADD COLUMN color VARCHAR(7) NULL DEFAULT NULL AFTER is_done'
                : 'ALTER TABLE statuses ADD COLUMN color TEXT NULL DEFAULT NULL');
        }
    }

    // Activity status periods (#34): the settings page stores the configured
    // period list in the app_settings table and the board reads it
    // unconditionally on every request, so a deployment that lands without
    // the upgrade SQL recreates the table on the first request. An absent
    // row simply means the built-in periods are in effect.
    $hasAppSettings = $isMysql
        ? (bool)$pdo->query("SHOW TABLES LIKE 'app_settings'")->fetchColumn()
        : (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='app_settings'")->fetchColumn();
    if (!$hasAppSettings) {
        if ($isMysql) {
            $pdo->exec("CREATE TABLE app_settings (
                name VARCHAR(80) PRIMARY KEY,
                value TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE app_settings (
                name TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )");
        }
    }

    // Know-hows (#53): the documents pages read the three knowledge-base
    // tables unconditionally, so a deployment that lands without the upgrade
    // SQL recreates them on the first request instead of fataling with
    // "table documents doesn't exist" (42S02) on every existing board.
    // Fresh installs get them from schema.sql. The DDL mirrors schema.sql;
    // the SQLite blocks follow the established projects/saved_searches style.
    $hasDocuments = $isMysql
        ? (bool)$pdo->query("SHOW TABLES LIKE 'documents'")->fetchColumn()
        : (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='documents'")->fetchColumn();
    if (!$hasDocuments) {
        if ($isMysql) {
            $pdo->exec("CREATE TABLE documents (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(200) NOT NULL,
                content TEXT NULL,
                created_by INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_document_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE document_comments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                document_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE document_attachments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                document_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NULL,
                stored_name VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(120) NOT NULL,
                file_size INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE documents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                content TEXT NULL,
                created_by INTEGER NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_document_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )");
            $pdo->exec("CREATE TABLE document_comments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                user_id INTEGER NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )");
            $pdo->exec("CREATE TABLE document_attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                document_id INTEGER NOT NULL,
                user_id INTEGER NULL,
                stored_name TEXT NOT NULL,
                original_name TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                file_size INTEGER NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )");
        }
    }

    // Message board (#56): the messages page and the navbar's unread badge
    // read the messages table unconditionally, so a deployment that lands
    // without the upgrade SQL recreates it on the first request. Fresh
    // installs get it from schema.sql; the DDL mirrors schema.sql.
    $hasMessages = $isMysql
        ? (bool)$pdo->query("SHOW TABLES LIKE 'messages'")->fetchColumn()
        : (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='messages'")->fetchColumn();
    if (!$hasMessages) {
        if ($isMysql) {
            $pdo->exec("CREATE TABLE messages (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sender_id INT UNSIGNED NULL,
                recipient_id INT UNSIGNED NULL,
                body TEXT NOT NULL,
                is_read TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sender_id INTEGER NULL,
                recipient_id INTEGER NULL,
                body TEXT NOT NULL,
                is_read INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE SET NULL
            )");
        }
    }

    // Notifications (#58): the navbar badge and the notifications page read
    // the notifications table unconditionally, so a deployment that lands
    // without the upgrade SQL recreates it on the first request - the same
    // self-heal discipline the messages table follows (#56). Fresh installs
    // get it from schema.sql; the DDL mirrors schema.sql.
    $hasNotifications = $isMysql
        ? (bool)$pdo->query("SHOW TABLES LIKE 'notifications'")->fetchColumn()
        : (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='notifications'")->fetchColumn();
    if (!$hasNotifications) {
        if ($isMysql) {
            $pdo->exec("CREATE TABLE notifications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                task_id INT UNSIGNED NOT NULL,
                history_id INT UNSIGNED NULL,
                event VARCHAR(100) NOT NULL,
                is_seen TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                FOREIGN KEY (history_id) REFERENCES task_history(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                task_id INTEGER NOT NULL,
                history_id INTEGER NULL,
                event TEXT NOT NULL,
                is_seen INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                FOREIGN KEY (history_id) REFERENCES task_history(id) ON DELETE SET NULL
            )");
        }
    }

    // Version-marked migrations (#59/#68): the migration runner records every
    // applied migrations/<version>-<slug>.sql file in this table, so the
    // board reads the table unconditionally on every request. A deployment
    // that lands without the upgrade SQL recreates it on the first request -
    // the same self-heal discipline the messages/notifications tables follow
    // (#56/#58). Fresh installs get it from schema.sql; the DDL mirrors it.
    $hasMigrations = $isMysql
        ? (bool)$pdo->query("SHOW TABLES LIKE 'migrations'")->fetchColumn()
        : (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='migrations'")->fetchColumn();
    if (!$hasMigrations) {
        if ($isMysql) {
            $pdo->exec("CREATE TABLE migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                version VARCHAR(64) NOT NULL UNIQUE,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } else {
            $pdo->exec("CREATE TABLE migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                version TEXT NOT NULL UNIQUE,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
        }
    }
}

/**
 * Lists the migration scripts in $dir (#59/#68). A migration file is named
 * <version>-<slug>.sql where <version> is a 14-digit UTC stamp
 * (YYYYMMDDHHMMSS) that sorts lexicographically in execution order; the slug
 * is optional and only there for humans. Anything else in the folder is
 * ignored, so notes, exports and half-renamed files never run.
 *
 * @return array<int,array{version:string,file:string}> sorted by version, then filename
 */
function tb_migration_scan(string $dir): array {
    if (!is_dir($dir)) return [];
    $found = [];
    foreach (scandir($dir) ?: [] as $file) {
        if (!str_ends_with(strtolower($file), '.sql')) continue;
        if (preg_match('/^(\d{14})(?:-[A-Za-z0-9._-]+)?\.sql$/i', $file, $m) !== 1) continue;
        $found[] = ['version' => $m[1], 'file' => $file];
    }
    usort($found, fn(array $a, array $b): int => [$a['version'], $a['file']] <=> [$b['version'], $b['file']]);
    return $found;
}

/**
 * Splits one migration script into individual statements (#59/#68) with the
 * same mechanical rule the schema importer uses (tests/Support/Schema.php):
 * statements end at a semicolon that closes the line. Migration scripts are
 * plain DDL/DML - triggers and stored procedures with embedded semicolons
 * are out of scope and their fragments would be rejected by the driver
 * anyway. Chunks that hold nothing but comments are dropped, so a trailing
 * comment after the last statement never becomes a bogus empty statement.
 *
 * @return string[] the non-empty statements, in file order
 */
function tb_migration_split_statements(string $sql): array {
    $parts = preg_split('/;[ \t]*(?:--[^\r\n]*\r?\n|(?:\r?\n)|$)/', $sql) ?: [];
    $statements = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (trim((string)preg_replace('/^[\s]*--.*$/m', '', $part)) === '') continue;
        $statements[] = $part;
    }
    return $statements;
}

/**
 * Applies every not-yet-recorded migration script in $dir, oldest first
 * (#59/#68). Each file runs inside one transaction and is recorded in the
 * migrations table only after it committed, so an interrupted run never
 * marks a script as applied and the next request simply retries it. The
 * first failure stops the run: later migrations may depend on earlier ones,
 * and the recorded state stays exactly "what actually ran".
 *
 * On MySQL the driver auto-commits every DDL statement, so a failed
 * multi-statement migration can leave earlier CREATE/ALTER statements in
 * place while the version stays unrecorded - write migration scripts
 * re-runnable (IF [NOT] EXISTS guards) and the retry converges. On SQLite
 * the transaction rolls the failed script back completely.
 *
 * @return array{applied:string[],failed:array<string,string>} applied version stamps and, per failed version, the driver error
 */
function tb_apply_pending_migrations(PDO $pdo, ?string $dir = null): array {
    $result = ['applied' => [], 'failed' => []];
    $dir ??= __DIR__ . '/../migrations';
    try {
        $appliedVersions = $pdo->query('SELECT version FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        // Without the migrations table nothing can be tracked (pre-install
        // board); the install flow and the schema self-heal create it.
        $result['failed']['_'] = 'The migrations table is missing.';
        return $result;
    }
    foreach (tb_migration_scan($dir) as $entry) {
        if (in_array($entry['version'], $appliedVersions, true)) continue;
        $sql = (string)file_get_contents($dir . '/' . $entry['file']);
        if (trim($sql) === '') continue;
        try {
            $pdo->beginTransaction();
            foreach (tb_migration_split_statements($sql) as $statement) {
                $pdo->exec($statement);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $result['failed'][$entry['version']] = $e->getMessage();
            break;
        }
        $pdo->prepare('INSERT INTO migrations(version) VALUES(?)')->execute([$entry['version']]);
        $result['applied'][] = $entry['version'];
    }
    return $result;
}

/**
 * Assigns every task its dependency "water level" (#44): level 0 holds the
 * tasks with no unresolved "Depends on" edge inside the chart, level N the
 * tasks whose prerequisites all sit in earlier levels. Edges that leave the
 * chart (archived or filtered-out prerequisites) cannot block. Cycles can
 * never resolve that way, so anything still unplaced after count($ids) sweeps
 * is pinned one level below the deepest placed task - the cycle stays visible
 * instead of looping forever.
 *
 * @param int[] $ids   every task id that participates in the chart
 * @param array<int,int[]> $deps taskId => list of prerequisite task ids (both sides inside $ids)
 * @return array<int,int> taskId => level
 */
function tb_waterflow_levels(array $ids, array $deps): array {
    $levels = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id > 0) $levels[$id] = null;
    }
    // Keep only edges that stay inside the chart and never point at the task
    // itself; anything else (archived, filtered out, malformed) cannot block.
    $clean = [];
    foreach ((array)$deps as $id => $prereqs) {
        $id = (int)$id;
        if (!array_key_exists($id, $levels)) continue;
        foreach ((array)$prereqs as $prereq) {
            $prereq = (int)$prereq;
            if (array_key_exists($prereq, $levels) && $prereq !== $id) $clean[$id][] = $prereq;
        }
        $clean[$id] = array_values(array_unique($clean[$id] ?? []));
    }
    $deps = $clean;
    $open = count($levels);
    for ($sweep = 0; $sweep <= $open && in_array(null, $levels, true); $sweep++) {
        foreach ($levels as $id => $level) {
            if ($level !== null) continue;
            $next = 0;
            $ready = true;
            foreach ($deps[$id] ?? [] as $prereq) {
                $prereqLevel = $levels[$prereq] ?? null;
                if ($prereqLevel === null) { $ready = false; break; }
                $next = max($next, $prereqLevel + 1);
            }
            if ($ready) $levels[$id] = $next;
        }
    }
    $deepest = 0;
    foreach ($levels as $level) {
        if ($level !== null) $deepest = max($deepest, $level);
    }
    foreach ($levels as $id => $level) {
        if ($level === null) $levels[$id] = $deepest + 1; // dependency cycle: parked below the flow
    }
    return $levels;
}

/**
 * Parses the scheduler's stored target assignees (#31): the comma-separated
 * user id list the task edit form saves into tasks.schedule_assignees.
 * Returns a sorted list of unique positive integer ids, so two lists compare
 * as sets; unparsable fragments and non-positive numbers are dropped. NULL
 * (a task scheduled before #31 existed) and '' mean "no target assignees" —
 * the reopen clears the task's assignees, exactly the #18 behavior.
 */
function schedule_target_assignees(?string $csv): array {
    if ($csv === null || trim($csv) === '') return [];
    $ids = [];
    foreach (explode(',', $csv) as $part) {
        $part = trim($part);
        if ($part === '' || !preg_match('/^\d+$/', $part)) continue;
        $id = (int)$part;
        if ($id > 0) $ids[] = $id;
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

/*
 * Attachment file handling (#38 v2): every attachment row in the database has
 * a matching file in the uploads/ folder named attachments.stored_name (a
 * server-generated token written at upload time). Deleting rows alone leaves
 * those files behind as orphans, so the delete paths hard-delete the files
 * too, and the admin page can sweep files that lost their row anyway.
 */

/** The safe on-disk path of an attachment's stored file. The stored name is
 * always a server-generated token, but basename() keeps any lookup inside
 * uploads/ even if a row was tampered with. */
function tb_attachment_path(string $storedName): string {
    return dirname(__DIR__) . '/uploads/' . basename($storedName);
}

/** The stored names of a task's attachments, captured before a delete
 * cascades the rows away. */
function tb_task_attachment_files(PDO $pdo, int $taskId): array {
    $s = $pdo->prepare('SELECT stored_name FROM attachments WHERE task_id=?');
    $s->execute([$taskId]);
    return array_map('strval', $s->fetchAll(PDO::FETCH_COLUMN));
}

/** Hard-deletes the given stored files from uploads/ (missing files are
 * skipped silently — that is what they are: gone). Returns how many files
 * were actually removed. */
function tb_unlink_stored_files(array $storedNames): int {
    $removed = 0;
    foreach ($storedNames as $storedName) {
        $path = tb_attachment_path((string)$storedName);
        if (is_file($path) && unlink($path)) $removed++;
    }
    return $removed;
}

/** Removes every file in uploads/ that no attachments row references anymore
 * (leftovers from deletes that happened before #38 v2, failed uploads, or
 * manually placed files). The .htaccess file and files still referenced are
 * never touched. Returns how many files were removed. */
function tb_cleanup_orphan_uploads(PDO $pdo): int {
    // Know-hows (#53): a file the document_attachments table still references
    // is not an orphan either - the sweep used to know only the task
    // attachments and deleted every knowledge-base file as a leftover.
    $known = array_map('strval', $pdo->query(
        'SELECT stored_name FROM attachments UNION SELECT stored_name FROM document_attachments'
    )->fetchAll(PDO::FETCH_COLUMN));
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) return 0;
    $removed = 0;
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.htaccess') continue;
        if (in_array($entry, $known, true)) continue;
        if (is_file($dir . '/' . $entry) && unlink($dir . '/' . $entry)) $removed++;
    }
    return $removed;
}

/** Know-hows (#53): does a document row exist? The knowledge base actions use
 * it exactly like the task actions use task_exists(). */
function document_exists(PDO $pdo, int $id): bool {
    $s = $pdo->prepare('SELECT 1 FROM documents WHERE id=?');
    $s->execute([$id]);
    return (bool)$s->fetchColumn();
}

/** Know-hows (#53): may the signed-in user edit or delete this document?
 * The same creator-or-administrator rule the task archive uses. */
function document_can_edit(array $doc): bool {
    return is_admin() || (int)($doc['created_by'] ?? 0) === (int)user()['id'];
}

/** Message board (#56): how many unread messages the signed-in user has.
 * Drives the red "new messages" notification on the message board page and
 * the badge next to the navbar's Messages link. */
function tb_unread_message_count(PDO $pdo, int $userId): int {
    $s = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE recipient_id=? AND is_read=0');
    $s->execute([$userId]);
    return (int)$s->fetchColumn();
}

/** Notifications (#58): how many unseen notifications the signed-in user has.
 * Drives the red badge next to the navbar's Notifications link and the count
 * on the notifications page. */
function tb_unseen_notification_count(PDO $pdo, int $userId): int {
    $s = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_seen=0');
    $s->execute([$userId]);
    return (int)$s->fetchColumn();
}

/** Notifications (#58): drops one notification per assignee of the task,
 * minus the actor - you do not get pinged for your own edit (actor 0, as used
 * by system paths without a session, excludes nobody). history() calls this
 * for every event-level history row; the per-field audit rows (#54) stay
 * notification-free, so one save pings each assignee exactly once.
 * $historyId points at the change-details page of the triggering entry. */
function tb_notify_task_assignees(PDO $pdo, int $taskId, int $actorId, string $event, int $historyId): void {
    $s = $pdo->prepare('SELECT user_id FROM task_assignees WHERE task_id=? AND user_id<>?');
    $s->execute([$taskId, $actorId]);
    $ins = $pdo->prepare('INSERT INTO notifications(user_id,task_id,history_id,event) VALUES(?,?,?,?)');
    foreach ($s->fetchAll() as $row) {
        $ins->execute([(int)$row['user_id'], $taskId, $historyId, $event]);
    }
}

/** Know-hows (#53): the first $max characters of a text for the list excerpt,
 * UTF-8 safe without requiring ext-mbstring (preg /u first, byte fallback). */
function tb_excerpt(string $text, int $max = 140): string {
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    if ($text === '' || tb_char_length($text) <= $max) return $text;
    if (preg_match('/^.{0,' . $max . '}/us', $text, $m) === 1) return trim((string)$m[0]) . '…';
    return substr($text, 0, $max) . '…';
}

/*
 * Status colours (#42): administrators can give every task status an optional
 * #RRGGBB colour on the Task statuses page. The colour renders on every
 * status badge of the board (task list, task/project/archive pages); NULL
 * keeps the default badge look the board has always had.
 */

/** The validation message for a posted status colour, or null when it is
 * usable. Empty means "no colour" and is always valid. */
function tb_status_color_validation_error(?string $raw): ?string {
    if (trim((string)$raw) === '') return null;
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', trim((string)$raw))) {
        return 'Status colour must be a #RRGGBB hex value, or empty for the default look.';
    }
    return null;
}

/** A validated colour, normalized for storage: lowercase #rrggbb, or NULL for
 * "no colour". Only call this after tb_status_color_validation_error() passed. */
function tb_status_color_normalized(?string $raw): ?string {
    $raw = strtolower(trim((string)$raw));
    return $raw === '' ? null : $raw;
}

/** True when the string is a well-formed #rrggbb colour. Used defensively at
 * render time, so a tampered or legacy row value falls back to the default
 * badge instead of producing markup. */
function tb_status_color_is_valid(?string $raw): bool {
    return $raw !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $raw) === 1;
}

/** Readable text colour for a #rrggbb background: near-black on light fills,
 * white on dark ones, from the WCAG relative luminance of the fill. */
function tb_status_text_color(string $hex): string {
    $lum = 0.2126 * hexdec(substr($hex, 1, 2)) / 255
         + 0.7152 * hexdec(substr($hex, 3, 2)) / 255
         + 0.0722 * hexdec(substr($hex, 5, 2)) / 255;
    return $lum > 0.6 ? '#1a1a1a' : '#ffffff';
}

/** The badge markup for a status name and its optional colour. Without a
 * colour this renders exactly the markup the board has always used, so
 * existing pages keep their look. The name is escaped; the colour passed the
 * strict #rrggbb check before it can reach the style attribute. */
function tb_status_badge(string $name, ?string $color): string {
    if (!tb_status_color_is_valid($color)) {
        return '<span class="badge text-bg-light border">' . h($name) . '</span>';
    }
    $color = strtolower($color);
    return '<span class="badge border" style="background-color:' . $color . ';color:' . tb_status_text_color($color) . '">' . h($name) . '</span>';
}

/** The auto-refresh script for the list views (#51): every list page renders
 * its live region as <div id="tb-list-region" data-tb-refresh="10">…</div> and
 * calls this helper once right after it. The script re-fetches the page URL
 * every 10 seconds (the data attribute is the per-page knob), replaces only
 * the region's children with the freshly rendered ones and re-applies the
 * user's in-region form state (checkbox and select values) afterwards, so a
 * bulk selection or a chosen target project survives every refresh tick. It
 * stays silent while the tab is hidden and polls again the moment the tab
 * becomes visible. No server-side endpoint, no dependency: the region lookup
 * also makes the refresh a no-op on every page that does not opt in. */
function tb_render_list_refresh_script(): void {
    ?><script>
(function () {
    var region = document.getElementById('tb-list-region');
    if (!region) return;
    var interval = parseInt(region.getAttribute('data-tb-refresh') || '10', 10) * 1000;
    if (!(interval > 0)) return;
    var busy = false;

    function savedState() {
        // name/value pairs are the stable keys across refreshes; the checked
        // flag and the selected option are the user's state to keep.
        var state = {checks: {}, selects: {}};
        region.querySelectorAll('input[type="checkbox"][name]').forEach(function (cb) {
            state.checks[cb.name + '::' + cb.value] = cb.checked;
        });
        region.querySelectorAll('select[name]').forEach(function (sel) {
            state.selects[sel.name] = sel.value;
        });
        return state;
    }

    function applyState(state) {
        region.querySelectorAll('input[type="checkbox"][name]').forEach(function (cb) {
            var key = cb.name + '::' + cb.value;
            if (key in state.checks) cb.checked = state.checks[key];
        });
        region.querySelectorAll('select[name]').forEach(function (sel) {
            if (sel.name in state.selects) sel.value = state.selects[sel.name];
        });
    }

    function tick() {
        if (busy || document.hidden) return;
        busy = true;
        fetch(window.location.href, {credentials: 'same-origin'})
            .then(function (response) { return response.ok ? response.text() : null; })
            .then(function (html) {
                if (html === null) return;
                var fresh = new DOMParser().parseFromString(html, 'text/html').getElementById('tb-list-region');
                // A page without the region (e.g. the sign-in page after an
                // expired session) or an unchanged region: nothing to swap.
                if (!fresh || fresh.innerHTML === region.innerHTML) return;
                var state = savedState();
                region.replaceChildren.apply(region, Array.prototype.slice.call(fresh.childNodes));
                applyState(state);
            })
            .catch(function () { /* a failed poll just waits for the next tick */ })
            .then(function () { busy = false; });
    }

    setInterval(tick, interval);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
})();
</script><?php
}
