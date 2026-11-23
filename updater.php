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
    // The migrations convention doc (#59/#68) ships with the code; the .sql
    // scripts themselves are instance-local state (uploads + repo checkouts)
    // and are deliberately not replaced by an update.
    'migrations/README.md',
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

// ---- SQL migration upload (#59/#68) --------------------------------------------------------
// Requirement 1 of the upgrade packs: a database change that has to run on this
// instance can be uploaded as a plain .sql file right here. The file is stored
// version-marked in migrations/ (the same tracked, once-only flow the repo
// migrations use) and applied immediately, so "upload + run" is one step. The
// code lives in updater.php on purpose (see the backup section above): the
// running updater only ever replaces allowlisted files, and this page IS one.
const TASK_BOARD_MIGRATION_MAX_BYTES = 1024 * 1024;

/** Stores the uploaded .sql as a version-marked migration and applies it. Returns the confirmation message. */
function tb_update_upload_migration(PDO $pdo): string {
    $file = $_FILES['migration_file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Choose a .sql file to upload.');
    }
    if ((int)($file['size'] ?? 0) > TASK_BOARD_MIGRATION_MAX_BYTES) {
        throw new RuntimeException('The migration script exceeds the 1 MB limit.');
    }
    $original = (string)($file['name'] ?? '');
    if (!str_ends_with(strtolower($original), '.sql')) {
        throw new RuntimeException('Only .sql files are accepted.');
    }
    $sql = (string)file_get_contents((string)$file['tmp_name']);
    if (trim($sql) === '') throw new RuntimeException('The uploaded file is empty.');
    if (tb_migration_split_statements($sql) === []) {
        throw new RuntimeException('The uploaded file contains no SQL statements.');
    }
    $dir = __DIR__ . '/migrations';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the migrations directory.');
    }
    // A human-readable slug may ride along, but the version stamp is what the
    // runner keys on: UTC time to the second keeps uploads ordered and unique.
    $slug = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($original, PATHINFO_FILENAME)), '-._');
    if ($slug === '') $slug = 'uploaded';
    $slug = substr($slug, 0, 80);
    $target = $dir . '/' . gmdate('YmdHis') . '-' . $slug . '.sql';
    if (file_exists($target)) {
        throw new RuntimeException('A migration with this timestamp already exists; try again in a second.');
    }
    if (!@move_uploaded_file((string)$file['tmp_name'], $target) && !@rename((string)$file['tmp_name'], $target) && file_put_contents($target, $sql, LOCK_EX) === false) {
        throw new RuntimeException('Could not store the migration script in migrations/.');
    }
    $result = tb_apply_pending_migrations($pdo, $dir);
    if (!empty($result['failed'])) {
        // A script the driver rejected must not linger: the runner would retry
        // it on every request. Drop the file and let the admin fix and re-upload.
        @unlink($target);
        throw new RuntimeException('The migration failed to apply and was not kept: ' . $result['failed'][array_key_first($result['failed'])]);
    }
    return 'Migration ' . basename($target) . ' uploaded and applied'
        . (empty($result['applied']) ? '' : ' (' . implode(', ', $result['applied']) . ' applied in total).')
        . ' It is now part of the migrations/ history and will never run again on this instance.';
}

/**
 * The migration state of this instance for the Software updates page (#59/#68):
 * every migration file with its applied timestamp, plus versions that were
 * applied on this instance but whose file is no longer present (deployed from
 * a tree that dropped the file).
 *
 * @return array<int,array{version:string,file:string,applied_at:?string}> or null when the migrations table does not exist yet
 */
