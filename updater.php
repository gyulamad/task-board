<?php
// This file is included by index.php after authentication and authorization.
if (!defined('TASK_BOARD_BOOTSTRAPPED')) {
    http_response_code(404);
    exit('Not found.');
}

const TASK_BOARD_UPDATE_REPO = 'gyulamad/task-board';
const TASK_BOARD_UPDATE_FILES = [
    'index.php',
    'updater.php',
    // index.php requires this file since the helpers were split out of
    // index.php; leaving it off the allowlist installs an application that
    // cannot start (HTTP 500). Keep it in sync with the repository layout.
    'src/helpers.php',
    'version.json',
    'schema.sql',
    'README.md',
    'config.example.php',
    '.gitignore',
    'uploads/.htaccess',
];

function tb_update_http_get(string $url, int $maxBytes): string {
    if (!str_starts_with($url, 'https://api.github.com/') && !str_starts_with($url, 'https://codeload.github.com/')) {
        throw new RuntimeException('The update source URL is not trusted.');
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 25,
            'follow_location' => 0,
            'header' => "User-Agent: PHP-Task-Board-Updater\r\nAccept: application/vnd.github+json\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $data = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) if (preg_match('#^HTTP/\\S+\\s+(\\d{3})#', $header, $match)) $status = (int)$match[1];
    if ($data === false || strlen($data) > $maxBytes || $status < 200 || $status >= 300) {
        throw new RuntimeException('Could not download the update (HTTP ' . $status . '), or the download exceeded the size limit.');
    }
    return $data;
}

function tb_update_source(string $type, string $branch): array {
    if ($type === 'release') {
        $meta = json_decode(tb_update_http_get('https://api.github.com/repos/' . TASK_BOARD_UPDATE_REPO . '/releases/latest', 1024 * 1024), true);
        if (!is_array($meta) || empty($meta['tag_name'])) throw new RuntimeException('No published GitHub release was found.');
        $tag = (string)$meta['tag_name'];
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $tag)) throw new RuntimeException('GitHub returned an invalid release tag.');
        $zipUrl = 'https://codeload.github.com/' . TASK_BOARD_UPDATE_REPO . '/zip/refs/tags/' . rawurlencode($tag);
        return ['label' => 'Release ' . $tag, 'ref' => $tag, 'sha' => $tag, 'url' => $zipUrl];
    }
    if ($type !== 'branch' || !preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $branch) || str_contains($branch, '..') || str_starts_with($branch, '/') || str_ends_with($branch, '/')) {
        throw new RuntimeException('Choose a valid branch name.');
    }
    $encoded = implode('/', array_map('rawurlencode', explode('/', $branch)));
    $meta = json_decode(tb_update_http_get('https://api.github.com/repos/' . TASK_BOARD_UPDATE_REPO . '/commits/' . $encoded, 1024 * 1024), true);
    if (!is_array($meta) || empty($meta['sha'])) throw new RuntimeException('The selected branch could not be found.');
    return [
        'label' => 'Branch ' . $branch . ' (' . substr((string)$meta['sha'], 0, 12) . ')',
        'ref' => (string)$meta['sha'],
        'sha' => (string)$meta['sha'],
        'url' => 'https://codeload.github.com/' . TASK_BOARD_UPDATE_REPO . '/zip/' . rawurlencode((string)$meta['sha']),
    ];
}


function tb_update_pinned_source(string $type, string $ref): array {
    if ($type === 'release' && preg_match('/^[A-Za-z0-9._-]{1,100}$/', $ref)) {
        return ['label' => 'Release ' . $ref, 'ref' => $ref, 'sha' => $ref, 'url' => 'https://codeload.github.com/' . TASK_BOARD_UPDATE_REPO . '/zip/refs/tags/' . rawurlencode($ref)];
    }
    if ($type === 'branch' && preg_match('/^[a-f0-9]{40}$/i', $ref)) {
        return ['label' => 'Branch commit ' . substr($ref, 0, 12), 'ref' => $ref, 'sha' => $ref, 'url' => 'https://codeload.github.com/' . TASK_BOARD_UPDATE_REPO . '/zip/' . rawurlencode($ref)];
    }
    throw new RuntimeException('The checked update reference is invalid. Please check for updates again.');
}

