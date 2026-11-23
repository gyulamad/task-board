<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #17: users must be editable and deletable from the admin page.
 * Covers the edit_user/delete_user actions, their guards and the content
 * preservation rules applied when a user is deleted.
 */
final class UserManagementTest extends E2eTestCase
{
    private function createUser(AppSession $admin, string $username, string $display, string $password, string $role = 'user'): void
    {
        $response = $admin->post('/?page=users', [
            'action' => 'add_user',
            'username' => $username,
            'display_name' => $display,
            'password' => $password,
            'role' => $role,
        ]);
        $this->assertSame(302, $response->status);
    }

    private function userId(string $username): int
    {
        $s = TestServer::pdo()->prepare('SELECT id FROM users WHERE username=?');
        $s->execute([$username]);
        $id = $s->fetchColumn();
        $this->assertNotFalse($id, 'User ' . $username . ' does not exist.');
        return (int)$id;
    }

    public function test_admin_can_edit_username_and_display_name_and_the_password_is_kept(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'dave', 'Dave User', 'DavePass12345');

        $response = $admin->post('/?page=users', [
            'action' => 'edit_user',
            'id' => (string)$this->userId('dave'),
            'username' => 'david',
            'display_name' => 'Dave Edited',
            'role' => 'user',
            'password' => '',
        ]);
        $this->assertSame(302, $response->status);

        $adminPage = $admin->get('/?page=users');
        $this->assertTrue($adminPage->contains('Dave Edited'));
        $this->assertTrue($adminPage->contains('david'));
        $this->assertFalse($adminPage->contains('Dave User'));

