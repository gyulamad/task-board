<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

/**
 * Task #32: mobile friendly navigation.
 *
 * On small screens the whole menu (links, theme switcher, sign-out) lives in a
 * Bootstrap collapse container that drops down only when the hamburger toggler
 * is pressed; from the lg breakpoint up the bar stays expanded exactly as
 * before. The toggle itself is powered by the bootstrap.bundle.min.js script
 * that render_footer() already loads, so the tests pin the markup contract:
 * toggler wired to the collapsible container, every entry inside it, and no
 * toggler at all for anonymous visitors (nothing to collapse).
 */
final class NavbarTest extends E2eTestCase
{
    public function test_signed_in_pages_render_a_hamburger_toggler_and_a_collapsible_menu(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=tasks');
        $this->assertSame(200, $page->status);

        // The toggler: a real button wired to the collapsible container.
        $this->assertTrue($page->contains('<button class="navbar-toggler ms-auto" type="button"'), 'The hamburger button must be rendered.');
        $this->assertTrue($page->contains('data-bs-toggle="collapse"'));
        $this->assertTrue($page->contains('data-bs-target="#tb-navbar"'));
        $this->assertTrue($page->contains('aria-controls="tb-navbar"'));
        $this->assertTrue($page->contains('aria-label="Toggle navigation"'));
        $this->assertTrue($page->contains('<span class="navbar-toggler-icon"></span>'));

        // The whole menu lives inside the collapsible container.
        $this->assertTrue($page->contains('<div class="collapse navbar-collapse" id="tb-navbar">'));
        $this->assertTrue($page->contains('href="?page=tasks-v2">Tasks</a>'));  // #57 v2: the menu entry opens the new canonical task list page.
        $this->assertTrue($page->contains('href="?page=projects">Projects</a>'));
        $this->assertTrue($page->contains('href="?page=templates">Templates</a>'));

        // From lg up the bar must keep its always-expanded behaviour.
        $this->assertTrue($page->contains('navbar navbar-expand-lg navbar-dark bg-dark'));

        // The toggler is inert without the bundle script; render_footer loads it.
        $this->assertTrue($page->contains('bootstrap.bundle.min.js'));
    }

    public function test_admin_entries_stay_in_the_menu(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/');
        $this->assertTrue($page->contains('href="?page=admin">Administration</a>'));
        $this->assertTrue($page->contains('href="?page=updates">Software updates</a>'));
    }

    public function test_theme_switcher_and_sign_out_stay_in_the_menu(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/');
        $this->assertTrue($page->contains('name="theme"'), 'The theme switcher must stay reachable in the menu.');
        $this->assertTrue($page->contains('action="?page=logout"'), 'Sign out must stay reachable in the menu.');
        $this->assertTrue($page->contains('navbar-text mx-2'), 'The signed-in user name stays in the menu.');
    }

    public function test_anonymous_pages_render_no_toggler(): void
    {
        $page = $this->session->get('/?page=login');
        $this->assertSame(200, $page->status);
        $this->assertFalse($page->contains('navbar-toggler'), 'Without a signed-in user there is nothing to collapse.');
        $this->assertFalse($page->contains('tb-navbar'));
        $this->assertTrue($page->contains('navbar navbar-expand-lg navbar-dark bg-dark'), 'The bar itself still renders for the brand.');
    }
}