function tb_update_migration_status(PDO $pdo, string $dir): ?array {
    try {
        $applied = $pdo->query('SELECT version, applied_at FROM migrations')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    $byVersion = [];
    foreach ($applied as $row) $byVersion[(string)$row['version']] = (string)$row['applied_at'];
    $rows = [];
    foreach (tb_migration_scan($dir) as $entry) {
        $rows[] = ['version' => $entry['version'], 'file' => $entry['file'], 'applied_at' => $byVersion[$entry['version']] ?? null];
        unset($byVersion[$entry['version']]);
    }
    foreach ($byVersion as $version => $appliedAt) {
        $rows[] = ['version' => $version, 'file' => '(file no longer present)', 'applied_at' => $appliedAt];
    }
    usort($rows, fn(array $a, array $b): int => [$a['version'], $a['file']] <=> [$b['version'], $b['file']]);
    return $rows;
}

// ---- Upgrade packs (#59/#69) ---------------------------------------------------------------
// A pack upgrades an instance without GitHub: one ZIP holding the patched or
// new application files under files/, an optional deletions.json (files to
// remove), an optional migration.sql (stored version-marked in migrations/
// and applied through the #68 migration engine, so it is recorded once and
// never runs twice) and a pack.json whose previous_version must equal the
// running instance's recorded version hash before the pack is accepted. The
// engine lives in updater.php on purpose (same reason as the #49 backup and
// the #68 upload): the updater only ever replaces the allowlisted files, so
// pack code placed anywhere else could never reach installations that update
// through this page.

const TASK_BOARD_PACK_MAX_BYTES = 20 * 1024 * 1024; // same archive limit as the GitHub update install
const TASK_BOARD_PACK_PROTECTED = ['config.php', 'index.php', 'updater.php', 'src/helpers.php'];

/** True when $relative is a path an upgrade pack may add, modify or delete (#59/#69). */
function tb_pack_path_is_allowed(string $relative): bool {
    if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\')) return false;
    if (preg_match('#(^|/)\.\.(/|$)#', $relative)) return false;
    if (in_array($relative, TASK_BOARD_PACK_PROTECTED, true)) return false;
    return $relative !== 'uploads' && !str_starts_with($relative, 'uploads/');
}

/** The recorded version hash of this installation (version.json "sha"), or null when nothing is recorded (#59/#69). */
function tb_pack_current_version_hash(?string $appDir = null): ?string {
    $path = ($appDir ?? __DIR__) . '/version.json';
    if (!is_file($path)) return null;
    $installed = json_decode((string)file_get_contents($path), true);
    $sha = is_array($installed) ? (string)($installed['sha'] ?? '') : '';
    return $sha !== '' ? $sha : null;
}

/**
 * Reads and validates an uploaded upgrade pack (#59/#69). Returns
 * [previous_version, new_version, files => [relative => content],
 * deletions => string[], migration => ?string]. Every path is checked with
 * tb_pack_path_is_allowed(), so a pack cannot touch config.php, uploads/ or
 * escape the application directory.
 */
function tb_pack_read(string $zipData): array {
    if (!class_exists(ZipArchive::class)) throw new RuntimeException('The PHP ZIP extension (ZipArchive) is required for upgrade packs.');
    if (strlen($zipData) > TASK_BOARD_PACK_MAX_BYTES) throw new RuntimeException('The upgrade pack exceeds the 20 MB limit.');
    $tmp = tempnam(sys_get_temp_dir(), 'tb-pack-');
    if ($tmp === false || file_put_contents($tmp, $zipData, LOCK_EX) === false) throw new RuntimeException('Could not prepare the upgrade pack.');
    $zip = new ZipArchive();
    $opened = false;
    try {
        if ($zip->open($tmp) !== true) throw new RuntimeException('The upgrade pack is not a valid ZIP archive.');
        $opened = true;
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat || !isset($stat['name'])) continue;
            $name = str_replace('\\', '/', (string)$stat['name']);
            $name = implode('/', array_filter(explode('/', trim($name, '/')), fn(string $part): bool => $part !== ''));
            if ($name === '') continue;
            if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)) throw new RuntimeException('The upgrade pack contains an unsafe path.');
            $opsys = 0; $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes) && $opsys === ZipArchive::OPSYS_UNIX) {
                $mode = ($attributes >> 16) & 0170000;
                if ($mode === 0120000) throw new RuntimeException('The upgrade pack contains a symbolic link.');
            }
            if ((int)$stat['size'] > 5 * 1024 * 1024) throw new RuntimeException('An upgrade pack file exceeds the 5 MB safety limit.');
            $stream = $zip->getStream($stat['name']);
            if (!$stream) throw new RuntimeException('Could not read an upgrade pack file.');
            $content = stream_get_contents($stream, 5 * 1024 * 1024 + 1);
            fclose($stream);
            if ($content === false || strlen($content) > 5 * 1024 * 1024) throw new RuntimeException('An upgrade pack file exceeds the safety limit.');
            $entries[$name] = $content;
        }
        // The entries may sit at the archive root or inside one top directory
        // (the wrapper zip tools and GitHub archives produce). When pack.json
        // is not at the root but every entry shares one top directory, strip
        // that wrapper; anything else is an unexpected layout.
        if (!isset($entries['pack.json'])) {
            $roots = [];
            foreach (array_keys($entries) as $entryName) {
                $parts = explode('/', $entryName, 2);
                if (count($parts) === 2) $roots[$parts[0]] = true;
            }
            if (count($roots) === 1) {
                $root = (string)array_key_first($roots);
                $stripped = [];
                foreach ($entries as $entryName => $content) {
                    if (!str_starts_with($entryName, $root . '/')) throw new RuntimeException('The upgrade pack has an unexpected directory layout.');
                    $stripped[substr($entryName, strlen($root) + 1)] = $content;
                }
                $entries = $stripped;
            }
        }
        if (!isset($entries['pack.json'])) throw new RuntimeException('The upgrade pack is missing its pack.json metadata (expected pack.json with previous_version and new_version).');
        $meta = null;
        $deletions = [];
        $migration = null;
        $files = [];
        foreach ($entries as $name => $content) {
            if ($name === 'pack.json') {
                $meta = json_decode($content, true);
                if (!is_array($meta)) throw new RuntimeException('The pack.json of the upgrade pack is not valid JSON.');
            } elseif ($name === 'deletions.json') {
                $deletions = json_decode($content, true);
                if (!is_array($deletions)) throw new RuntimeException('The deletions.json of the upgrade pack is not valid JSON.');
            } elseif ($name === 'migration.sql') {
                $migration = $content;
            } elseif (str_starts_with($name, 'files/')) {
                $relative = substr($name, strlen('files/'));
                if ($relative === '') continue;
                if ($relative === 'pack.json' || $relative === 'deletions.json' || $relative === 'migration.sql' || str_starts_with($relative, 'files/')) {
                    throw new RuntimeException('The upgrade pack file name ' . $relative . ' is reserved by the pack format.');
                }
                if (!tb_pack_path_is_allowed($relative)) throw new RuntimeException('The upgrade pack file ' . $relative . ' is outside the allowed application paths.');
                $files[$relative] = $content;
            }
            // anything else in the archive is ignored, like unknown GitHub archive entries
        }
        if (!is_array($meta)) throw new RuntimeException('The pack.json of the upgrade pack is not valid JSON.');
        $previous = trim((string)($meta['previous_version'] ?? ''));
        $new = trim((string)($meta['new_version'] ?? ''));
        foreach ([$previous, $new] as $version) {
            if ($version === '' || !preg_match('/^[A-Za-z0-9._-]{1,128}$/', $version)) throw new RuntimeException('The pack.json must carry a previous_version and a new_version hash.');
        }
        $cleanDeletions = [];
        foreach ((array)$deletions as $entry) {
            $relative = ltrim(str_replace('\\', '/', (string)$entry), '/');
            if ($relative === '') continue;
            if (!tb_pack_path_is_allowed($relative)) throw new RuntimeException('The upgrade pack deletion ' . $relative . ' is outside the allowed application paths.');
            $cleanDeletions[] = $relative;
        }
        if (!$files && !$cleanDeletions && $migration === null) throw new RuntimeException('The upgrade pack is empty: it carries no files, deletions or migration.');
        return ['previous_version' => $previous, 'new_version' => $new, 'files' => $files, 'deletions' => array_values(array_unique($cleanDeletions)), 'migration' => $migration];
    } finally {
        if ($opened) $zip->close();
        @unlink($tmp);
    }
}

