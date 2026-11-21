<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

final class AuthFlowTest extends E2eTestCase
{
    public function test_anonymous_users_are_redirected_to_the_login_page(): void
    {
        foreach (['/?', '/?page=task&id=1', '/?page=new', '/?page=admin'] as $path) {
            $response = $this->session->get($path);
            $this->assertSame(302, $response->status, $path);
            $this->assertSame('?page=login', (string)$response->header('location'), $path);
        }
    }

    public function test_login_rejects_unknown_users_and_wrong_passwords(): void
    {
        $this->adminSession();
        $anonymous = new \TaskBoard\Tests\Support\AppSession();

        $unknown = $anonymous->post('/?page=login&i=1', ['username' => 'nobody', 'password' => 'Whatever12345']);
        $this->assertSame(200, $unknown->status);
        $this->assertTrue($unknown->contains('Invalid username or password.'));

        $wrongPassword = $anonymous->post('/?page=login&i=1', ['username' => 'admin', 'password' => 'WrongPassword123']);
        $this->assertSame(200, $wrongPassword->status);
        $this->assertTrue($wrongPassword->contains('Invalid username or password.'));
    }

    public function test_login_and_sign_out_round_trip(): void
    {
        $this->adminSession();
        $user = new \TaskBoard\Tests\Support\AppSession('admin', 'AdminPass1234');

        $tasks = $user->get('/?');
        $this->assertSame(200, $tasks->status);
        $this->assertTrue($tasks->contains('Sign out'));

        $signOut = $user->get('/?page=logout');
        $this->assertSame(302, $signOut->status);
        $this->assertSame('?page=login', (string)$signOut->header('location'));

        $afterLogout = $user->get('/?');
        $this->assertSame(302, $afterLogout->status);
        $this->assertSame('?page=login', (string)$afterLogout->header('location'));
    }

    public function test_sign_in_page_renders_the_expected_form(): void
    {
        $page = $this->session->get('/?page=login');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('name="username"'));
        $this->assertTrue($page->contains('name="password"'));
        $this->assertTrue($page->contains('Sign in'));
    }
}
