<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #42: the single Administration page is split into a compact hub
 * (?page=admin) plus separate Users (?page=users) and Task statuses
 * (?page=statuses) pages. The hub links to every administration area and
 * keeps the uploads maintenance action.
 */
final class AdminPagesTest extends E2eTestCase
{
    public function test_administration_hub_links_to_the_separated_pages(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=admin');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Administration'));
        $this->assertTrue($page->contains('href="?page=users"'), 'The hub must link to the Users page.');
        $this->assertTrue($page->contains('href="?page=statuses"'), 'The hub must link to the Task statuses page.');
        $this->assertTrue($page->contains('href="?page=activity_settings"'), 'The hub must still link to the activity periods.');
        $this->assertTrue($page->contains('href="?page=updates"'), 'The hub must still link to the software updates.');
        // The maintenance action stays reachable on the hub.
        $this->assertTrue($page->contains('Clean up orphaned attachment files'));
    }

    public function test_users_page_shows_the_user_management_ui(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=users');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Create user'));
        $this->assertTrue($page->contains('Administrator'));
        $this->assertTrue($page->contains('Last active'));
        $this->assertTrue($page->contains('href="?page=admin"'), 'The page must link back to the hub.');
    }

    public function test_statuses_page_shows_every_status_with_a_colour_picker(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=statuses');
        $this->assertSame(200, $page->status);
        foreach (['TODO', 'IN PROGRESS', 'DONE'] as $status) {
            $this->assertTrue($page->contains($status));
        }
        $this->assertTrue($page->contains('type="color"'), 'Every status row carries a colour picker.');
        $this->assertTrue($page->contains('action" value="edit_status"'));
        $this->assertTrue($page->contains('href="?page=admin"'), 'The page must link back to the hub.');
    }

    public function test_admin_can_add_a_status(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=statuses', ['action' => 'add_status', 'name' => 'BLOCKED']);
        $this->assertSame(302, $response->status);

        $page = $admin->get('/?page=statuses');
        $this->assertTrue($page->contains('BLOCKED'));
        $this->assertTrue($page->contains('Status added.'));

        $newTaskPage = $admin->get('/?page=new');
        $this->assertTrue($newTaskPage->contains('BLOCKED'));
    }

    public function test_status_treated_as_done_is_marked_in_the_list(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=statuses', ['action' => 'add_status', 'name' => 'SHIPPED', 'is_done' => '1']);
        $page = $admin->get('/?page=statuses');
        $this->assertTrue($page->contains('SHIPPED'));
        $this->assertTrue($page->contains('Completion status'));
    }

    public function test_empty_status_names_are_rejected(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=statuses', ['action' => 'add_status', 'name' => '  ']);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Status name is required.'));
    }

    public function test_admin_can_create_users_who_can_then_log_in(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=users', [
            'action' => 'add_user',
            'username' => 'dave',
            'display_name' => 'Dave User',
            'password' => 'DavePass12345',
            'role' => 'user',
        ]);
        $this->assertSame(302, $response->status);
        $this->assertStringContainsString('?page=users', (string)$response->header('location'), 'User actions redirect back to the Users page.');
        $this->assertTrue($admin->get('/?page=users')->contains('Dave User'));

        $dave = new AppSession('dave', 'DavePass12345');
        $tasks = $dave->get('/?');
        $this->assertSame(200, $tasks->status);
        $this->assertTrue($tasks->contains('Dave User'));

        $createdTask = $dave->createTask('Dave creates a task');
        $this->assertTrue($dave->taskPage($createdTask)->contains('Dave creates a task'));
    }

    public function test_user_creation_validates_password_length(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=users', [
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