/**
 * Stores the pack's migration.sql in migrations/ under the #68 convention
 * (<14-digit-UTC-stamp>-upgrade_pack.sql) so the engine scans, applies and
 * records it exactly like an uploaded script. The stamp is what the runner
 * keys on, so a name conflict (two packs within the same second) waits for
 * the next second instead of creating two files with the same version.
 */
function tb_pack_store_migration(string $sql, string $migDir): string {
    if (!is_dir($migDir) && !@mkdir($migDir, 0775, true) && !is_dir($migDir)) {
        throw new RuntimeException('Could not create the migrations directory for the pack migration.');
    }
    $target = null;
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $candidate = $migDir . '/' . gmdate('YmdHis') . '-upgrade_pack.sql';
        if (!file_exists($candidate)) {
            $target = $candidate;
            break;
        }
        if ($attempt < 2) sleep(1);
    }
    if ($target === null) throw new RuntimeException('Could not reserve a migration file name in migrations/.');
    if (file_put_contents($target, $sql, LOCK_EX) === false) {
        throw new RuntimeException('Could not store the pack migration in migrations/.');
    }
    return $target;
}

/**
 * Applies a validated upgrade pack (#59/#69): backs up everything it is about
 * to change, writes the pack files, deletes the listed files, stores the pack
 * migration in migrations/ and applies pending migrations through the #68
 * engine, then records the pack's new_version in version.json. Any failure
 * rolls the file changes back where possible (and removes a stored migration
 * that never applied, so it cannot auto-apply on a later request against
 * rolled-back code). Returns the report parts for the page message.
 */
