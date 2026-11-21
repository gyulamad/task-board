<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\TestServer;

final class AdminPagesTest extends E2eTestCase
{
    public function test_administration_page_lists_users_and_statuses(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=admin');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Create user'));
        $this->assertTrue($page->contains('Task statuses'));
        $this->assertTrue($page->contains('Administrator'));
        foreach (['TODO', 'IN PROGRESS', 'DONE'] as $status) {
            $this->assertTrue($page->contains($status));
        }
    }

    public function test_admin_can_add_a_status(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=admin', ['action' => 'add_status', 'name' => 'BLOCKED']);
        $this->assertSame(302, $response->status);

        $page = $admin->get('/?page=admin');
        $this->assertTrue($page->contains('BLOCKED'));
        $this->assertTrue($page->contains('Status added.'));

        $newTaskPage = $admin->get('/?page=new');
        $this->assertTrue($newTaskPage->contains('BLOCKED'));
    }

    public function test_status_treated_as_done_is_marked_in_the_list(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=admin', ['action' => 'add_status', 'name' => 'SHIPPED', 'is_done' => '1']);
        $page = $admin->get('/?page=admin');
        $this->assertTrue($page->contains('SHIPPED'));
        $this->assertTrue($page->contains('Completion status'));
    }

    public function test_empty_status_names_are_rejected(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=admin', ['action' => 'add_status', 'name' => '  ']);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Status name is required.'));
    }

    public function test_admin_can_create_users_who_can_then_log_in(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'dave',
            'display_name' => 'Dave User',
            'password' => 'DavePass12345',
            'role' => 'user',
        ]);
        $this->assertSame(302, $response->status);
        $this->assertTrue($admin->get('/?page=admin')->contains('Dave User'));

        $dave = new \TaskBoard\Tests\Support\AppSession('dave', 'DavePass12345');
        $tasks = $dave->get('/?');
        $this->assertSame(200, $tasks->status);
        $this->assertTrue($tasks->contains('Dave User'));

        $createdTask = $dave->createTask('Dave creates a task');
        $this->assertTrue($dave->taskPage($createdTask)->contains('Dave creates a task'));
    }

    public function test_user_creation_validates_password_length(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'eve',
            'display_name' => 'Eve User',
            'password' => 'short',
            'role' => 'user',
        ]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Enter username, display name and a password of at least 10 characters.'));
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }
}
