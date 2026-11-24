<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #112: the filled toggle and the colour selection for the activity
 * status indicators, end to end through the real settings form. The stored
 * configuration carries the per-period 'filled' key; the board renders the
 * solid dot or the hollow circle per the pick, and legacy defaults keep the
 * exact pre-#112 shapes.
 */
final class ActivityFilledSettingsTest extends E2eTestCase
{
    private function userId(string $username): int
    {
        $s = TestServer::pdo()->prepare('SELECT id FROM users WHERE username=?');
        $s->execute([$username]);
        $id = $s->fetchColumn();
        $this->assertNotFalse($id, 'User ' . $username . ' does not exist.');
        return (int)$id;
    }

    /** Ages the stamp by writing an epoch second directly (#108 discipline). */
    private function backdateMinutes(string $username, int $minutes): void
    {
        TestServer::pdo()->prepare('UPDATE users SET last_seen_at = ? WHERE id = ?')
            ->execute([time() - $minutes * 60, $this->userId($username)]);
    }

    /** Creates the worker account through the real admin form and logs in as them. */
    private function regularUserSession(): AppSession
    {
        $admin = $this->adminSession();
        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'worker',
            'display_name' => 'Worker',
            'password' => 'WorkerPass1234',
            'role' => 'user',
        ]);
        return new AppSession('worker', 'WorkerPass1234');
    }

    private function storedLevels(): ?array
    {
        $s = TestServer::pdo()->prepare('SELECT value FROM app_settings WHERE name=?');
        $s->execute(['activity_levels']);
        $v = $s->fetchColumn();
        return $v === false ? null : json_decode((string)$v, true);
    }

    public function test_the_form_saves_the_filled_toggle_and_the_board_renders_it(): void
    {
        $admin = $this->adminSession();
        $this->regularUserSession();

        // Period 1: hollow green (unfilled), period 2: the solid grey dot
        // (filled) - the shape that was impossible before #112.
        $response = $this->admin->post('/?page=activity_settings', [
            'action' => 'save_activity_levels',
            'levels' => [
                'minutes' => ['30', '', '', '', ''],
                'style' => ['color:green', 'color:grey', '', '', ''],
                'character' => ['', '', '', '', ''],
                'filled' => ['0', '1', '0', '0', '0'],
            ],
        ]);
        $this->assertSame(302, $response->status, 'The save redirects.');

        $stored = $this->storedLevels();
        $this->assertNotNull($stored, 'The periods are stored.');
        $this->assertFalse($stored[0]['filled'], 'The unticked green period stores filled=false.');
        $this->assertTrue($stored[1]['filled'], 'The ticked grey period stores filled=true.');

        // The settings legend renders the picked shapes.
        $page = $this->admin->get('/?page=activity_settings');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('tb-activity-empty tb-activity-green'), 'The hollow green circle renders in the legend.');
        $this->assertTrue($page->contains('tb-activity-dot tb-activity-grey'), 'The solid grey dot renders in the legend.');
        // The form preselects the stored toggle.
        $this->assertTrue($page->contains('name="levels[filled][1]" value="0"'), 'The hidden half of the toggle posts per row.');
        $this->assertTrue($page->contains('id="lvl-filled-1" name="levels[filled][1]" checked'), 'The ticked grey period comes back checked.');
        $this->assertTrue($page->contains('id="lvl-filled-0" name="levels[filled][0]"'), 'The green period renders its checkbox unchecked.');

        // The board renders the shapes for real: a worker aged into the
        // second (last) period shows the solid grey dot on the users page.
        $this->backdateMinutes('worker', 60);
        $usersPage = $this->admin->get('/?page=users');
        $this->assertSame(200, $usersPage->status);
        $this->assertTrue($usersPage->contains('<span class="tb-activity-dot tb-activity-grey" title="Last seen longer ago than every period above"></span>'), 'The aged worker renders the solid grey dot.');
    }

    public function test_a_hollow_period_renders_the_glyph_on_the_board(): void
    {
        $admin = $this->adminSession();
        $this->regularUserSession();

        // One hollow yellow period for everything past 15 minutes.
        $response = $this->admin->post('/?page=activity_settings', [
            'action' => 'save_activity_levels',
            'levels' => [
                'minutes' => ['15', '', '', '', ''],
                'style' => ['color:green', 'color:yellow', '', '', ''],
                'character' => ['', '', '', '', ''],
                'filled' => ['1', '0', '0', '0', '0'],
            ],
        ]);
        $this->assertSame(302, $response->status);

        $this->backdateMinutes('worker', 60);
        $usersPage = $this->admin->get('/?page=users');
        $this->assertSame(200, $usersPage->status);
        $this->assertTrue($usersPage->contains('<span class="tb-activity-empty tb-activity-yellow" title="Last seen longer ago than every period above">&#9675;</span>'), 'The aged worker renders the hollow yellow circle.');
    }

    public function test_the_logged_out_indicator_round_trips_the_toggle(): void
    {
        $admin = $this->adminSession();
        $worker = $this->regularUserSession();

        // Solid grey dot for signed-out users (impossible before #112).
        $response = $this->admin->post('/?page=activity_settings', [
            'action' => 'save_logged_out_indicator',
            'style' => 'color:grey',
            'character' => '',
            'filled' => '1',
        ]);
        $this->assertSame(302, $response->status);
        $this->assertTrue($this->admin->get('/?page=activity_settings')->contains('id="lo-filled" name="filled" checked'), 'The form comes back with the toggle ticked.');

        $worker->logout();
        $page = $this->admin->get('/?page=users');
        $this->assertTrue($page->contains('<span class="tb-activity-dot tb-activity-grey" title="Signed out"></span>'), 'The signed-out worker renders the solid grey dot.');

        // Unticking falls back to the exact #106 hollow circle.
        $this->admin->post('/?page=activity_settings', [
            'action' => 'save_logged_out_indicator',
            'style' => 'color:grey',
            'character' => '',
            'filled' => '0',
        ]);
        $page = $this->admin->get('/?page=users');
        $this->assertTrue($page->contains('<span class="tb-activity-empty" title="Signed out">&#9675;</span>'), 'The unticked grey renders the exact #106 hollow circle.');
    }

    public function test_saving_without_the_filled_array_keeps_legacy_defaults(): void
    {
        $admin = $this->adminSession();

        // A save in the pre-#112 shape (no filled entries at all): the parser
        // stores no key and the renderer falls back to solid colours, hollow
        // grey - exactly the pre-#112 board.
        $response = $this->admin->post('/?page=activity_settings', [
            'action' => 'save_activity_levels',
            'levels' => [
                'minutes' => ['15', '', '', '', ''],
                'style' => ['color:green', 'color:grey', '', '', ''],
                'character' => ['', '', '', '', ''],
            ],
        ]);
        $this->assertSame(302, $response->status);

        $stored = $this->storedLevels();
        $this->assertNotNull($stored);
        $this->assertArrayNotHasKey('filled', $stored[0], 'No toggle posted: no key stored.');
        $page = $this->admin->get('/?page=activity_settings');
        $this->assertTrue($page->contains('<span class="tb-activity-dot tb-activity-green" title="Seen within the last 15 minutes"></span>'), 'The legacy green period renders the solid dot.');
        $this->assertTrue($page->contains('<span class="tb-activity-empty" title="Last seen longer ago than every period above">&#9675;</span>'), 'The legacy grey period renders the exact #106 hollow circle.');
    }

    public function test_resetting_restores_the_built_in_periods_and_shapes(): void
    {
        $admin = $this->adminSession();
        $this->admin->post('/?page=activity_settings', ['action' => 'reset_activity_levels']);
        $page = $this->admin->get('/?page=activity_settings');
        $this->assertTrue($page->contains('name="levels[filled][0]"'), 'The form carries the toggle after the reset.');
        $this->assertTrue($page->contains('id="lvl-filled-2" name="levels[filled][2]" checked'), 'The built-in red period comes back solid (ticked).');
    }
}
