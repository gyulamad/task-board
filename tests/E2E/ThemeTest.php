<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * End-to-end coverage for the per-user theme setting (Task #11):
 * the board ships two themes - light (the original look) and dark - and
 * every signed-in user can pick a preference that persists in the database.
 *
 * Assertions target the exact <html ...> opening tag (not bare substrings,
 * because the dark-mode CSS selectors also contain 'data-bs-theme="dark"').
 */
final class ThemeTest extends E2eTestCase
{
    private const HTML_LIGHT = '<html lang="en" data-bs-theme="light">';
    private const HTML_DARK = '<html lang="en" data-bs-theme="dark">';

    public function test_pages_render_the_light_theme_by_default(): void
    {
        $page = $this->session->get('/?page=login');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains(self::HTML_LIGHT), 'Anonymous visitors should get the light theme.');
        $this->assertFalse($page->contains(self::HTML_DARK));
    }

    public function test_the_theme_switcher_is_only_available_to_signed_in_users(): void
    {
        $anonymous = $this->session->get('/?page=login');
        $this->assertFalse($anonymous->contains('name="theme"'), 'The switcher must not be rendered for anonymous visitors.');

        $admin = $this->adminSession();
        $page = $admin->get('/?');
        $this->assertTrue($page->contains('name="theme"'), 'The switcher should be rendered in the navbar.');
        $this->assertTrue($page->contains('<option value="light" selected>'), 'The current theme should be preselected.');
        $this->assertTrue($page->contains('<option value="dark"'), 'Both themes should be offered.');
    }

    public function test_a_user_can_switch_to_the_dark_theme_and_it_persists(): void
    {
        $admin = $this->adminSession();
        $this->assertTrue($admin->get('/?')->contains(self::HTML_LIGHT));

        $response = $admin->post('/?', ['action' => 'save_theme', 'theme' => 'dark']);
        $this->assertSame(302, $response->status, 'Saving the theme should redirect back.');

        $page = $admin->get('/?');
        $this->assertTrue($page->contains(self::HTML_DARK), 'The dark theme should be active right after saving.');
        $this->assertTrue($page->contains('Theme saved.'), 'A confirmation flash message should be shown.');
        $this->assertTrue($page->contains('<option value="dark" selected>'), 'The switcher should reflect the stored choice.');

        // The preference is stored per user, so it survives a fresh sign-in.
        $admin->logout();
        $fresh = new AppSession('admin', 'AdminPass1234');
        $this->assertTrue($fresh->get('/?')->contains(self::HTML_DARK), 'The theme must persist across sessions.');

        // ...and it is visible on the other pages too (task pages, admin page).
        $taskId = $fresh->createTask('Theme check task');
        $this->assertTrue($fresh->taskPage($taskId)->contains(self::HTML_DARK));
        $this->assertTrue($fresh->get('/?page=admin')->contains(self::HTML_DARK));
    }

    public function test_a_user_can_switch_back_to_the_light_theme(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?', ['action' => 'save_theme', 'theme' => 'dark']);
        $this->assertTrue($admin->get('/?')->contains(self::HTML_DARK));

        $admin->post('/?', ['action' => 'save_theme', 'theme' => 'light']);
        $page = $admin->get('/?');
        $this->assertTrue($page->contains(self::HTML_LIGHT));
        $this->assertFalse($page->contains(self::HTML_DARK));
    }

    public function test_the_theme_selection_is_per_user(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?', ['action' => 'save_theme', 'theme' => 'dark']);
        $this->assertTrue($admin->get('/?')->contains(self::HTML_DARK));

        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'jules',
            'display_name' => 'Jules',
            'password' => 'JulesPassword123',
            'role' => 'user',
        ]);
        $jules = new AppSession('jules', 'JulesPassword123');
        $this->assertTrue($jules->get('/?')->contains(self::HTML_LIGHT), 'A new user starts with the light theme regardless of other users.');
    }

    public function test_invalid_theme_values_are_rejected(): void
    {
        $admin = $this->adminSession();
        foreach (['blue', 'DARK', 'dark ', "<script>x</script>", ''] as $invalid) {
            $response = $admin->post('/?', ['action' => 'save_theme', 'theme' => $invalid]);
            $this->assertSame(200, $response->status, 'Rejections render the page with the error.');
            $this->assertTrue($response->contains('Invalid theme selection.'), "Value '{$invalid}' must be rejected.");
        }
        $this->assertTrue($admin->get('/?')->contains(self::HTML_LIGHT), 'The stored theme is unchanged after rejected submissions.');
    }

    public function test_anonymous_users_cannot_change_the_theme(): void
    {
        $response = $this->session->post('/?', ['action' => 'save_theme', 'theme' => 'dark']);
        $this->assertSame(302, $response->status, 'Anonymous posts fall through to the login redirect.');
        $this->assertSame('?page=login', (string)$response->header('location'));
    }

    public function test_boards_installed_before_the_theme_feature_are_upgraded_in_place(): void
    {
        $admin = $this->adminSession();
        $pdo = TestServer::pdo();

        // Simulate a board created before themes existed: drop the column.
        $pdo->exec('ALTER TABLE users DROP COLUMN theme');

        // The application must repair the schema on the next request instead of failing.
        $page = $admin->get('/?');
        $this->assertSame(200, $page->status, 'The board should render after the in-place upgrade.');
        $this->assertTrue($page->contains(self::HTML_LIGHT), 'Existing users get the default light theme.');

        // And the feature works normally afterwards.
        $admin->post('/?', ['action' => 'save_theme', 'theme' => 'dark']);
        $this->assertTrue($admin->get('/?')->contains(self::HTML_DARK));
    }
}
