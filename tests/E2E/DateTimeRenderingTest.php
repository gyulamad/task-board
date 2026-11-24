<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #108: the epoch datetime storage and the per-user timezone render.
 * Every *_at column stores a UTC unix timestamp and nothing else; the render
 * layer converts it into the viewer's timezone exactly where it is displayed.
 * The timezone lives in the Settings page with the #66 user_preferences
 * discipline, defaults to UTC (the storage zone) and re-validates on every
 * read, so a stale or crafted preference can never break a page.
 */
final class DateTimeRenderingTest extends E2eTestCase
{
    private function storedStamp(string $table, int $id): int
    {
        return (int)TestServer::pdo()->query('SELECT created_at FROM ' . $table . ' WHERE id=' . $id)->fetchColumn();
    }

    public function test_a_fresh_user_without_a_preference_renders_utc(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Utc render task');
        $stamp = $this->storedStamp('tasks', $id);
        $this->assertGreaterThan(0, $stamp, 'The task stores an epoch second, not a string.');

        // No timezone preference set yet: the render is the plain UTC form,
        // i.e. exactly the classic board's shape.
        $page = $admin->taskPage($id);
        $expected = (new \DateTime('@' . $stamp))->format('Y-m-d H:i:s');
        $this->assertTrue($page->contains($expected), 'Without a preference the board renders UTC.');
    }

    public function test_the_timezone_preference_shifts_every_render(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Budapest render task');
        $stamp = $this->storedStamp('tasks', $id);

        // Pick a zone through the real Settings form.
        $settings = $admin->get('/?page=settings');
        $this->assertSame(1, preg_match('/name="csrf" value="([0-9a-f]{64})"/', $settings->body, $m));
        $response = $admin->post('/?page=settings', ['action' => 'save_timezone', 'timezone' => 'Europe/Budapest']);
        $this->assertSame(302, $response->status);

        // The task page now renders the same instant shifted by the zone's
        // offset (Budapest winter = UTC+1, summer = UTC+2), while the stored
        // epoch stays untouched.
        $page = $admin->taskPage($id);
        $budapest = (new \DateTime('@' . $stamp))->setTimezone(new \DateTimeZone('Europe/Budapest'))->format('Y-m-d H:i:s');
        $this->assertTrue($page->contains($budapest), 'The task page renders the viewer timezone.');
        $utc = (new \DateTime('@' . $stamp))->format('Y-m-d H:i:s');
        $this->assertNotSame($utc, $budapest, 'The picked zone really shifts the rendered time.');
        $this->assertSame($stamp, $this->storedStamp('tasks', $id), 'The render never rewrites the stored stamp.');

        // The Settings form remembers the pick.
        $again = $admin->get('/?page=settings');
        $this->assertTrue($again->contains('<option value="Europe/Budapest" selected>Europe/Budapest</option>'), 'The form preselects the stored zone.');
    }

    public function test_another_user_renders_their_own_zone_for_the_same_instant(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=users-v2', [
            'action' => 'add_user',
            'username' => 'tzviewer',
            'display_name' => 'Tzviewer Name',
            'password' => 'tzviewerPass12345',
            'role' => 'user',
        ]);
        $id = $admin->createTask('Two zones task');
        $stamp = $this->storedStamp('tasks', $id);

        $admin->post('/?page=settings', ['action' => 'save_timezone', 'timezone' => 'Europe/Budapest']);
        $viewer = new AppSession('tzviewer', 'tzviewerPass12345');
        $viewer->post('/?page=settings', ['action' => 'save_timezone', 'timezone' => 'America/New_York']);

        $adminPage = $admin->taskPage($id);
        $viewerPage = $viewer->taskPage($id);
        $budapest = (new \DateTime('@' . $stamp))->setTimezone(new \DateTimeZone('Europe/Budapest'))->format('Y-m-d H:i:s');
        $newYork = (new \DateTime('@' . $stamp))->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d H:i:s');
        $this->assertTrue($adminPage->contains($budapest), 'The admin sees Budapest time.');
        $this->assertTrue($viewerPage->contains($newYork), 'The viewer sees New York time for the same stored instant.');
        $this->assertNotSame($budapest, $newYork);
    }

    public function test_a_crafted_preference_falls_back_to_utc(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Fallback task');
        $stamp = $this->storedStamp('tasks', $id);

        // A stale or crafted preference (a zone deleted from PHP's list, or a
        // hand-written row) must never break a page: the helper falls back to
        // UTC and renders.
        TestServer::pdo()->prepare('UPDATE user_preferences SET pref_value=? WHERE user_id=? AND pref_key=?')
            ->execute(['Mars/Olympus_Mons', $this->userId('admin'), 'timezone']);

        $page = $admin->taskPage($id);
        $this->assertSame(200, $page->status, 'A crafted zone must not break the render.');
        $expected = (new \DateTime('@' . $stamp))->format('Y-m-d H:i:s');
        $this->assertTrue($page->contains($expected), 'The fallback renders UTC.');

        // The Settings form refuses an unknown zone outright.
        $response = $admin->post('/?page=settings', ['action' => 'save_timezone', 'timezone' => 'Mars/Olympus_Mons']);
        $this->assertTrue($response->contains('Unknown timezone.'), 'The save rejects an unknown zone.');
    }

    private function userId(string $username): int
    {
        return (int)TestServer::pdo()->query("SELECT id FROM users WHERE username='" . $username . "'")->fetchColumn();
    }
}