function tb_update_archive_files(string $archiveData): array {
    if (!class_exists(ZipArchive::class)) throw new RuntimeException('The PHP ZIP extension (ZipArchive) is required for updates.');
    $tmp = tempnam(sys_get_temp_dir(), 'tb-update-');
    if ($tmp === false || file_put_contents($tmp, $archiveData, LOCK_EX) === false) throw new RuntimeException('Could not prepare the update archive.');
    $zip = new ZipArchive();
    try {
        if ($zip->open($tmp) !== true) throw new RuntimeException('The downloaded update is not a valid ZIP archive.');
        $files = [];
        $root = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat || !isset($stat['name'])) continue;
            $name = str_replace('\\', '/', (string)$stat['name']);
            if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) throw new RuntimeException('The archive contains an unsafe path.');
            $parts = explode('/', trim($name, '/'));
            if (count($parts) < 2) continue;
            if ($root === '') $root = $parts[0];
            if ($parts[0] !== $root) throw new RuntimeException('The archive has an unexpected directory layout.');
            $relative = implode('/', array_slice($parts, 1));
            if ($relative === '' || !in_array($relative, TASK_BOARD_UPDATE_FILES, true)) continue;
            if (str_ends_with($name, '/')) continue;
            $opsys = 0; $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX) {
                $mode = ($attributes >> 16) & 0170000;
                if ($mode === 0120000) throw new RuntimeException('The archive contains a symbolic link.');
            }
            if ((int)$stat['size'] > 5 * 1024 * 1024) throw new RuntimeException('An update file exceeds the 5 MB safety limit.');
            $stream = $zip->getStream($stat['name']);
            if (!$stream) throw new RuntimeException('Could not read an update file.');
            $content = stream_get_contents($stream, 5 * 1024 * 1024 + 1);
            fclose($stream);
            if ($content === false || strlen($content) > 5 * 1024 * 1024) throw new RuntimeException('An update file exceeds the safety limit.');
            $files[$relative] = $content;
        }
        if (!isset($files['index.php']) || !isset($files['updater.php'])) throw new RuntimeException('The archive is missing required application files.');
        return $files;
    } finally {
        $zip->close();
        @unlink($tmp);
    }
}

function tb_update_install(array $files): string {
    $backupDir = sys_get_temp_dir() . '/task-board-backup-' . bin2hex(random_bytes(12));
    if (!mkdir($backupDir, 0700)) throw new RuntimeException('Could not create a private update backup.');
    $backedUp = [];
    $written = [];
    try {
        foreach ($files as $relative => $content) {
            if (!in_array($relative, TASK_BOARD_UPDATE_FILES, true)) throw new RuntimeException('An unexpected update path was rejected.');
            $target = __DIR__ . '/' . $relative;
            $parent = dirname($target);
            // Post-split versions may introduce sub-directories (e.g. src/)
            // that do not exist on installations made before the split, so an
            // update has to be able to bootstrap them.
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException('The hosting account cannot create the directory needed for ' . $relative . '.');
            }
            if (!is_writable($parent)) throw new RuntimeException('The hosting account cannot write to ' . $relative . '.');
            if (is_link($target)) throw new RuntimeException('A symbolic link target was rejected.');
            if (is_file($target)) {
                $backup = $backupDir . '/' . str_replace('/', '__', $relative);
                if (!copy($target, $backup)) throw new RuntimeException('Could not back up ' . $relative . '.');
                $backedUp[$relative] = $backup;
            } else {
                $backedUp[$relative] = null;
            }
        }
        foreach ($files as $relative => $content) {
            $target = __DIR__ . '/' . $relative;
            $tmp = tempnam(dirname($target), '.tb-update-');
            if ($tmp === false || file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) throw new RuntimeException('Could not write ' . $relative . '.');
            $mode = is_file($target) ? (fileperms($target) & 0777) : 0644;
            @chmod($tmp, $mode);
            if (!rename($tmp, $target)) { @unlink($tmp); throw new RuntimeException('Could not replace ' . $relative . '.'); }
            $written[] = $relative;
        }
        return $backupDir;
    } catch (Throwable $e) {
        foreach (array_reverse($written) as $relative) {
            $backup = $backedUp[$relative] ?? null;
            $target = __DIR__ . '/' . $relative;
            if ($backup && is_file($backup)) @copy($backup, $target);
            elseif (is_file($target)) @unlink($target);
        }
        throw new RuntimeException($e->getMessage() . ' Any files already replaced were rolled back where possible.');
    }
}

