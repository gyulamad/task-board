<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\TestServer;

final class AccessControlTest extends E2eTestCase
{
    public function test_post_requests_without_a_valid_csrf_token_are_rejected(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('CSRF target');

        $noToken = $admin->postWithoutCsrf('/?page=task&id=' . $taskId, [
            'action' => 'comment',
            'task_id' => (string)$taskId,
            'body' => 'no token',
        ]);
        $this->assertSame(403, $noToken->status);
        $this->assertTrue($noToken->contains('Invalid CSRF token.'));

        $badToken = $admin->postWithCsrf('/?page=task&id=' . $taskId, [
            'action' => 'comment',
            'task_id' => (string)$taskId,
            'body' => 'bad token',
        ], str_repeat('0', 64));
        $this->assertSame(403, $badToken->status);
        $this->assertTrue($badToken->contains('Invalid CSRF token.'));

        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM comments')->fetchColumn());
    }

    public function test_regular_users_cannot_open_administration_or_software_updates(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'alice',
            'display_name' => 'Alice User',
            'password' => 'AlicePass12345',
            'role' => 'user',
        ]);
        $alice = new \TaskBoard\Tests\Support\AppSession('alice', 'AlicePass12345');

        $adminPage = $alice->get('/?page=admin');
        $this->assertSame(200, $adminPage->status);
        $this->assertFalse($adminPage->contains('Create user'));
        $this->assertTrue($adminPage->contains('Tasks')); // falls back to the task list

        $updates = $alice->get('/?page=updates');
        $this->assertSame(403, $updates->status);
        $this->assertTrue($updates->contains('Forbidden.'));
    }

    public function test_regular_users_cannot_create_users_or_statuses_via_post(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'bob',
            'display_name' => 'Bob User',
            'password' => 'BobPass123456',
            'role' => 'user',
        ]);

        $bob = new \TaskBoard\Tests\Support\AppSession('bob', 'BobPass123456');
        $bob->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'evil',
            'display_name' => 'Evil',
            'password' => 'EvilPass123456',
            'role' => 'admin',
        ]);
        $bob->post('/?page=admin', [
            'action' => 'add_status',
            'name' => 'PWNED',
        ]);

        $users = (int)TestServer::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $this->assertSame(2, $users); // admin + bob only
        $statuses = TestServer::pdo()->query('SELECT COUNT(*) FROM statuses WHERE name=\'PWNED\'')->fetchColumn();
        $this->assertSame('0', (string)$statuses);
    }

    public function test_task_page_returns_404_for_unknown_tasks(): void
    {
        $admin = $this->adminSession();
        $response = $admin->get('/?page=task&id=9999');
        $this->assertSame(404, $response->status);
        $this->assertTrue($response->contains('Task not found.'));
    }
}
