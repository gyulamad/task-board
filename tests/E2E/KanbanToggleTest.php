<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #66: the per-status kanban collapse toggle - every column header
 * carries a +/- button that collapses/expands that one status column, and
 * the state is a per-user preference (user_preferences, key
 * 'kanban_collapsed_statuses') that survives reloads, the list auto-refresh
 * and new sign-ins, separate per user.
 *
 * Note: the file reached develop truncated (the 66v2 merge shipped the test
 * methods without the class wrapper, so PHPUnit could not find the class and
 * every SQLite CI run exited 1 on the runner warning); this version restores
 * the wrapper and the helpers the three tests call.
 */
final class KanbanToggleTest extends E2eTestCase
{
    private function createRegularUser(AppSession $admin, string $username): AppSession
    {
        $admin->post('/?page=users', [
            'action' => 'add_user',
            'username' => $username,
            'display_name' => ucfirst($username),
            'password' => $username . 'Pass12345',
            'role' => 'user',
        ]);
        return new AppSession($username, $username . 'Pass12345');
    }

    private function userIdFromName(string $username): int
    {
        $s = TestServer::pdo()->prepare('SELECT id FROM users WHERE username=?');
        $s->execute([$username]);
        $rows = $s->fetchAll(\PDO::FETCH_COLUMN);
        $s->closeCursor();
        $this->assertNotEmpty($rows, 'User ' . $username . ' does not exist.');
        return (int)$rows[0];
    }

    /** The stored kanban_collapsed_statuses preference of a user, '' if none. */
    private function collapsedValue(int $userId): string
    {
        $s = TestServer::pdo()->prepare("SELECT pref_value FROM user_preferences WHERE user_id=? AND pref_key='kanban_collapsed_statuses'");
        $s->execute([$userId]);
        $rows = $s->fetchAll(\PDO::FETCH_COLUMN);
        $s->closeCursor();
        return (string)($rows[0] ?? '');
    }

    public function test_toggling_a_column_collapses_it_and_persists(): void
    {
        $admin = $this->adminSession();
        $admin->createTask('Visible task');
        $board = $admin->get('/?page=kanban')->body;

        // Every column header carries the toggle; TODO starts open.
        $this->assertTrue(str_contains($board, 'name="action" value="toggle_kanban_column"'));
        $this->assertTrue(str_contains($board, 'aria-label="Collapse column"'), 'Open columns offer the collapse toggle.');
        $this->assertFalse(str_contains($board, 'card-body p-2 d-none'), 'Nothing is collapsed before the first toggle.');

        // Collapse the TODO column (status id 1 on a fresh board).
        $this->assertSame(302, $admin->post('/?page=kanban', [
            'action' => 'toggle_kanban_column', 'status_id' => '1',
        ])->status, 'The toggle redirects back to the board (PRG).');
        $this->assertSame('1', $this->collapsedValue($this->userIdFromName('admin')), 'The collapsed status id is stored as a preference.');

        // The column renders collapsed and stays collapsed on the next load -
        // which is exactly what the 10s auto-refresh re-renders.
        for ($i = 0; $i < 2; $i++) {
            $board = $admin->get('/?page=kanban')->body;
            $this->assertTrue(str_contains($board, 'card-body p-2 d-none'), 'The TODO column body is hidden server-side.');
            $this->assertTrue(str_contains($board, 'aria-label="Expand column"'), 'A collapsed column offers the expand toggle.');
            $this->assertStringNotContainsString('Visible task', $board, 'The collapsed column renders no cards.');
        }

        // Expanding restores the column and empties the preference.
        $this->assertSame(302, $admin->post('/?page=kanban', ['action' => 'toggle_kanban_column', 'status_id' => '1'])->status);
        $board = $admin->get('/?page=kanban')->body;
        $this->assertFalse(str_contains($board, 'card-body p-2 d-none'), 'The column is open again.');
        $this->assertTrue(str_contains($board, 'Visible task'));
        $this->assertSame('', $this->collapsedValue($this->userIdFromName('admin')), 'No collapsed ids remain.');
    }

    public function test_the_collapsed_state_is_per_user(): void
    {
        $admin = $this->adminSession();
        $wanda = $this->createRegularUser($admin, 'wanda');

        $this->assertSame(302, $admin->post('/?page=kanban', ['action' => 'toggle_kanban_column', 'status_id' => '2'])->status);

        // Wanda's board is unaffected: her columns are all open and she has
        // no preference row of her own yet.
        $board = $wanda->get('/?page=kanban')->body;
        $this->assertFalse(str_contains($board, 'card-body p-2 d-none'), "Another user's collapse must not leak.");
        $this->assertSame('', $this->collapsedValue($this->userIdFromName('wanda')));

        // Wanda collapses her own column: two independent rows, two boards.
        $this->assertSame(302, $wanda->post('/?page=kanban', ['action' => 'toggle_kanban_column', 'status_id' => '3'])->status);
        $this->assertSame('2', $this->collapsedValue($this->userIdFromName('admin')));
        $this->assertSame('3', $this->collapsedValue($this->userIdFromName('wanda')));
        $this->assertTrue(str_contains($admin->get('/?page=kanban')->body, 'card-body p-2 d-none'), 'Admin still has status 2 collapsed.');
        $wandaBoard = $wanda->get('/?page=kanban')->body;
        $this->assertTrue(str_contains($wandaBoard, 'card-body p-2 d-none'), 'Wanda has status 3 collapsed.');
        $this->assertTrue(str_contains($wandaBoard, 'aria-label="Expand column"'), 'Wanda has exactly one collapsed column.');
        $this->assertTrue(str_contains($wandaBoard, 'aria-label="Collapse column"'), 'The open columns still offer the collapse toggle.');
    }

    public function test_the_preference_table_self_heals_and_the_toggle_survives(): void
    {
        $admin = $this->adminSession();
        TestServer::pdo()->exec('DROP TABLE user_preferences');

        // The next request recreates the table (tb_migrate_schema) and the
        // board keeps working.
        $board = $admin->get('/?page=kanban')->body;
        $this->assertTrue(str_contains($board, 'name="action" value="toggle_kanban_column"'), 'The board renders after the table was dropped.');

        $this->assertSame(302, $admin->post('/?page=kanban', ['action' => 'toggle_kanban_column', 'status_id' => '1'])->status);
        $this->assertSame('1', $this->collapsedValue($this->userIdFromName('admin')), 'The toggle persists into the recreated table.');
    }
}
