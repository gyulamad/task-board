<?php
declare(strict_types=1);
define('TASK_BOARD_BOOTSTRAPPED', true);
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
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
verify_csrf();

$page = $_GET['page'] ?? 'tasks';
$error = '';

if ($page === 'logout') { session_destroy(); redirect('?page=login'); }

if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $s = $pdo->prepare('SELECT * FROM users WHERE username=?');
    $s->execute([trim($_POST['username'] ?? '')]);
    $u = $s->fetch();
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$u['id'], 'username' => $u['username'], 'display_name' => $u['display_name'], 'role' => $u['role']];
        redirect('?');
    }
    $error = 'Invalid username or password.';
}

if ($page === 'install') {
    // Disable the installer completely after the first account exists.
    // This protects direct GET requests and POST submissions alike.
    $existingUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($existingUsers > 0) {
        http_response_code(404);
        exit('Not found.');
    }
}

if ($page === 'install' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Run schema.sql manually first. This creates the first administrator.
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count > 0) $error = 'An account already exists. Sign in instead.';
    elseif (strlen($_POST['password'] ?? '') < 10) $error = 'Use a password with at least 10 characters.';
    else {
        $s = $pdo->prepare("INSERT INTO users(username,password_hash,display_name,role) VALUES(?,?,?,'admin')");
        $s->execute([trim($_POST['username']), password_hash($_POST['password'], PASSWORD_DEFAULT), trim($_POST['display_name'])]);
        flash('Administrator created. Please sign in.'); redirect('?page=login');
    }
}