        // Old password still works after an edit without a password change.
        $david = new AppSession('david', 'DavePass12345');
        $this->assertSame(200, $david->get('/?')->status);
    }

    public function test_a_self_edit_keeps_the_theme_preference(): void
    {
        // Task #61: preferences are stored id-based (users.theme on users.id),
        // so renaming the own account must not lose the theme. The regression
        // was in the session rebuild after the save, which dropped the theme
        // key and flipped the still-signed-in admin back to the light theme.
        $admin = $this->adminSession();
        $this->assertSame(302, $admin->post('/?', ['action' => 'save_theme', 'theme' => 'dark'])->status);
        $this->assertTrue($admin->get('/?')->contains('<html lang="en" data-bs-theme="dark">'));

        $response = $admin->post('/?page=users', [
            'action' => 'edit_user',
            'id' => (string)$this->userId('admin'),
            'username' => 'root',
            'display_name' => 'Root Administrator',
            'role' => 'admin',
            'password' => '',
        ]);
        $this->assertSame(302, $response->status);

        // The same session must still render dark right after the self-edit.
        $this->assertTrue(
            $admin->get('/?')->contains('<html lang="en" data-bs-theme="dark">'),
            'Renaming the own account must not reset the theme of the signed-in session.'
        );
        // The stored preference is untouched in the database.
        $s = TestServer::pdo()->prepare('SELECT theme FROM users WHERE id=?');
        $s->execute([$this->userId('root')]);
        $this->assertSame('dark', $s->fetchColumn());
        // ...and a fresh sign-in with the new username picks the theme up again.
        $fresh = new AppSession('root', 'AdminPass1234');
        $this->assertTrue($fresh->get('/?')->contains('<html lang="en" data-bs-theme="dark">'));
    }

    public function test_admin_can_reset_a_password_and_the_old_one_stops_working(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'eve', 'Eve User', 'EvePass12345');

        $response = $admin->post('/?page=users', [
            'action' => 'edit_user',
            'id' => (string)$this->userId('eve'),
            'username' => 'eve',
            'display_name' => 'Eve User',
            'role' => 'user',
            'password' => 'EveNewPass1234',
        ]);
        $this->assertSame(302, $response->status);

        $oldLogin = new AppSession();
        $failed = $oldLogin->login('eve', 'EvePass12345');
        $this->assertSame(200, $failed->status);
        $this->assertTrue($failed->contains('Invalid username or password.'));

        $eve = new AppSession('eve', 'EveNewPass1234');
        $this->assertSame(200, $eve->get('/?')->status);
    }

    public function test_admin_can_promote_a_user_to_administrator_and_back(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'promoted', 'Promoted User', 'PromoPass12345');

        $admin->post('/?page=users', [
            'action' => 'edit_user',
            'id' => (string)$this->userId('promoted'),
            'username' => 'promoted',
            'display_name' => 'Promoted User',
            'role' => 'admin',
            'password' => '',
        ]);
        $secondAdmin = new AppSession('promoted', 'PromoPass12345');
        $this->assertTrue($secondAdmin->get('/?page=users')->contains('Create user'));

        // With two administrators the demotion of one must succeed.
        $response = $admin->post('/?page=users', [
            'action' => 'edit_user',
            'id' => (string)$this->userId('promoted'),
            'username' => 'promoted',
            'display_name' => 'Promoted User',
            'role' => 'user',
            'password' => '',
        ]);
        $this->assertSame(302, $response->status);
        $s = TestServer::pdo()->prepare('SELECT role FROM users WHERE username=?');
        $s->execute(['promoted']);
        $this->assertSame('user', $s->fetchColumn());
    }

    public function test_edit_rejects_duplicate_username_blank_names_and_short_passwords(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'dave', 'Dave User', 'DavePass12345');

        $duplicate = $admin->post('/?page=users', [
            'action' => 'edit_user',
            'id' => (string)$this->userId('dave'),
            'username' => 'admin',
            'display_name' => 'Dave User',
            'role' => 'user',
            'password' => '',
        ]);
        $this->assertTrue($duplicate->contains('This username is already taken.'));

        $blank = $admin->post('/?page=users', [
            'action' => 'edit_user',
            'id' => (string)$this->userId('dave'),
            'username' => '  ',
            'display_name' => '  ',
            'role' => 'user',
            'password' => '',
        ]);
        $this->assertTrue($blank->contains('Enter a username and a display name.'));

        $short = $admin->post('/?page=users', [
            'action' => 'edit_user',
            'id' => (string)$this->userId('dave'),
            'username' => 'dave',
            'display_name' => 'Dave User',
            'role' => 'user',
            'password' => 'short',
        ]);
        $this->assertTrue($short->contains('Use a password with at least 10 characters'));

        // Nothing changed in the database after the three failed attempts.
        $s = TestServer::pdo()->prepare('SELECT username,display_name,role FROM users WHERE id=?');
        $s->execute([$this->userId('dave')]);
        $this->assertSame(['username' => 'dave', 'display_name' => 'Dave User', 'role' => 'user'], $s->fetch(\PDO::FETCH_ASSOC));
    }

    public function test_the_last_administrator_cannot_be_demoted_or_deleted(): void
    {
        $admin = $this->adminSession(); // the only administrator of this fresh board
        $adminId = $this->userId('admin');

        $demote = $admin->post('/?page=users', [
            'action' => 'edit_user',
            'id' => (string)$adminId,
            'username' => 'admin',
            'display_name' => 'Administrator',
            'role' => 'user',
            'password' => '',
        ]);
        $this->assertTrue($demote->contains('At least one administrator must remain.'));

        $delete = $admin->post('/?page=users', [
            'action' => 'delete_user',
            'id' => (string)$adminId,
        ]);
        $this->assertTrue($delete->contains('You cannot delete your own account.'));

        $s = TestServer::pdo()->query("SELECT COUNT(*) FROM users WHERE role='admin'");
        $this->assertSame(1, (int)$s->fetchColumn());
    }

    public function test_regular_users_cannot_edit_or_delete_users_via_post(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'mallory', 'Mallory User', 'MalloryPass12345');
        $this->createUser($admin, 'victim', 'Victim User', 'VictimPass12345');

        $mallory = new AppSession('mallory', 'MalloryPass12345');
        $mallory->post('/?page=users', [
            'action' => 'edit_user',
            'id' => (string)$this->userId('admin'),
            'username' => 'hacked',
            'display_name' => 'Hacked',
            'role' => 'user',
            'password' => '',
        ]);
        $mallory->post('/?page=users', [
            'action' => 'delete_user',
            'id' => (string)$this->userId('victim'),
        ]);

        $s = TestServer::pdo()->prepare('SELECT username,display_name,role FROM users WHERE id=?');
        $s->execute([$this->userId('admin')]);
        $this->assertSame(['username' => 'admin', 'display_name' => 'Administrator', 'role' => 'admin'], $s->fetch(\PDO::FETCH_ASSOC));
        $s = TestServer::pdo()->prepare('SELECT COUNT(*) FROM users WHERE username=?');
        $s->execute(['victim']);
        $this->assertSame(1, (int)$s->fetchColumn());
    }

    public function test_admin_can_delete_a_user_who_never_contributed(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'ghost', 'Ghost User', 'GhostPass12345');

        $response = $admin->post('/?page=users', [
            'action' => 'delete_user',
            'id' => (string)$this->userId('ghost'),
        ]);
        $this->assertSame(302, $response->status);
        $this->assertTrue($admin->get('/?page=users')->contains('User deleted.'));
        $this->assertFalse($admin->get('/?page=users')->contains('Ghost User'));

        $login = new AppSession();
        $this->assertTrue($login->login('ghost', 'GhostPass12345')->contains('Invalid username or password.'));
        $s = TestServer::pdo()->prepare('SELECT COUNT(*) FROM users WHERE username=?');
        $s->execute(['ghost']);
        $this->assertSame(0, (int)$s->fetchColumn());
    }

    public function test_deleting_a_user_keeps_their_tasks_comments_and_history_and_marks_them_deleted(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'contrib', 'Contributor', 'ContribPass12345');
        $contribId = $this->userId('contrib');

        $contrib = new AppSession('contrib', 'ContribPass12345');
        $taskId = $contrib->createTask('Contrib created this task', ['assignees' => [$contribId]]);
        $contrib->post('/?page=task&id=' . $taskId, [
            'action' => 'comment',
            'task_id' => (string)$taskId,
            'body' => 'A comment that must survive the deletion.',
        ]);

        $response = $admin->post('/?page=users', [
            'action' => 'delete_user',
            'id' => (string)$contribId,
        ]);
        $this->assertSame(302, $response->status);

        $page = $admin->taskPage($taskId);
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Contrib created this task'));
        $this->assertTrue($page->contains('Deleted user'));
        $this->assertTrue($page->contains('A comment that must survive the deletion.'));
        $this->assertTrue($page->contains('Unassigned'));

        // The database really cleared the ownership/authorship but kept the rows.
        // Every statement is closed right away: an open SQLite statement holds a
        // shared lock that would block the server-side DELETE below.
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM users WHERE username=\'contrib\'')->fetchColumn());
        $s = TestServer::pdo()->prepare('SELECT created_by FROM tasks WHERE id=?');
        $s->execute([$taskId]);
        $this->assertNull($s->fetchColumn());
        $s->closeCursor();
        $s = TestServer::pdo()->prepare('SELECT COUNT(*) FROM comments WHERE task_id=? AND user_id IS NULL');
        $s->execute([$taskId]);
        $this->assertSame(1, (int)$s->fetchColumn());
        $s->closeCursor();
        $s = TestServer::pdo()->prepare('SELECT COUNT(*) FROM task_assignees WHERE task_id=?');
        $s->execute([$taskId]);
        $this->assertSame(0, (int)$s->fetchColumn());
        $s->closeCursor();

        // The orphaned task can still be administered by an administrator.
        // Archive-first flow (#12): archive it, then delete it permanently.
        $archiveTask = $admin->post('/?page=task&id=' . $taskId, ['action' => 'archive_task', 'id' => (string)$taskId]);
        $this->assertSame(302, $archiveTask->status);
        $deleteTask = $admin->post('/?page=task&id=' . $taskId, ['action' => 'delete_task', 'id' => (string)$taskId, 'return' => '?page=archive']);
        $this->assertSame(302, $deleteTask->status);
    }

    public function test_deleting_a_user_who_created_a_project_keeps_the_project(): void
    {
        // Integration with the projects CRUD (#9): projects.created_by is
        // nullable with ON DELETE SET NULL, so a user deletion cannot fail on
        // the projects foreign key and the project survives as an orphan.
        $admin = $this->adminSession();
        $this->createUser($admin, 'pm', 'Pm User', 'PmUserPass1234');
        $pmId = $this->userId('pm');
        $pm = new AppSession('pm', 'PmUserPass1234');

        $pm->get('/?page=project_new');
        $response = $pm->post('/?page=project_new', [
            'action' => 'save_project',
            'id' => '0',
            'name' => 'Pm owns this project',
            'description' => 'Project that must survive its creator.',
            'status_id' => '1',
            'assignees' => [],
        ]);
        $this->assertSame(302, $response->status);
        $s = TestServer::pdo()->prepare('SELECT id FROM projects WHERE name=?');
        $s->execute(['Pm owns this project']);
        $projectId = (int)$s->fetchColumn();
        $this->assertNotFalse($projectId);
        $s->closeCursor(); // an open statement holds a shared lock that would block the DELETE below

        $delete = $admin->post('/?page=users', ['action' => 'delete_user', 'id' => (string)$pmId]);
        $this->assertSame(302, $delete->status, 'User deletion must not fail on the projects foreign key.');

        $s = TestServer::pdo()->prepare('SELECT created_by FROM projects WHERE id=?');
        $s->execute([$projectId]);
        $this->assertNull($s->fetchColumn(), 'The project creator reference should be cleared.');
        $s->closeCursor();

        $page = $admin->get('/?page=project&id=' . $projectId);
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Pm owns this project'));
        $this->assertTrue($page->contains('Deleted user'));
    }

    public function test_non_admins_cannot_open_the_user_edit_page(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'alice', 'Alice User', 'AlicePass12345');
        $alice = new AppSession('alice', 'AlicePass12345');

        $page = $alice->get('/?page=user&id=' . $this->userId('admin'));
        $this->assertSame(200, $page->status);
        $this->assertFalse($page->contains('Edit user'));
        $this->assertTrue($page->contains('Tasks')); // falls back to the task list
    }
}
