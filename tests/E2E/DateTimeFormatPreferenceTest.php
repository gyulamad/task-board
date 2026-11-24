<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #110: the per-user date/time format preference. A Settings-page select
 * (the #66 user_preferences discipline) chooses the shape every datetime
 * renders in; the #108 render helper applies it on top of the viewer's
 * timezone. The default keeps the classic board's byte-identical shape, the
 * value re-validates against the tb_datetime_formats() allowlist on every
 * read, so a stale or crafted preference falls back instead of breaking a page.
 */
final class DateTimeFormatPreferenceTest extends E2eTestCase
{
    private function storedStamp(string $table, int $id): int
    {
        return (int)TestServer::pdo()->query('SELECT created_at FROM ' . $table . ' WHERE id=' . $id)->fetchColumn();
    }

    public function test_a_fresh_user_without_a_preference_renders_the_classic_shape(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Classic shape task');
        $stamp = $this->storedStamp('tasks', $id);

        // No datetime_format preference set yet: the render is byte-identical
        // to the #108 default, so nobody's board changes until they pick.
        // The fresh stamp also carries the #110 relative note ('just now') -
        // the absolute shape in front of it is what this test pins.
        $page = $admin->taskPage($id);
        $expected = (new \DateTime('@' . $stamp))->format('Y-m-d H:i:s');
        $this->assertTrue($page->contains($expected), 'Without a preference the classic shape renders.');
        $this->assertTrue($page->contains($expected . ' (just now)'), 'The relative note rides next to the classic shape.');
    }

    public function test_the_format_preference_renders_the_picked_shape(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Picked shape task');
        $stamp = $this->storedStamp('tasks', $id);

        // Pick a non-default shape through the real Settings form.
        $response = $admin->post('/?page=settings', ['action' => 'save_datetime_format', 'datetime_format' => 'd/m/Y H:i']);
        $this->assertSame(302, $response->status);

        // The task detail page renders the same instant in the picked shape.
        $page = $admin->taskPage($id);
        $expected = (new \DateTime('@' . $stamp))->format('d/m/Y H:i');
        $this->assertTrue($page->contains($expected), 'The task page renders the picked format.');
        $this->assertSame($stamp, $this->storedStamp('tasks', $id), 'The render never rewrites the stored stamp.');

        // The tasks list opts into the same shape (the Updated column).
        $list = $admin->get('/?page=tasks-v2');
        $this->assertTrue($list->contains($expected), 'The list cell renders the picked format.');
    }

    public function test_format_and_timezone_combine(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Combined render task');
        $stamp = $this->storedStamp('tasks', $id);

        $admin->post('/?page=settings', ['action' => 'save_timezone', 'timezone' => 'Europe/Budapest']);
        $admin->post('/?page=settings', ['action' => 'save_datetime_format', 'datetime_format' => 'd/m/Y H:i']);

        $page = $admin->taskPage($id);
        $expected = (new \DateTime('@' . $stamp))
            ->setTimezone(new \DateTimeZone('Europe/Budapest'))
            ->format('d/m/Y H:i');
        $this->assertTrue($page->contains($expected), 'The picked format renders in the picked zone.');
    }

    public function test_two_users_render_their_own_format_for_the_same_instant(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=users-v2', [
            'action' => 'add_user',
            'username' => 'fmtviewer',
            'display_name' => 'Format Viewer',
            'password' => 'fmtviewerPass12345',
            'role' => 'user',
        ]);
        $id = $admin->createTask('Two formats task');
        $stamp = $this->storedStamp('tasks', $id);

        $admin->post('/?page=settings', ['action' => 'save_datetime_format', 'datetime_format' => 'd.m.Y H:i']);
        $viewer = new AppSession('fmtviewer', 'fmtviewerPass12345');
        $viewer->post('/?page=settings', ['action' => 'save_datetime_format', 'datetime_format' => 'm/d/Y h:i A']);

        $adminPage = $admin->taskPage($id);
        $viewerPage = $viewer->taskPage($id);
        $adminShape = (new \DateTime('@' . $stamp))->format('d.m.Y H:i');
        $viewerShape = (new \DateTime('@' . $stamp))->format('m/d/Y h:i A');
        $this->assertTrue($adminPage->contains($adminShape), 'The admin sees their picked shape.');
        $this->assertTrue($viewerPage->contains($viewerShape), 'The viewer sees their own picked shape.');
        $this->assertNotSame($adminShape, $viewerShape, 'The two shapes really differ.');
    }

    public function test_a_crafted_preference_falls_back_to_the_default(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Fallback format task');
        $stamp = $this->storedStamp('tasks', $id);

        // A stale or crafted preference (a hand-written row) must never break
        // a page: the helper falls back to the classic default and renders.
        TestServer::pdo()->prepare('UPDATE user_preferences SET pref_value=? WHERE user_id=? AND pref_key=?')
            ->execute(['Q m-d-Y HACK', $this->userId('admin'), 'datetime_format']);

        $page = $admin->taskPage($id);
        $this->assertSame(200, $page->status, 'A crafted format must not break the render.');
        $expected = (new \DateTime('@' . $stamp))->format('Y-m-d H:i:s');
        $this->assertTrue($page->contains($expected), 'The fallback renders the classic default shape.');

        // The Settings form refuses an unknown format outright.
        $response = $admin->post('/?page=settings', ['action' => 'save_datetime_format', 'datetime_format' => 'Q m-d-Y HACK']);
        $this->assertTrue($response->contains('Unknown date/time format.'), 'The save rejects an unknown format.');
    }

    public function test_the_settings_form_renders_and_preselects_the_stored_pick(): void
    {
        $admin = $this->adminSession();

        $settings = $admin->get('/?page=settings');
        $this->assertSame(200, $settings->status);
        $this->assertTrue($settings->contains('name="datetime_format"'), 'The format form renders.');
        $this->assertTrue($settings->contains('value="save_datetime_format"'), 'The format form posts its own action.');
        $this->assertTrue($settings->contains('value="Y-m-d H:i:s" selected>Y-m-d H:i:s'), 'The default shape is preselected on a fresh user.');

        $admin->post('/?page=settings', ['action' => 'save_datetime_format', 'datetime_format' => 'D, M j, Y H:i']);
        $again = $admin->get('/?page=settings');
        $this->assertTrue($again->contains('<option value="D, M j, Y H:i" selected>D, M j, Y H:i'), 'The form preselects the stored shape.');
    }

    private function userId(string $username): int
    {
        return (int)TestServer::pdo()->query("SELECT id FROM users WHERE username='" . $username . "'")->fetchColumn();
    }
}