/** Re-checks the signed-in administrator's current password for the page's sensitive actions (#49). */
function tb_update_verify_admin_password(PDO $pdo, string $password): bool {
    $admin = $pdo->prepare('SELECT password_hash FROM users WHERE id=? AND role=\'admin\'');
    $admin->execute([user()['id']]);
    $hash = $admin->fetchColumn();
    return $hash !== false && (string)$hash !== '' && password_verify($password, (string)$hash);
}

// ---- Full backup download (#49) -----------------------------------------------------------
// A full backup before applying an update: one ZIP with (a) the complete
// application code as served from this directory, (b) every uploaded
// attachment file and (c) an SQL dump of the whole database.
//
// Everything lives in updater.php on purpose: the updater only ever replaces
// the allowlisted TASK_BOARD_UPDATE_FILES, so a brand-new src/backup.php could
// never reach installations that update through this page (the running old
// updater would skip it and the next request would fatal on the missing
// require). Keeping the code inside updater.php — which IS on the allowlist —
// makes the feature deployable through the updater itself.
const TASK_BOARD_BACKUP_SKIP_DIRS = ['.git', 'vendor', 'uploads'];
const TASK_BOARD_BACKUP_SKIP_ROOT_SUFFIXES = ['.zip', '.sqlite'];

/** Collects the relative paths of all files under $dir that the backup includes (#49). */
function tb_backup_list_files(string $dir): array {
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile() || !$file->isReadable()) continue;
        $relative = str_replace('\\', '/', substr(str_replace('\\', '/', (string)$file), strlen(rtrim($dir, '/')) + 1));
        if ($relative === '' || str_contains($relative, '..')) continue; // cannot happen from the filesystem, kept as a guard
        $parts = explode('/', $relative);
        if (in_array($parts[0], TASK_BOARD_BACKUP_SKIP_DIRS, true)) continue;
        // A backup ZIP or SQLite file that someone parked in the document root
        // must not be folded into the next backup (backup-of-backup growth).
        if (count($parts) === 1) {
            foreach (TASK_BOARD_BACKUP_SKIP_ROOT_SUFFIXES as $suffix) {
                if (str_ends_with(strtolower($relative), $suffix)) continue 2;
            }
        }
        $files[] = $relative;
    }
    sort($files);
    return $files;
}

/** Quotes a PHP value as a SQL literal for the active database driver (#49). */
function tb_backup_sql_literal(PDO $pdo, mixed $value): string {
    if ($value === null) return 'NULL';
    if (is_int($value) || is_float($value)) return (string)$value;
    return $pdo->quote((string)$value);
}

/** The identifier $name quoted for the active database driver (#49). */
function tb_backup_quote_identifier(string $driver, string $name): string {
    if ($driver === 'mysql') return '`' . str_replace('`', '``', $name) . '`';
    return '"' . str_replace('"', '""', $name) . '"';
}

/** The base tables of the current database, sorted by name (#49). */
function tb_backup_table_list(PDO $pdo, string $driver): array {
    if ($driver === 'mysql') {
        $rows = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $tables = array_map(fn(array $row): string => (string)$row[0], $rows);
    } else {
        $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_NUM);
        $tables = array_map(fn(array $row): string => (string)$row[0], $rows);
    }
    sort($tables, SORT_STRING);
    return $tables;
}

/** The CREATE statement of one table for the active database driver (#49). */
function tb_backup_table_schema(PDO $pdo, string $driver, string $table): string {
    if ($driver === 'mysql') {
        $row = $pdo->query('SHOW CREATE TABLE ' . tb_backup_quote_identifier($driver, $table))->fetch(PDO::FETCH_NUM);
        if (!$row || !isset($row[1])) throw new RuntimeException('Could not read the schema of table ' . $table . '.');
        return (string)$row[1];
    }
    $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name=?");
    $stmt->execute([$table]);
    $sql = $stmt->fetchColumn();
    if ($sql === false || trim((string)$sql) === '') throw new RuntimeException('Could not read the schema of table ' . $table . '.');
    return (string)$sql;
}

