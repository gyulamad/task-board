<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
/**
 * Task #55 - the personal Settings page.
 *
 * The page is deliberately minimal ("under development"): it exists as the
 * base the future preference screens will grow into. These tests pin the
 * navbar entry, the notice text and the sign-in requirement.
 */
final class SettingsPageTest extends E2eTestCase
{
    public function test_the_navbar_links_to_settings(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?');
        $this->assertTrue($page->contains('href="?page=settings">Settings</a>'), 'The navbar must carry the Settings link.');
    }

    public function test_the_settings_page_carries_the_timezone_preference(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=settings');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Settings'), 'The page must carry its title.');
        $this->assertTrue($page->contains('Personal preferences'), 'The page must explain what lives here.');
        // #108: the timezone select renders the IANA list and defaults to UTC.
        $this->assertTrue($page->contains('id="timezone-select"'), 'The timezone select renders.');
        $this->assertTrue($page->contains('<option value="UTC" selected>UTC</option>'), 'The default zone is UTC (the storage zone).');
        $this->assertTrue($page->contains('value="Europe/Budapest"'), 'The IANA zone list renders.');
    }

    public function test_anonymous_users_are_pushed_to_sign_in(): void
    {
        $anonymous = new AppSession();
        $this->assertSame(302, $anonymous->get('/?page=settings')->status);
    }
}
