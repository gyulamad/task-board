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
        if ($zip->status !== ZipArchive::ER_OK && $zip->numFiles === 0) { /* close below */ }
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
            if (!is_dir($parent) || !is_writable($parent)) throw new RuntimeException('The hosting account cannot write to ' . $relative . '.');
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

function task_board_update_page(PDO $pdo, array $config): void {
    $error = '';
    $message = '';
    $selectedType = $_POST['source_type'] ?? 'release';
    $selectedBranch = trim($_POST['branch'] ?? 'master');
    $sourceInfo = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install_update') {
        $admin = $pdo->prepare('SELECT password_hash FROM users WHERE id=? AND role=\'admin\'');
        $admin->execute([user()['id']]);
        $hash = $admin->fetchColumn();
        if (!$hash || !password_verify($_POST['current_password'] ?? '', (string)$hash)) {
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
        <?php if ($sourceInfo): ?>
            <hr><h3 class="h6">Install <?=h($sourceInfo['label'])?></h3>
            <p class="small muted">The updater changes only the application's allowlisted files. It never overwrites config.php or uploaded files, deletes files, executes SQL, or runs downloaded scripts as part of extraction. A private backup is created first. Make your own database backup before updating.</p>
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