/** Writes the schema + data dump of the whole database to $dumpPath (#49). Returns [tables, rows]. */
function tb_backup_write_dump(PDO $pdo, string $dumpPath): array {
    $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $handle = fopen($dumpPath, 'wb');
    if ($handle === false) throw new RuntimeException('Could not create the SQL dump file.');
    $tables = tb_backup_table_list($pdo, $driver);
    $rows = 0;
    try {
        fwrite($handle, "-- Task Board full database dump (#49)\n-- Generated (UTC): " . gmdate('c') . "\n-- Driver: {$driver}\n\n");
        if ($driver === 'mysql') {
            fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        } else {
            fwrite($handle, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n");
        }
        foreach ($tables as $table) {
            $quoted = tb_backup_quote_identifier($driver, $table);
            fwrite($handle, "-- Table: {$table}\nDROP TABLE IF EXISTS {$quoted};\n");
            fwrite($handle, tb_backup_table_schema($pdo, $driver, $table) . ";\n");
            $stmt = $pdo->query('SELECT * FROM ' . $quoted);
            if ($stmt === false) throw new RuntimeException('Could not read the rows of table ' . $table . '.');
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if ($row === []) continue;
                $columns = implode(', ', array_map(fn(string $c): string => tb_backup_quote_identifier($driver, $c), array_keys($row)));
                $values = implode(', ', array_map(fn(mixed $v): string => tb_backup_sql_literal($pdo, $v), array_values($row)));
                fwrite($handle, "INSERT INTO {$quoted} ({$columns}) VALUES ({$values});\n");
                $rows++;
            }
            fwrite($handle, "\n");
        }
        if ($driver === 'mysql') {
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } else {
            fwrite($handle, "COMMIT;\n");
        }
    } finally {
        fclose($handle);
    }
    return [count($tables), $rows];
}

/** Builds the MANIFEST.txt that documents what the archive contains (#49). */
function tb_backup_manifest(string $driver, array $codeFiles, array $uploadFiles, int $tables, int $rows, string $dumpName): string {
    $installed = is_file(__DIR__ . '/version.json') ? (json_decode((string)file_get_contents(__DIR__ . '/version.json'), true) ?: []) : [];
    $lines = [
        'Task Board full backup',
        'Generated (UTC): ' . gmdate('c'),
        'PHP: ' . PHP_VERSION . ' · Database driver: ' . $driver,
        'Installed source: ' . ($installed['source'] ?? 'unknown') . ' · ref: ' . ($installed['ref'] ?? 'not recorded') . ' · installed at: ' . ($installed['installed_at'] ?? 'unknown'),
        '',
        'Contents:',
        '  code/       the complete application directory as served (includes config.php; excludes .git, vendor/ and the uploads/ folder)',
        '  uploads/    the uploads folder exactly as stored on disk, including its .htaccess (' . count($uploadFiles) . ' file' . (count($uploadFiles) === 1 ? '' : 's') . ')',
        "  database/   full SQL dump (schema + data) of every table: {$tables} table" . ($tables === 1 ? '' : 's') . ", {$rows} row" . ($rows === 1 ? '' : 's') . ' (' . $dumpName . ')',
        '  ' . count($codeFiles) . ' code file' . (count($codeFiles) === 1 ? '' : 's') . ' included',
        '',
        'Restore hints:',
        '  1. Restore code/ into the web root (config.php is included and is needed for the database credentials).',
        '  2. Recreate the empty database and import database/' . $dumpName . ' (the MySQL dump wraps the import in SET FOREIGN_KEY_CHECKS=0/1; the SQLite dump in PRAGMA foreign_keys=OFF / COMMIT).',
        '  3. Copy uploads/ back over the uploads directory.',
        '  This archive contains credentials (config.php and password hashes): keep it in a safe place.',
    ];
    return implode("\n", $lines) . "\n";
}

