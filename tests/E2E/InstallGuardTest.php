<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\TestServer;

final class InstallGuardTest extends E2eTestCase
{
    public function test_install_page_is_available_while_no_users_exist(): void
    {
        $page = $this->session->get('/?page=install');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Create administrator'));
        $this->assertTrue($page->contains('name="username"'));
    }

    public function test_install_rejects_short_passwords(): void
    {
        $response = $this->session->post('/?page=install', [
            'username' => 'admin',
            'display_name' => 'Administrator',
            'password' => 'short',
        ]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Use a password with at least 10 characters.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_install_creates_the_first_administrator(): void
    {
        $response = $this->session->post('/?page=install', [
            'username' => 'admin',
            'display_name' => 'Administrator',
            'password' => 'AdminPass1234',
        ]);
        $this->assertSame(302, $response->status);
        $this->assertSame('?page=login', (string)$response->header('location'));

        $loginPage = $this->session->get('/?page=login');
        $this->assertTrue($loginPage->contains('Administrator created. Please sign in.'));

        $login = $this->session->post('/?page=login&i=1', ['username' => 'admin', 'password' => 'AdminPass1234']);
        $this->assertSame(302, $login->status);
        $tasksPage = $this->session->get('/?');
        $this->assertTrue($tasksPage->contains('Administrator'));
    }

    public function test_install_returns_404_for_get_and_post_after_setup(): void
    {
        $this->adminSession();
        $get = $this->session->get('/?page=install');
        $this->assertSame(404, $get->status);
        $this->assertTrue($get->contains('Not found.'));

        $post = $this->session->post('/?page=install', [
            'username' => 'hacker',
            'display_name' => 'Hacker',
            'password' => 'LongEnoughPassword123',
        ]);
        $this->assertSame(404, $post->status);
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function test_login_page_links_to_installer_only_when_empty(): void
    {
        $empty = $this->session->get('/?page=login');
        $this->assertTrue($empty->contains('Create the administrator account'));

        $this->adminSession();
        $afterSetup = $this->session->get('/?page=login');
        $this->assertFalse($afterSetup->contains('Create the administrator account'));
    }
}
