<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\TestServer;

/**
 * Task #70 - the pack generator on the Software updates page: the section is
 * offered behind the admin password re-check, refuses an invalid base ref and
 * a wrong password. The full generation flow (diff + emit + round-trip
 * through the #69 engine) is pinned by PackGeneratorTest with an injected
 * base fetcher, so these page tests do not depend on GitHub.
 */
final class PackGeneratorTest extends E2eTestCase
{
    protected function tearDown(): void
    {
        if (is_file(TestServer::docRoot() . '/version.json')) @unlink(TestServer::docRoot() . '/version.json');
        parent::tearDown();
    }

    public function test_updates_page_offers_the_pack_generator(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=updates');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Generate an upgrade pack'), 'the page must present the generator section.');
        $this->assertTrue($page->contains('name="action" value="generate_pack"'), 'the generator form must POST the generate_pack action.');
        $this->assertTrue($page->contains('name="base_version"'), 'the generator must ask for the base version.');
    }

    public function test_generator_rejects_a_wrong_password(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=updates', [
            'action' => 'generate_pack',
            'base_version' => str_repeat('a', 40),
            'current_password' => 'NotTheAdminPassword1',
        ]);
        $this->assertTrue($response->contains('Your current administrator password was not accepted.'));
    }

    public function test_generator_rejects_an_invalid_base_ref(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=updates', [
            'action' => 'generate_pack',
            'base_version' => 'not ../a version',
            'current_password' => 'AdminPass1234',
        ]);
        $this->assertTrue($response->contains('must be a commit SHA or a release tag'), 'an invalid base ref must surface the validation error.');
    }

    public function test_generator_requires_a_valid_csrf_token(): void
    {
        $admin = $this->adminSession();
        $response = $admin->postWithoutCsrf('/?page=updates', ['action' => 'generate_pack', 'base_version' => 'v1.0.0']);
        $this->assertSame(403, $response->status);
        $this->assertTrue($response->contains('Invalid CSRF token.'));
    }

    public function test_generator_reports_a_missing_instance_hash(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=updates', [
            'action' => 'generate_pack',
            'base_version' => 'v1.0.0',
            'current_password' => 'AdminPass1234',
        ]);
        $this->assertTrue($response->contains('no recorded version hash'), 'without version.json the generator explains what is missing (no GitHub call needed for this).');
    }
}