/** Builds the full backup ZIP in a private temp file. Returns [path, dump, name, driver]. */
function tb_backup_build(PDO $pdo): array {
    if (!class_exists(ZipArchive::class)) throw new RuntimeException('The PHP ZIP extension (ZipArchive) is required for the full backup.');
    $stamp = gmdate('Ymd-His');
    $name = 'task-board-backup-' . $stamp . '.zip';
    $dumpName = 'task-board-dump-' . $stamp . '.sql';
    $zipPath = tempnam(sys_get_temp_dir(), 'tb-backup-');
    $dumpPath = tempnam(sys_get_temp_dir(), 'tb-dump-');
    if ($zipPath === false || $dumpPath === false) throw new RuntimeException('Could not create the temporary backup files.');
    try {
        [$tables, $rows] = tb_backup_write_dump($pdo, $dumpPath);
        $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $codeFiles = tb_backup_list_files(__DIR__);
        $uploadDir = __DIR__ . '/uploads';
        $uploadFiles = is_dir($uploadDir) ? tb_backup_list_files($uploadDir) : [];

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup ZIP archive.');
        }
        try {
            foreach ($codeFiles as $relative) {
                if (!$zip->addFile(__DIR__ . '/' . $relative, 'code/' . $relative)) {
                    throw new RuntimeException('Could not add ' . $relative . ' to the backup ZIP.');
                }
            }
            foreach ($uploadFiles as $relative) {
                if (!$zip->addFile($uploadDir . '/' . $relative, 'uploads/' . $relative)) {
                    throw new RuntimeException('Could not add the uploaded file ' . $relative . ' to the backup ZIP.');
                }
            }
            if (!$zip->addFile($dumpPath, 'database/' . $dumpName)) {
                throw new RuntimeException('Could not add the SQL dump to the backup ZIP.');
            }
            if ($zip->addFromString('MANIFEST.txt', tb_backup_manifest($driver, $codeFiles, $uploadFiles, $tables, $rows, $dumpName)) !== true) {
                throw new RuntimeException('Could not add the backup manifest to the ZIP.');
            }
            if ($zip->close() !== true) {
                throw new RuntimeException('Could not finish the backup ZIP archive.');
            }
        } catch (Throwable $e) {
            $zip->close();
            throw $e;
        }
        return ['path' => $zipPath, 'dump' => $dumpPath, 'name' => $name];
    } catch (Throwable $e) {
        @unlink($zipPath);
        @unlink($dumpPath);
        throw $e;
    }
}

/** Streams the finished backup ZIP as a download and stops the request (#49). */
function tb_backup_send(array $backup): never {
    try {
        while (ob_get_level() > 0) ob_end_clean();
        $size = filesize($backup['path']);
        if ($size === false) throw new RuntimeException('Could not read the finished backup ZIP.');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $backup['name'] . '"');
        header('Content-Length: ' . $size);
        header('Cache-Control: no-store, max-age=0');
        if (!readfile($backup['path'])) throw new RuntimeException('Could not stream the backup ZIP.');
    } finally {
        @unlink($backup['path']);
        @unlink($backup['dump']);
    }
    exit;
}

// ---- End full backup download (#49) --------------------------------------------------------

