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
