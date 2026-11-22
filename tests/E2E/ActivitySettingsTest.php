<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use PDO;
use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #34: the activity status periods settings page. An administrator can
 * configure which age period renders as which colour or character next to
 * user names; the choice takes effect on the board immediately, invalid
 * saves are refused, and resetting restores the built-in traffic light.
 */
final class ActivitySettingsTest extends E2eTestCase
{
    /** Ages a user's stamp on the database's own clock, like UserActivityTest does. */
    private function backdateMinutes(string $username, int $minutes): void
    {
        $pdo = TestServer::pdo();
        $id = $this->userId($username);
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $sql = $isMysql
            ? 'UPDATE users SET last_seen_at = NOW() - INTERVAL ' . $minutes . ' MINUTE WHERE id = ?'
            : "UPDATE users SET last_seen_at = datetime('now', '-" . $minutes . " minutes') WHERE id = ?";
        $pdo->prepare($sql)->execute([$id]);
    }

    private function userId(string $username): int
    {
        $s = TestServer::pdo()->prepare('SELECT id FROM users WHERE username=?');
        $s->execute([$username]);
        $id = $s->fetchColumn();
        $this->assertNotFalse($id, 'User ' . $username . ' does not exist.');
        return (int)$id;
    }

    /** The activity levels row as raw JSON, or null when the built-ins are in effect. */
    private function storedLevels(): ?string
    {
        $s = TestServer::pdo()->prepare('SELECT value FROM app_settings WHERE name=?');
        $s->execute(['activity_levels']);
        $v = $s->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    /** Posts a period list in the exact shape the settings form submits. */
    private function saveLevels(AppSession $session, array $rows): \TaskBoard\Tests\Support\HttpResponse
    {
        return $session->post('/?page=activity_settings', [
            'action' => 'save_activity_levels',
            'levels' => $rows,
        ]);
    }

    public function test_the_settings_page_is_admin_only(): void
    {
        $this->adminSession();
        $regular = $this->regularUserSession();
        $this->assertSame(403, $regular->get('/?page=activity_settings')->status);
        $this->assertSame(302, $this->session->get('/?page=activity_settings')->status, 'an anonymous visitor is redirected to the login page');
        $this->assertSame(200, $this->adminSession()->get('/?page=activity_settings')->status);
    }

    public function test_the_page_shows_the_current_legend_and_the_form(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=activity_settings');
        $this->assertSame(200, $page->status);
        // the built-in periods render with their #30 wording
        $this->assertStringContainsString('Active in the last 15 minutes', $page->body);
        $this->assertStringContainsString('Inactive for over an hour', $page->body);
        $this->assertStringContainsString('name="action" value="save_activity_levels"', $page->body);
        $this->assertStringContainsString('name="action" value="reset_activity_levels"', $page->body);
        // and the admin page links to it, so it is discoverable
        $adminPage = $admin->get('/?page=admin');
        $this->assertSame(200, $adminPage->status);
        $this->assertStringContainsString('?page=activity_settings', $adminPage->body);
    }

    public function test_saving_periods_stores_them_and_renders_them_on_the_board(): void
    {
        $admin = $this->adminSession();

        // green up to 30 min, yellow up to 2 h, red up to a day, then the empty grey
        $response = $this->saveLevels($admin, [
            'minutes' => ['30', '120', '1440', '', ''],
            'style' => ['color:green', 'color:yellow', 'color:red', 'color:grey', ''],
            'character' => ['', '', '', '', ''],
        ]);
        $this->assertSame(302, $response->status, 'the save redirects (post/redirect/get)');

        $stored = $this->storedLevels();
        $this->assertNotNull($stored, 'the periods must be stored in app_settings');
        $this->assertSame('grey', json_decode((string)$stored, true)[3]['color'], 'the fourth period is the empty grey');

        // the settings page now shows the generated wording and the legend marks
        $page = $admin->get('/?page=activity_settings');
        $this->assertStringContainsString('Seen within the last 30 minutes', $page->body);
        $this->assertStringContainsString('tb-activity-grey', $page->body);

        // the admin's own request keeps their dot fresh (tb_touch_last_seen),
        // so the aged periods are asserted on another user: the worker below,
        // rendered in the admin page's users table
        $this->regularUserSession();
        $this->backdateMinutes('worker', 180);
        $adminPage = $admin->get('/?page=admin');
        $this->assertSame(200, $adminPage->status);
        $this->assertStringContainsString('tb-activity-red', $adminPage->body);
        $this->backdateMinutes('worker', 2 * 1440);
        $adminPage = $admin->get('/?page=admin');
        $this->assertStringContainsString('tb-activity-grey', $adminPage->body);
    }

    public function test_a_character_period_renders_its_glyph_on_the_board(): void
    {
        $admin = $this->adminSession();
        $worker = $this->regularUserSession();
        $response = $this->saveLevels($admin, [
            'minutes' => ['15', ''],
            'style' => ['color:green', 'character'],
            'character' => ['', '·'],
        ]);
        $this->assertSame(302, $response->status);

        // the admin's own dot is always fresh, so the glyph is asserted on the
        // aged worker in the admin page's users table
        $this->backdateMinutes('worker', 90);
        $adminPage = $admin->get('/?page=admin');
        $this->assertSame(200, $adminPage->status);
        $this->assertStringContainsString('tb-activity-char', $adminPage->body);
        $this->assertStringContainsString('>·</span>', $adminPage->body, 'the glyph is rendered escaped');
    }

    public function test_an_invalid_save_is_refused_and_keeps_the_old_periods(): void
    {
        $admin = $this->adminSession();
        $good = $this->saveLevels($admin, [
            'minutes' => ['30', ''],
            'style' => ['color:green', 'color:red'],
            'character' => ['', ''],
        ]);
        $this->assertSame(302, $good->status);
        $this->assertNotNull($this->storedLevels());

        // backwards bounds must be refused
        $bad = $this->saveLevels($admin, [
            'minutes' => ['120', '30', ''],
            'style' => ['color:green', 'color:yellow', 'color:red'],
            'character' => ['', '', ''],
        ]);
        $this->assertSame(200, $bad->status, 'an invalid save re-renders the page with the error');
        $this->assertStringContainsString('the bounds have to increase', $bad->body);

        // the stored list is untouched
        $stored = json_decode((string)$this->storedLevels(), true);
        $this->assertSame(30, $stored[0]['until_minutes'], 'the refused save must not change the stored periods');
    }

    public function test_resetting_restores_the_built_in_traffic_light(): void
    {
        $admin = $this->adminSession();
        $this->saveLevels($admin, [
            'minutes' => ['30', ''],
            'style' => ['color:grey', 'color:red'],
            'character' => ['', ''],
        ]);
        $this->assertNotNull($this->storedLevels());

        $reset = $admin->post('/?page=activity_settings', ['action' => 'reset_activity_levels']);
        $this->assertSame(302, $reset->status);
        $this->assertNull($this->storedLevels(), 'resetting removes the stored list');

        $page = $admin->get('/?page=activity_settings');
        $this->assertStringContainsString('Active in the last 15 minutes', $page->body, 'the built-in wording is back');
    }

    public function test_regular_users_cannot_save_or_reset_periods(): void
    {
        $this->adminSession();
        $regular = $this->regularUserSession();

        $save = $this->saveLevels($regular, [
            'minutes' => ['30', ''],
            'style' => ['color:green', 'color:red'],
            'character' => ['', ''],
        ]);
        $this->assertSame(403, $save->status);
        $reset = $regular->post('/?page=activity_settings', ['action' => 'reset_activity_levels']);
        $this->assertSame(403, $reset->status);
        $this->assertNull($this->storedLevels(), 'nothing may have been stored by the refused requests');
    }

    /** A non-admin account for the permission tests. */
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
}