function task_board_update_page(PDO $pdo, array $config): void {
    $error = '';
    $message = '';
    $selectedType = $_POST['source_type'] ?? 'release';
    $selectedBranch = trim($_POST['branch'] ?? 'master');
    $sourceInfo = null;

    // Full backup download (#49): the restore point an administrator takes
    // before installing an update. Sensitive payload (code incl. config.php,
    // uploaded files, whole SQL dump), so it re-checks the admin password like
    // the install action does; the zip streams to the browser and no copy is
    // kept on the server beyond the request.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download_backup') {
        if (!tb_update_verify_admin_password($pdo, (string)($_POST['current_password'] ?? ''))) {
            $error = 'Your current administrator password was not accepted.';
        } else {
            try {
                tb_backup_send(tb_backup_build($pdo));
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install_update') {
        $admin = $pdo->prepare('SELECT password_hash FROM users WHERE id=? AND role=\'admin\'');
        $admin->execute([user()['id']]);
        $hash = $admin->fetchColumn();
        if (!$hash || !tb_update_verify_admin_password($pdo, (string)($_POST['current_password'] ?? ''))) {
            $error = 'Your current administrator password was not accepted.';
        } else {
            try {
                $sourceInfo = tb_update_pinned_source($selectedType, trim($_POST['source_ref'] ?? ''));
                $archive = tb_update_http_get($sourceInfo['url'], 20 * 1024 * 1024);
                $files = tb_update_archive_files($archive);
                $files['version.json'] = json_encode(['source' => $selectedType, 'ref' => $sourceInfo['ref'], 'sha' => $sourceInfo['sha'], 'installed_at' => gmdate('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                $installedLabel = $sourceInfo['label'];
                $backup = tb_update_install($files);
                $sourceInfo = null;
                $message = 'Update installed from ' . $installedLabel . '. A private backup was saved temporarily at ' . $backup . '. No database changes were run; apply any required schema migrations manually after reviewing the release notes.';
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_update') {
        try {
            $sourceInfo = tb_update_source($selectedType, $selectedBranch);
            $installed = is_file(__DIR__ . '/version.json') ? (json_decode((string)file_get_contents(__DIR__ . '/version.json'), true) ?: []) : [];
            if (!empty($installed['sha']) && hash_equals((string)$installed['sha'], (string)$sourceInfo['sha'])) {
                $message = 'This exact source is already installed (' . $sourceInfo['label'] . ').';
                $sourceInfo = null;
            } else {
                $message = 'Update found: ' . $sourceInfo['label'] . '. Confirm the source and enter your current administrator password to install it.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $installed = [];
    if (is_file(__DIR__ . '/version.json')) $installed = json_decode((string)file_get_contents(__DIR__ . '/version.json'), true) ?: [];
    render_header('Software updates', $config); ?>
    <div class="card p-4">
        <h2 class="h4">Software updates</h2>
        <p class="small muted">Installed source: <?=h((string)($installed['source'] ?? 'unknown'))?> · <?=h((string)($installed['ref'] ?? 'not recorded'))?> · Installed at <?=h((string)($installed['installed_at'] ?? 'unknown'))?></p>
        <p class="muted">Download application files from the official <a href="https://github.com/gyulamad/task-board" target="_blank" rel="noopener">Task Board repository</a>. Releases are recommended for normal updates; branches are intended for testing.</p>
        <?php if ($error): ?><div class="alert alert-danger"><?=h($error)?></div><?php endif; ?>
        <?php if ($message): ?><div class="alert alert-info"><?=h($message)?></div><?php endif; ?>
        <form method="post" class="mb-3"><?=csrf_field()?>
            <input type="hidden" name="action" value="check_update">
            <div class="mb-3"><label class="form-label" for="source_type">Update source</label>
                <select class="form-select" id="source_type" name="source_type" onchange="document.getElementById('branch-wrap').hidden=this.value!=='branch'">
                    <option value="release" <?=$selectedType==='release'?'selected':''?>>Latest published release (recommended)</option>
                    <option value="branch" <?=$selectedType==='branch'?'selected':''?>>Selected branch (advanced)</option>
                </select>
            </div>
            <div class="mb-3" id="branch-wrap" <?=$selectedType==='branch'?'':'hidden'?>><label class="form-label" for="branch">Branch name</label><input class="form-control" id="branch" name="branch" value="<?=h($selectedBranch)?>" maxlength="100" placeholder="master"></div>
            <button class="btn btn-outline-primary">Check source</button>
        </form>
        <hr><h3 class="h6">Download a full backup</h3>
        <p class="small muted">One ZIP package with the complete application code (including <span class="font-monospace">config.php</span>), every uploaded attachment file and an SQL dump of the whole database — the restore point to keep before installing an update. The archive holds credentials, so keep the downloaded file safe; nothing is stored on the server.</p>
        <form method="post" class="mb-3" onsubmit="return confirm('Prepare and download the full backup ZIP now?')"><?=csrf_field()?>
            <input type="hidden" name="action" value="download_backup">
            <div class="mb-3"><label class="form-label" for="backup_password">Current administrator password</label><input class="form-control" type="password" id="backup_password" name="current_password" autocomplete="current-password" required></div>
            <button class="btn btn-outline-secondary">Download full backup (code, uploads, SQL dump)</button>
        </form>
        <?php if ($sourceInfo): ?>
            <hr><h3 class="h6">Install <?=h($sourceInfo['label'])?></h3>
            <p class="small muted">The updater changes only the application's allowlisted files. It never overwrites config.php or uploaded files, deletes files, executes SQL, or runs downloaded scripts as part of extraction. A private backup is created first. Make your own database backup before updating — the full backup download above is exactly that.</p>
            <form method="post" onsubmit="return confirm('Install this update now? Make sure you have a database backup.')"><?=csrf_field()?>
                <input type="hidden" name="action" value="install_update">
                <input type="hidden" name="source_type" value="<?=h($selectedType)?>">
                <input type="hidden" name="branch" value="<?=h($selectedBranch)?>">
                <input type="hidden" name="source_ref" value="<?=h($sourceInfo['ref'])?>">
                <div class="mb-3"><label class="form-label" for="current_password">Current administrator password</label><input class="form-control" type="password" id="current_password" name="current_password" autocomplete="current-password" required></div>
                <button class="btn btn-danger">Back up and install update</button>
            </form>
        <?php endif; ?>
    </div>
    <?php render_footer();
}
