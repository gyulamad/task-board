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

    public function test_the_settings_page_shows_the_under_development_notice(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=settings');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('under development'), 'The page must show the under-development notice.');
        $this->assertTrue($page->contains('Settings'), 'The page must carry its title.');
        $this->assertTrue($page->contains('personal preferences'), 'The page must explain what will live here.');
    }

    public function test_anonymous_users_are_pushed_to_sign_in(): void
    {
        $anonymous = new AppSession();
        $this->assertSame(302, $anonymous->get('/?page=settings')->status);
    }
}