function tb_pack_apply(PDO $pdo, array $pack, ?string $appDir = null): array {
    $appDir = $appDir ?? __DIR__;
    $current = tb_pack_current_version_hash($appDir);
    if ($current === null) {
        throw new RuntimeException('This instance has no recorded version hash (version.json is missing or empty), so the pack cannot verify it upgrades the right installation. Install an update once, or record the current hash in version.json.');
    }
    if (!hash_equals($current, (string)$pack['previous_version'])) {
        throw new RuntimeException('The pack expects to upgrade an instance at version "' . $pack['previous_version'] . '", but this installation is at "' . $current . '". The pack does not fit.');
    }
    $backupDir = sys_get_temp_dir() . '/task-board-pack-backup-' . bin2hex(random_bytes(12));
    if (!mkdir($backupDir, 0700)) throw new RuntimeException('Could not create a private pack backup.');
    $backedUp = [];
    $written = [];
    $deleted = [];
    $migrationFile = null;
    $migrationApplied = false;
    try {
        foreach ((array)$pack['files'] as $relative => $content) {
            if (!tb_pack_path_is_allowed((string)$relative)) throw new RuntimeException('The upgrade pack file ' . $relative . ' is outside the allowed application paths.');
            $target = $appDir . '/' . $relative;
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) throw new RuntimeException('Could not create the directory needed for ' . $relative . '.');
            if (is_link($target)) throw new RuntimeException('A symbolic link target was rejected.');
            if (is_file($target)) {
                $backup = $backupDir . '/' . str_replace('/', '__', (string)$relative);
                if (!copy($target, $backup)) throw new RuntimeException('Could not back up ' . $relative . '.');
                $backedUp[(string)$relative] = $backup;
            } else {
                $backedUp[(string)$relative] = null;
            }
        }
        foreach ((array)$pack['deletions'] as $relative) {
            if (!tb_pack_path_is_allowed((string)$relative)) throw new RuntimeException('The upgrade pack deletion ' . $relative . ' is outside the allowed application paths.');
            $target = $appDir . '/' . $relative;
            if (is_link($target)) throw new RuntimeException('A symbolic link target was rejected.');
            if (is_file($target)) {
                $backup = $backupDir . '/' . str_replace('/', '__', (string)$relative);
                if (!copy($target, $backup)) throw new RuntimeException('Could not back up ' . $relative . '.');
                $backedUp[(string)$relative] = $backup;
            }
        }
        // Files first, then deletions, then the migration: a pack that swaps
        // application code and migrates the schema lands completely or not at
        // all (the migration keeps its own record in the migrations table).
        foreach ((array)$pack['files'] as $relative => $content) {
            $target = $appDir . '/' . $relative;
            $tmp = tempnam(dirname($target), '.tb-pack-');
            if ($tmp === false || file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) throw new RuntimeException('Could not write ' . $relative . '.');
            $mode = is_file($target) ? (fileperms($target) & 0777) : 0644;
            @chmod($tmp, $mode);
            if (!rename($tmp, $target)) { @unlink($tmp); throw new RuntimeException('Could not replace ' . $relative . '.'); }
            $written[] = (string)$relative;
        }
        foreach ((array)$pack['deletions'] as $relative) {
            $target = $appDir . '/' . $relative;
            if (is_file($target) && !@unlink($target)) throw new RuntimeException('Could not delete ' . $relative . '.');
            $deleted[] = (string)$relative;
        }
        if ((string)($pack['migration'] ?? '') !== '') {
            $migrationFile = tb_pack_store_migration((string)$pack['migration'], $appDir . '/migrations');
        }
        $migrations = tb_apply_pending_migrations($pdo, $appDir . '/migrations');
        // 'applied' holds the recorded version stamps; ours is the 14-digit
        // stamp that leads the stored file name.
        $migrationApplied = $migrationFile !== null
            && in_array(substr(basename($migrationFile), 0, 14), $migrations['applied'], true);
        // The migration engine reports failures instead of throwing; a pack
        // whose migration fails is an incomplete upgrade, so fail the pack
        // (the failed migration stays unrecorded and the files roll back).
        if (!empty($migrations['failed'])) {
            throw new RuntimeException('The migration run failed for ' . implode(', ', array_keys($migrations['failed'])) . ': ' . (string)reset($migrations['failed']));
        }
        // The pack's new_version becomes the recorded hash, so the next pack
        // (or GitHub update) verifies against what this pack produced.
        $installed = is_file($appDir . '/version.json') ? (json_decode((string)file_get_contents($appDir . '/version.json'), true) ?: []) : [];
        $installed = ['source' => 'upgrade-pack', 'ref' => 'pack', 'sha' => (string)$pack['new_version'], 'installed_at' => gmdate('c')] + $installed;
        $tmpJson = tempnam($appDir, '.tb-version-');
        if ($tmpJson === false || file_put_contents($tmpJson, json_encode($installed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) throw new RuntimeException('Could not record the new version.');
        if (!rename($tmpJson, $appDir . '/version.json')) throw new RuntimeException('Could not record the new version.');
        return [
            'files_changed' => $written,
            'files_deleted' => $deleted,
            'migration_file' => $migrationFile,
            'migrations' => $migrations,
            'new_version' => (string)$pack['new_version'],
        ];
    } catch (Throwable $e) {
        // A stored migration that never applied must not linger: with the file
        // changes rolled back it would auto-apply on the first next request
        // against the rolled-back code. An applied one stays recorded.
        if ($migrationFile !== null && !$migrationApplied) @unlink($migrationFile);
        foreach (array_reverse($written) as $relative) {
            $target = $appDir . '/' . $relative;
            $backup = $backedUp[$relative] ?? null;
            if ($backup && is_file($backup)) @copy($backup, $target);
            elseif (is_file($target)) @unlink($target);
        }
        foreach (array_reverse($deleted) as $relative) {
            $backup = $backedUp[$relative] ?? null;
            if ($backup && is_file($backup)) @copy($backup, $appDir . '/' . $relative);
        }
        throw new RuntimeException($e->getMessage() . ' The file changes of this pack were rolled back where possible; check the Software updates page for pending migrations.');
    }
}

/** One-line human summary of a tb_pack_apply() result (#59/#69). */
function tb_pack_result_text(array $report): string {
    $parts = [];
    if ($report['files_changed']) $parts[] = count($report['files_changed']) . ' file' . (count($report['files_changed']) === 1 ? '' : 's') . ' written (' . implode(', ', $report['files_changed']) . ')';
    if ($report['files_deleted']) $parts[] = count($report['files_deleted']) . ' file' . (count($report['files_deleted']) === 1 ? '' : 's') . ' deleted (' . implode(', ', $report['files_deleted']) . ')';
    if ($report['migration_file'] !== null) $parts[] = 'pack migration stored as ' . basename((string)$report['migration_file']);
    $migrations = $report['migrations'] ?? ['applied' => [], 'failed' => []];
    if (!empty($migrations['applied'])) {
        $parts[] = 'migrations applied: ' . implode(', ', $migrations['applied']);
    } elseif (empty($migrations['failed'])) {
        $parts[] = 'no pending migrations were found';
    }
    $parts[] = 'instance is now at version "' . $report['new_version'] . '"';
    return implode('; ', $parts) . '.';
}

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
                // Version-marked migrations (#59/#68): after the files land,
                // every pending migrations/*.sql applies automatically, so a
                // release that carries schema changes no longer needs hand-run
                // SQL. The install already happened, so a failing migration is
                // reported in the message instead of failing the update.
                $migrations = tb_apply_pending_migrations($pdo);
                $sourceInfo = null;
                $message = 'Update installed from ' . $installedLabel . '. A private backup was saved temporarily at ' . $backup . '. '
                    . (empty($migrations['applied'])
                        ? 'No pending database migrations were found.'
                        : 'Database migrations applied automatically: ' . implode(', ', $migrations['applied']) . '.')
                    . (empty($migrations['failed'])
                        ? ''
                        : ' FAILED migrations (not recorded, they will retry): ' . implode(' | ', array_map(fn(string $v, string $m): string => $v . ': ' . $m, array_keys($migrations['failed']), $migrations['failed'])));
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_migration') {
        if (!tb_update_verify_admin_password($pdo, (string)($_POST['current_password'] ?? ''))) {
            $error = 'Your current administrator password was not accepted.';
        } else {
            try {
                $message = tb_update_upload_migration($pdo);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply_pack') {
        // Runs pack files and SQL against this installation, so it re-checks
        // the administrator password like the install and upload actions (#59/#69).
        if (!tb_update_verify_admin_password($pdo, (string)($_POST['current_password'] ?? ''))) {
            $error = 'Your current administrator password was not accepted.';
        } else {
            try {
                if (!isset($_FILES['pack_zip']) || !is_array($_FILES['pack_zip']) || (int)($_FILES['pack_zip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Choose an upgrade pack ZIP to apply.');
                }
                $pack = tb_pack_read((string)file_get_contents((string)$_FILES['pack_zip']['tmp_name']));
                $packReport = tb_pack_apply($pdo, $pack);
                $message = 'Upgrade pack applied: ' . tb_pack_result_text($packReport);
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
    $migrationRows = tb_update_migration_status($pdo, __DIR__ . '/migrations');
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
        <hr><h3 class="h6">Upload a SQL migration</h3>
        <p class="small muted">A database change for this instance (requirement 1 of the upgrade packs, #59): upload a <span class="font-monospace">.sql</span> file (max 1 MB) and it is stored version-marked in <span class="font-monospace">migrations/</span> and applied immediately — recorded once, never re-run. Statements end at a semicolon that closes the line; write scripts re-runnable (<span class="font-monospace">IF [NOT] EXISTS</span> guards) because MySQL auto-commits DDL. Every pending file in <span class="font-monospace">migrations/</span> — uploaded or shipped with the code — also applies automatically on the first request after a deployment.</p>
        <form method="post" enctype="multipart/form-data" class="mb-3" onsubmit="return confirm('Upload and apply this migration now? Make sure you have a database backup.')"><?=csrf_field()?>
            <input type="hidden" name="action" value="upload_migration">
            <div class="mb-3"><input class="form-control" type="file" name="migration_file" accept=".sql" required></div>
            <div class="mb-3"><label class="form-label" for="migration_password">Current administrator password</label><input class="form-control" type="password" id="migration_password" name="current_password" autocomplete="current-password" required></div>
            <button class="btn btn-outline-primary">Upload and apply migration</button>
        </form>
        <hr><h3 class="h6">Apply an upgrade pack</h3>
        <p class="small muted">A pack upgrades this instance without GitHub. The ZIP carries <span class="font-monospace">pack.json</span> (<span class="font-monospace">previous_version</span> = the hash this instance must be at, <span class="font-monospace">new_version</span> = the hash it becomes), the patched or new application files under <span class="font-monospace">files/</span>, an optional <span class="font-monospace">deletions.json</span> (array of files to remove) and an optional <span class="font-monospace">migration.sql</span> that runs through the same migration engine the upload above uses (#68). The pack is accepted only when <span class="font-monospace">previous_version</span> matches this installation's recorded hash; <span class="font-monospace">config.php</span>, the boot files and <span class="font-monospace">uploads/</span> are untouchable, and everything the pack changes is backed up and rolled back when the pack fails.</p>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end" onsubmit="return confirm('Apply this upgrade pack now? Make sure you have a database backup.')"><?=csrf_field()?>
            <input type="hidden" name="action" value="apply_pack">
            <div class="col-md-5"><label class="form-label" for="pack_zip">Upgrade pack ZIP (max 20 MB)</label><input class="form-control" type="file" id="pack_zip" name="pack_zip" accept=".zip" required></div>
            <div class="col-md-3"><label class="form-label" for="pack_password">Current administrator password</label><input class="form-control" type="password" id="pack_password" name="current_password" autocomplete="current-password" required></div>
            <div class="col-md-2"><button class="btn btn-outline-primary">Validate and apply pack</button></div>
        </form>
        <p class="small muted mt-2">This installation is at version hash: <span class="font-monospace"><?=h(tb_pack_current_version_hash() ?? 'not recorded (version.json missing or empty)')?></span></p>
        <?php if ($migrationRows !== null): ?>
            <h3 class="h6">Migration state</h3>
            <div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-3"><thead><tr><th scope="col">Version</th><th scope="col">File</th><th scope="col">Applied</th></tr></thead><tbody>
            <?php foreach ($migrationRows as $row): ?><tr><td class="font-monospace"><?=h($row['version'])?></td><td><?=h($row['file'])?></td><td><?php if ($row['applied_at'] !== null): ?><?=h($row['applied_at'])?><?php else: ?><span class="badge text-bg-warning text-dark">pending</span><?php endif; ?></td></tr><?php endforeach; ?>
            <?php if (!$migrationRows): ?><tr><td colspan="3" class="text-center muted py-3">No migration files yet. Pending scripts appear here before they run; the table fills as migrations apply.</td></tr><?php endif; ?>
            </tbody></table></div>
        <?php endif; ?>
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
