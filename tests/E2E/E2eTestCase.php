<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Base class for end-to-end tests: every test gets a freshly created
 * database (schema only, no users) and a fresh anonymous session.
 */
abstract class E2eTestCase extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        TestServer::ensureRunning();
        TestServer::resetDatabase();
        $this->session = new AppSession();
        $this->admin = null;
    }

    protected function tearDown(): void
    {
        unset($this->session, $this->admin);
        parent::tearDown();
    }

    /** Creates the first admin through the real install flow and logs in as them. */
    protected function adminSession(): AppSession
    {
        if ($this->admin === null) {
            $installer = new AppSession();
            $response = $installer->installAdmin('admin', 'AdminPass1234', 'Administrator');
            $this->assertSame(302, $response->status);
            $this->admin = new AppSession('admin', 'AdminPass1234');
            // Reuse the installer session as the admin session is fine too, but a
            // fresh login also covers the login flow on every test that needs admin.
        }
        return $this->admin;
    }

    protected AppSession $session;
    protected ?AppSession $admin = null;
}