if (user()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        try {
            if ($action === 'save_task') {
                $id = (int)($_POST['id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $statusId = (int)($_POST['status_id'] ?? 0);
                $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
                if ($title === '') throw new RuntimeException('Task title is required.');
                if ($parentId && ($parentId === $id || !task_exists($pdo, $parentId))) throw new RuntimeException('Invalid parent task.');
                $s = $pdo->prepare('SELECT id FROM statuses WHERE id=?'); $s->execute([$statusId]);
                if (!$s->fetch()) throw new RuntimeException('Choose a valid status.');
                $assignees = array_values(array_unique(array_map('intval', $_POST['assignees'] ?? [])));
                if ($id) {
                    $s = $pdo->prepare('SELECT * FROM tasks WHERE id=?'); $s->execute([$id]); $old = $s->fetch();
                    if (!$old) throw new RuntimeException('Task not found.');
                    $pdo->prepare('UPDATE tasks SET title=?,description=?,status_id=?,parent_id=? WHERE id=?')->execute([$title,$description,$statusId,$parentId,$id]);
                    $pdo->prepare('DELETE FROM task_assignees WHERE task_id=?')->execute([$id]);
                    history($pdo, $id, 'Task updated', 'Title/status/description/parent or assignees may have changed.');
                } else {
                    $pdo->prepare('INSERT INTO tasks(title,description,status_id,parent_id,created_by) VALUES(?,?,?,?,?)')->execute([$title,$description,$statusId,$parentId,user()['id']]);
                    $id = (int)$pdo->lastInsertId();
                    history($pdo, $id, 'Task created', $title);
                }
                $a = $pdo->prepare('INSERT IGNORE INTO task_assignees(task_id,user_id) VALUES(?,?)');
                foreach ($assignees as $uid) $a->execute([$id,$uid]);
                redirect('?page=task&id=' . $id);
            }
            if ($action === 'delete_task') {
                $id = (int)$_POST['id'];
                if (!is_admin() && (int)$pdo->query('SELECT created_by FROM tasks WHERE id=' . $id)->fetchColumn() !== (int)user()['id']) throw new RuntimeException('Only an administrator or task creator can delete this task.');
                $pdo->prepare('DELETE FROM tasks WHERE id=?')->execute([$id]); flash('Task deleted.'); redirect('?');
            }
            if ($action === 'comment') {
                $id = (int)$_POST['task_id']; $body = trim($_POST['body'] ?? '');
                if (!task_exists($pdo,$id) || $body === '') throw new RuntimeException('Comment cannot be empty.');
                $pdo->prepare('INSERT INTO comments(task_id,user_id,body) VALUES(?,?,?)')->execute([$id,user()['id'],$body]);
                history($pdo,$id,'Comment added','A comment was added.'); redirect('?page=task&id='.$id.'#comments');
            }
            if ($action === 'link_task') {
                $id=(int)$_POST['task_id']; $other=(int)$_POST['related_task_id']; $type=$_POST['link_type'] ?? '';
                if ($id===$other || !task_exists($pdo,$id) || !task_exists($pdo,$other) || !in_array($type,['blocks','depends_on','relates_to'],true)) throw new RuntimeException('Invalid task relationship.');
                $pdo->prepare('INSERT IGNORE INTO task_links(task_id,related_task_id,link_type) VALUES(?,?,?)')->execute([$id,$other,$type]);
                history($pdo,$id,'Relationship added',$type.' task #'.$other); redirect('?page=task&id='.$id);
            }
            if ($action === 'unlink_task') {
                $id=(int)$_POST['task_id']; $other=(int)$_POST['related_task_id']; $type=$_POST['link_type'];
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
            if ($action === 'add_status' && is_admin()) {
                $name=trim($_POST['name'] ?? '');
                if ($name==='') throw new RuntimeException('Status name is required.');
                $pdo->prepare('INSERT INTO statuses(name,sort_order,is_done) VALUES(?,?,?)')->execute([$name,(int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM statuses')->fetchColumn(),isset($_POST['is_done'])?1:0]);
                flash('Status added.'); redirect('?page=admin');
            }
            if ($action === 'add_user' && is_admin()) {
                $name=trim($_POST['username'] ?? ''); $display=trim($_POST['display_name'] ?? ''); $pass=$_POST['password'] ?? '';
                if ($name==='' || $display==='' || strlen($pass)<10) throw new RuntimeException('Enter username, display name and a password of at least 10 characters.');
                $pdo->prepare('INSERT INTO users(username,password_hash,display_name,role) VALUES(?,?,?,?)')->execute([$name,password_hash($pass,PASSWORD_DEFAULT),$display,$_POST['role']==='admin'?'admin':'user']);
                flash('User created.'); redirect('?page=admin');
            }
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

function render_header(string $title, array $config): void {
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?> - <?=h($config['app_name'])?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#f5f6f8}.card{border:0;box-shadow:0 1px 5px #0000000d}.muted{color:#687386}.prewrap{white-space:pre-wrap}.navbar-brand{font-weight:700}</style></head><body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark"><div class="container"><a class="navbar-brand" href="?"><?=h($config['app_name'])?></a><?php if(user()): ?><div class="navbar-nav ms-auto align-items-center"><a class="nav-link" href="?">Tasks</a><?php if(is_admin()): ?><a class="nav-link" href="?page=admin">Administration</a><a class="nav-link" href="?page=updates">Software updates</a><?php endif; ?><span class="navbar-text mx-2"><?=h(user()['display_name'])?></span><a class="nav-link" href="?page=logout">Sign out</a></div><?php endif; ?></div></nav><main class="container py-4">
    <?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success"><?=h($_SESSION['flash'])?></div><?php unset($_SESSION['flash']); endif; ?>
    <?php global $error; if($error): ?><div class="alert alert-danger"><?=h($error)?></div><?php endif;
}
function render_footer(): void { ?></main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script></body></html><?php }

if (!user() && !in_array($page,['login','install'],true)) redirect('?page=login');

if ($page==='login') {
    render_header('Sign in',$config); ?>
    <div class="row justify-content-center"><div class="col-md-5"><div class="card p-4"><h2 class="h4 mb-3">Sign in</h2><form method="post"><?=csrf_field()?><div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" required autofocus></div><div class="mb-3"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required></div><button class="btn btn-primary w-100">Sign in</button></form><?php if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0): ?><p class="small muted mt-3 mb-0">First time here? <a href="?page=install">Create the administrator account</a>.</p><?php endif; ?></div></div></div>
    <?php render_footer(); exit;
}
if ($page==='install') {
    render_header('Initial setup',$config); ?>
    <div class="row justify-content-center"><div class="col-md-6"><div class="card p-4"><h2 class="h4">Create administrator</h2><p class="muted">Run schema.sql in phpMyAdmin before continuing. This page only works while there are no users.</p><form method="post"><?=csrf_field()?><div class="mb-3"><label class="form-label">Username</label><input class="form-control" name="username" required></div><div class="mb-3"><label class="form-label">Display name</label><input class="form-control" name="display_name" required></div><div class="mb-3"><label class="form-label">Password (10+ characters)</label><input class="form-control" type="password" name="password" minlength="10" required></div><button class="btn btn-primary">Create administrator</button></form></div></div></div>
    <?php render_footer(); exit;
}

if ($page==='download') {
    $id=(int)($_GET['id']??0); $s=$pdo->prepare('SELECT * FROM attachments WHERE id=?'); $s->execute([$id]); $a=$s->fetch();
    if(!$a || !is_file(__DIR__.'/uploads/'.$a['stored_name'])) { http_response_code(404); exit('File not found.'); }
    header('Content-Type: '.$a['mime_type']); header('Content-Length: '.$a['file_size']);
    header('Content-Disposition: attachment; filename="'.str_replace('"','', $a['original_name']).'"');
    readfile(__DIR__.'/uploads/'.$a['stored_name']); exit;
}

if ($page === 'updates') {
    if (!is_admin()) { http_response_code(403); exit('Forbidden.'); }
    require __DIR__ . '/updater.php';
    task_board_update_page($pdo, $config);
    exit;
}

if ($page==='admin' && is_admin()) {
    $users=$pdo->query('SELECT id,username,display_name,role,created_at FROM users ORDER BY display_name')->fetchAll();
    $statuses=$pdo->query('SELECT * FROM statuses ORDER BY sort_order,id')->fetchAll();
    render_header('Administration',$config); ?>
    <div class="row g-4"><div class="col-lg-6"><div class="card p-4"><h2 class="h5">Create user</h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="add_user"><div class="mb-2"><input class="form-control" name="username" placeholder="Username" required></div><div class="mb-2"><input class="form-control" name="display_name" placeholder="Display name" required></div><div class="mb-2"><input class="form-control" type="password" name="password" placeholder="Password (10+ characters)" minlength="10" required></div><div class="mb-3"><select class="form-select" name="role"><option value="user">User</option><option value="admin">Administrator</option></select></div><button class="btn btn-primary">Create user</button></form></div>
    <div class="card p-4 mt-4"><h2 class="h5">Users</h2><ul class="list-group"><?php foreach($users as $u): ?><li class="list-group-item d-flex justify-content-between"><?=h($u['display_name'])?> <span class="badge text-bg-secondary"><?=h($u['role'])?></span></li><?php endforeach; ?></ul></div></div>
    <div class="col-lg-6"><div class="card p-4"><h2 class="h5">Task statuses</h2><ul class="list-group mb-3"><?php foreach($statuses as $st): ?><li class="list-group-item d-flex justify-content-between"><?=h($st['name'])?><?php if($st['is_done']): ?><span class="badge text-bg-success">Completion status</span><?php endif; ?></li><?php endforeach; ?></ul><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="add_status"><div class="mb-2"><input class="form-control" name="name" placeholder="New status label" required></div><div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_done" id="done"><label class="form-check-label" for="done">Treat as completed</label></div><button class="btn btn-primary">Add status</button></form><p class="small muted mt-3">Existing statuses cannot currently be renamed or deleted from the interface; edit the statuses table in phpMyAdmin if needed.</p></div></div></div>
    <?php render_footer(); exit;
}

if ($page==='edit' || $page==='new') {
    $id=(int)($_GET['id']??0); $task=['id'=>0,'title'=>'','description'=>'','status_id'=>0,'parent_id'=>null]; $selected=[];
    if($id){$s=$pdo->prepare('SELECT * FROM tasks WHERE id=?');$s->execute([$id]);$task=$s->fetch() ?: $task;$s=$pdo->prepare('SELECT user_id FROM task_assignees WHERE task_id=?');$s->execute([$id]);$selected=array_map('intval',array_column($s->fetchAll(),'user_id'));}
    $users=$pdo->query('SELECT id,display_name FROM users ORDER BY display_name')->fetchAll();$statuses=$pdo->query('SELECT * FROM statuses ORDER BY sort_order,id')->fetchAll();$tasks=$pdo->prepare('SELECT id,title FROM tasks WHERE id<>? ORDER BY id DESC LIMIT 200');$tasks->execute([$id]);
    render_header($id?'Edit task':'New task',$config); ?>
    <div class="card p-4"><h2 class="h4"><?= $id?'Edit task':'Create task' ?></h2><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_task"><input type="hidden" name="id" value="<?=$id?>"><div class="mb-3"><label class="form-label">Title</label><input class="form-control" name="title" maxlength="200" value="<?=h($task['title'])?>" required></div><div class="mb-3"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="5"><?=h($task['description'])?></textarea></div><div class="row"><div class="col-md-6 mb-3"><label class="form-label">Status</label><select class="form-select" name="status_id" required><?php foreach($statuses as $st): ?><option value="<?=$st['id']?>" <?=$task['status_id']==$st['id']?'selected':''?>><?=h($st['name'])?></option><?php endforeach; ?></select></div><div class="col-md-6 mb-3"><label class="form-label">Parent task</label><select class="form-select" name="parent_id"><option value="">None</option><?php foreach($tasks->fetchAll() as $t): ?><option value="<?=$t['id']?>" <?=$task['parent_id']==$t['id']?'selected':''?>>#<?=$t['id']?> - <?=h($t['title'])?></option><?php endforeach; ?></select></div></div><div class="mb-3"><label class="form-label">Assignees</label><select class="form-select" name="assignees[]" multiple size="5"><?php foreach($users as $u): ?><option value="<?=$u['id']?>" <?=in_array((int)$u['id'],$selected,true)?'selected':''?>><?=h($u['display_name'])?></option><?php endforeach; ?></select><div class="form-text">Use Ctrl/Cmd to select multiple people.</div></div><button class="btn btn-primary">Save task</button> <a class="btn btn-outline-secondary" href="?">Cancel</a></form></div>
    <?php render_footer(); exit;
}

if ($page==='task') {
    $id=(int)($_GET['id']??0);$s=$pdo->prepare('SELECT t.*,s.name status_name,u.display_name creator FROM tasks t JOIN statuses s ON s.id=t.status_id JOIN users u ON u.id=t.created_by WHERE t.id=?');$s->execute([$id]);$t=$s->fetch();
    if(!$t){http_response_code(404);exit('Task not found.');}
    $s=$pdo->prepare('SELECT u.display_name FROM task_assignees a JOIN users u ON u.id=a.user_id WHERE a.task_id=?');$s->execute([$id]);$assignees=$s->fetchAll();
    $s=$pdo->prepare('SELECT c.*,u.display_name FROM comments c JOIN users u ON u.id=c.user_id WHERE c.task_id=? ORDER BY c.created_at');$s->execute([$id]);$comments=$s->fetchAll();
    $s=$pdo->prepare('SELECT a.*,u.display_name FROM attachments a JOIN users u ON u.id=a.user_id WHERE a.task_id=? ORDER BY a.created_at DESC');$s->execute([$id]);$attachments=$s->fetchAll();
    $s=$pdo->prepare('SELECT h.*,u.display_name FROM task_history h JOIN users u ON u.id=h.user_id WHERE h.task_id=? ORDER BY h.created_at DESC');$s->execute([$id]);$historyRows=$s->fetchAll();
    $s=$pdo->prepare('SELECT l.*,t.title FROM task_links l JOIN tasks t ON t.id=l.related_task_id WHERE l.task_id=? ORDER BY l.link_type');$s->execute([$id]);$links=$s->fetchAll();
    $all=$pdo->prepare('SELECT id,title FROM tasks WHERE id<>? ORDER BY id DESC LIMIT 200');$all->execute([$id]);
    render_header('Task #'.$id,$config); ?>
    <div class="d-flex justify-content-between align-items-start mb-3"><div><div class="muted">Task #<?=$id?> · <?=h($t['status_name'])?></div><h1 class="h3"><?=h($t['title'])?></h1></div><a class="btn btn-primary" href="?page=edit&id=<?=$id?>">Edit task</a></div>
    <div class="row g-4"><div class="col-lg-8"><div class="card p-4 mb-4"><div class="prewrap"><?=h($t['description'])?></div><hr><div class="small muted">Created by <?=h($t['creator'])?> · <?=h($t['created_at'])?> · Updated <?=h($t['updated_at'])?></div><div class="mt-2"><strong>Assignees:</strong> <?=h(implode(', ',array_column($assignees,'display_name')) ?: 'Unassigned')?></div><?php if($t['parent_id']): ?><div><strong>Parent:</strong> <a href="?page=task&id=<?=$t['parent_id']?>">Task #<?=$t['parent_id']?></a></div><?php endif; ?></div>
    <div class="card p-4 mb-4" id="comments"><h2 class="h5">Comments</h2><?php foreach($comments as $c): ?><div class="border-bottom py-3"><strong><?=h($c['display_name'])?></strong> <span class="small muted"><?=h($c['created_at'])?></span><div class="prewrap mt-1"><?=h($c['body'])?></div></div><?php endforeach; ?><form method="post" class="mt-3"><?=csrf_field()?><input type="hidden" name="action" value="comment"><input type="hidden" name="task_id" value="<?=$id?>"><textarea class="form-control mb-2" name="body" rows="3" placeholder="Write a comment..." required></textarea><button class="btn btn-primary">Add comment</button></form></div>
    <div class="card p-4 mb-4"><h2 class="h5">Attachments</h2><?php foreach($attachments as $a): ?><div class="d-flex justify-content-between border-bottom py-2"><a href="?page=download&id=<?=$a['id']?>"><?=h($a['original_name'])?></a><span class="small muted"><?=h($a['display_name'])?> · <?=number_format($a['file_size']/1024,1)?> KB</span></div><?php endforeach; ?><form method="post" enctype="multipart/form-data" class="mt-3"><?=csrf_field()?><input type="hidden" name="action" value="upload"><input type="hidden" name="task_id" value="<?=$id?>"><div class="input-group"><input class="form-control" type="file" name="file" required><button class="btn btn-outline-primary">Upload</button></div><div class="form-text">Max <?=round($config['max_upload_bytes']/1024/1024)?> MB. Allowed: common images, PDF, TXT, ZIP, DOCX and XLSX.</div></form></div>
    <div class="card p-4"><h2 class="h5">History</h2><?php foreach($historyRows as $r): ?><div class="border-bottom py-2"><strong><?=h($r['event'])?></strong> <span class="small muted">by <?=h($r['display_name'])?> · <?=h($r['created_at'])?></span><?php if($r['details']): ?><div class="small"><?=h($r['details'])?></div><?php endif; ?></div><?php endforeach; ?></div></div>
    <div class="col-lg-4"><div class="card p-4"><h2 class="h5">Relationships</h2><?php foreach($links as $l): ?><div class="border-bottom py-2"><span class="badge text-bg-secondary"><?=h($l['link_type'])?></span> <a href="?page=task&id=<?=$l['related_task_id']?>">#<?=$l['related_task_id']?> <?=h($l['title'])?></a><form method="post" class="d-inline"><?=csrf_field()?><input type="hidden" name="action" value="unlink_task"><input type="hidden" name="task_id" value="<?=$id?>"><input type="hidden" name="related_task_id" value="<?=$l['related_task_id']?>"><input type="hidden" name="link_type" value="<?=h($l['link_type'])?>"><button class="btn btn-sm btn-link text-danger">remove</button></form></div><?php endforeach; ?><form method="post" class="mt-3"><?=csrf_field()?><input type="hidden" name="action" value="link_task"><input type="hidden" name="task_id" value="<?=$id?>"><label class="form-label">Link type</label><select class="form-select mb-2" name="link_type"><option value="blocks">Blocks</option><option value="depends_on">Depends on</option><option value="relates_to">Relates to</option></select><label class="form-label">Task</label><select class="form-select mb-2" name="related_task_id" required><?php foreach($all->fetchAll() as $a): ?><option value="<?=$a['id']?>">#<?=$a['id']?> - <?=h($a['title'])?></option><?php endforeach; ?></select><button class="btn btn-outline-primary w-100">Add relationship</button></form></div>
    <div class="card p-4 mt-4"><h2 class="h5">Actions</h2><form method="post" onsubmit="return confirm('Delete this task permanently?')"><?=csrf_field()?><input type="hidden" name="action" value="delete_task"><input type="hidden" name="id" value="<?=$id?>"><button class="btn btn-outline-danger w-100">Delete task</button></form></div></div></div>
    <?php render_footer(); exit;
}

$statuses=$pdo->query('SELECT * FROM statuses ORDER BY sort_order,id')->fetchAll();
$users=$pdo->query('SELECT id,display_name FROM users ORDER BY display_name')->fetchAll();
$where=[];$params=[];
if (!empty($_GET['assignee'])) {$where[]='EXISTS(SELECT 1 FROM task_assignees ax WHERE ax.task_id=t.id AND ax.user_id=?)';$params[]=(int)$_GET['assignee'];}
if (!empty($_GET['status'])) {$where[]='t.status_id=?';$params[]=(int)$_GET['status'];}
if (!empty($_GET['q'])) {$where[]='(t.title LIKE ? OR t.description LIKE ?)';$params[]='%'.$_GET['q'].'%';$params[]='%'.$_GET['q'].'%';}
$sql='SELECT t.id,t.title,t.updated_at,s.name status_name FROM tasks t JOIN statuses s ON s.id=t.status_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY t.updated_at DESC';
$s=$pdo->prepare($sql);$s->execute($params);$tasks=$s->fetchAll();
render_header('Tasks',$config); ?>
<div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-0">Tasks</h1><div class="muted"><?=count($tasks)?> tasks</div></div><a class="btn btn-primary" href="?page=new">+ New task</a></div>
<div class="card p-3 mb-3"><form class="row g-2"><input type="hidden" name="page" value="tasks"><div class="col-md-4"><input class="form-control" name="q" value="<?=h($_GET['q']??'')?>" placeholder="Search tasks"></div><div class="col-md-3"><select class="form-select" name="assignee"><option value="">All assignees</option><?php foreach($users as $u): ?><option value="<?=$u['id']?>" <?=($_GET['assignee']??'')==$u['id']?'selected':''?>><?=h($u['display_name'])?></option><?php endforeach; ?></select></div><div class="col-md-3"><select class="form-select" name="status"><option value="">All statuses</option><?php foreach($statuses as $st): ?><option value="<?=$st['id']?>" <?=($_GET['status']??'')==$st['id']?'selected':''?>><?=h($st['name'])?></option><?php endforeach; ?></select></div><div class="col-md-2 d-grid"><button class="btn btn-outline-primary">Filter</button></div></form></div>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th class="ps-3">Task</th><th>Status</th><th>Assignees</th><th>Updated</th></tr></thead><tbody><?php foreach($tasks as $t): ?><tr><td class="ps-3"><a class="fw-semibold text-decoration-none" href="?page=task&id=<?=$t['id']?>">#<?=$t['id']?> · <?=h($t['title'])?></a></td><td><span class="badge text-bg-light border"><?=h($t['status_name'])?></span></td><td><?php $as=$pdo->prepare('SELECT u.display_name FROM task_assignees a JOIN users u ON u.id=a.user_id WHERE a.task_id=?');$as->execute([$t['id']]);echo h(implode(', ',array_column($as->fetchAll(),'display_name')) ?: '—'); ?></td><td class="small muted"><?=h($t['updated_at'])?></td></tr><?php endforeach; if(!$tasks): ?><tr><td colspan="4" class="text-center muted py-4">No tasks found.</td></tr><?php endif; ?></tbody></table></div></div>
<?php render_footer(); ?>
