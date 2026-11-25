<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #113: the list view selection lives in the session. Arriving at the
 * bare list from somewhere else restores the stored filters and the picked
 * saved search; consecutive requests of the same list page keep the exact
 * pre-#113 behaviour (they ride their explicit params and never replay);
 * the Clear search button and the "- Not selected -" dropdown entry drop the
 * stored selection.
 */
final class SessionListViewTest extends E2eTestCase
{
    public function test_the_filter_selection_survives_a_round_trip(): void
    {
        $admin = $this->adminSession();
        $alpha = $admin->createTask('Alpha search token');
        $beta = $admin->createTask('Beta item');

        // Apply a filter (the same GET navigation the search box produces).
        $filtered = $admin->get('/?page=tasks&q=Alpha+search');
        $this->assertTrue($filtered->contains('#' . $alpha . ' · Alpha search token'));
        $this->assertFalse($filtered->contains('#' . $beta . ' · Beta item'));
        $this->assertTrue($filtered->contains('Clear search'), 'A selection makes the clear button appear.');

        // Walk to a task page and come back to the bare list.
        $admin->taskPage($alpha);
        $back = $admin->get('/?page=tasks');
        $this->assertSame(200, $back->status);
        $this->assertTrue($back->contains('value="Alpha search"'), 'The search box carries the remembered term.');
        $this->assertTrue($back->contains('#' . $alpha . ' · Alpha search token'), 'The remembered filter renders again.');
        $this->assertFalse($back->contains('#' . $beta . ' · Beta item'), 'The remembered filter really filters.');
    }

    public function test_the_stored_views_survive_a_logout_login_cycle(): void
    {
        // #120: the session stays the working copy, but every capture is
        // mirrored into the user_list_views table and a fresh session
        // hydrates from it on the first list-page request - the advanced
        // filter setups and the picked saved search survive logout/login.
        // A new AppSession is exactly the fresh-sign-in state: empty session,
        // same user.
        $admin = $this->adminSession();
        $this->createTaskRow();

        $admin->get('/?page=tasks&assignee[]=unassigned&sort=id&dir=asc');
        $row = (string)TestServer::pdo()->query("SELECT view_query FROM user_list_views WHERE page='tasks'")->fetchColumn();
        $this->assertTrue(str_contains($row, 'assignee'), 'The capture is mirrored into the persistent home.');
        $this->assertTrue(str_contains($row, 'dir=asc'), 'The stored query carries the whole view, not just the filters.');

        // A brand-new session (the next sign-in) hydrates and replays it.
        $fresh = new AppSession('admin', 'AdminPass1234');
        $replayed = $fresh->get('/?page=tasks');
        $this->assertTrue($replayed->contains('<input type="hidden" name="assignee[]" value="unassigned">'), 'The stored view survives the sign-in cycle.');
        $this->assertTrue($replayed->contains('value="asc"'), 'The remembered sort direction rides along.');

        // The explicit clear drops it everywhere, session and persistent home.
        $fresh->get('/?page=tasks&clear_search=1');
        $left = (int)TestServer::pdo()->query("SELECT COUNT(*) FROM user_list_views WHERE page='tasks'")->fetchColumn();
        $this->assertSame(0, $left, 'The clear deletes the persistent row too.');
        $plain = (new AppSession('admin', 'AdminPass1234'))->get('/?page=tasks');
        $this->assertFalse($plain->contains('<input type="hidden" name="assignee[]"'), 'The next sign-in starts from the plain list.');
    }

    public function test_a_consecutive_bare_fetch_stays_plain(): void
    {
        $admin = $this->adminSession();
        $this->createTaskRow();

        $admin->get('/?page=tasks&q=Alpha+search');
        // The very next request of the same page is NOT a return from
        // elsewhere: it renders the plain list exactly like before #113.
        $plain = $admin->get('/?page=tasks');
        $this->assertTrue($plain->contains('Beta item'), 'The plain list renders everything.');
        $this->assertFalse($plain->contains('value="Alpha search"'), 'No silent replay on a consecutive fetch.');
    }

    public function test_the_clear_button_drops_the_stored_selection(): void
    {
        $admin = $this->adminSession();
        $this->createTaskRow();

        $admin->get('/?page=tasks&q=Alpha+search');
        $cleared = $admin->get('/?page=tasks&clear_search=1');
        $this->assertSame(200, $cleared->status);
        $this->assertFalse($cleared->contains('Clear search'), 'A pristine view offers no clear button.');
        $this->assertTrue($cleared->contains('Beta item'), 'The cleared view renders the plain list.');

        // The stored selection is really gone: the return from elsewhere
        // shows the plain list again.
        $admin->taskPage(1);
        $back = $admin->get('/?page=tasks');
        $this->assertTrue($back->contains('Beta item'), 'After the clear the plain list stays.');
        $this->assertFalse($back->contains('value="Alpha search"'), 'The remembered term is gone.');
    }

    public function test_the_saved_search_dropdown_carries_the_not_selected_entry(): void
    {
        $admin = $this->adminSession();
        $this->createTaskRow();

        $admin->post('/?page=tasks', [
            'action' => 'save_search',
            'save_page' => 'tasks',
            'name' => 'Alpha by title',
            'q' => 'Alpha search',
        ]);
        $list = $admin->get('/?page=tasks');
        $this->assertTrue($list->contains('- Not selected -'), 'The dropdown carries the not-selected entry.');
        $this->assertTrue($list->contains('href="?page=tasks&amp;clear_search=1"'), 'The entry points at the clear endpoint.');

        // Opening a saved search stores it: the return from elsewhere
        // re-opens the saved search's view.
        $admin->get('/?page=tasks&q=Alpha+search');
        $admin->taskPage(1);
        $back = $admin->get('/?page=tasks');
        $this->assertTrue($back->contains('value="Alpha search"'), 'The picked saved search rides the session.');
        $this->assertFalse($back->contains('Beta item'), 'The picked saved search really filters.');

        // - Not selected - clears it.
        $admin->get('/?page=tasks&clear_search=1');
        $admin->taskPage(1);
        $plain = $admin->get('/?page=tasks');
        $this->assertTrue($plain->contains('Beta item'), 'After the not-selected pick the plain list renders.');
    }

    public function test_the_kanban_status_filter_survives_a_round_trip(): void
    {
        $admin = $this->adminSession();
        $admin->createTask('Todo item');
        $admin->createTask('Progress item', ['status_id' => '2']);

        $filtered = $admin->get('/?page=kanban&status[]=1');
        $this->assertTrue($filtered->contains($this->columnHeader('TODO')));
        $this->assertFalse($filtered->contains($this->columnHeader('IN PROGRESS')), 'The unselected column is gone.');

        $admin->taskPage(1);
        $back = $admin->get('/?page=kanban');
        $this->assertTrue($back->contains($this->columnHeader('TODO')));
        $this->assertFalse($back->contains($this->columnHeader('IN PROGRESS')), 'The remembered status filter renders again.');
        $this->assertTrue($back->contains('value="1" selected'), 'The filter dropdown marks the remembered pick.');
    }

    public function test_the_replayed_view_pagination_keeps_the_filters(): void
    {
        $admin = $this->adminSession();
        for ($i = 1; $i <= 25; $i++) {
            $admin->createTask('Alpha search token ' . $i);
        }

        $admin->get('/?page=tasks&q=Alpha+search');
        $admin->taskPage(1);
        $back = $admin->get('/?page=tasks');
        $this->assertTrue($back->contains('Page 1 of 2'), 'The remembered filter paginates.');
        $this->assertTrue((bool)preg_match('/href="\?page=tasks&amp;q=Alpha\+search&amp;p=2"/', $back->body), 'The pagination links carry the remembered filters.');
    }

    public function test_the_users_page_round_trips_its_filters(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'worker',
            'display_name' => 'Worker',
            'password' => 'WorkerPass12345',
            'role' => 'user',
        ]);

        $filtered = $admin->get('/?page=users&q=worker');
        $this->assertTrue($filtered->contains('Worker'));
        $admin->taskPage(1);
        $back = $admin->get('/?page=users');
        $this->assertTrue($back->contains('value="worker"'), 'The users search carries the remembered term.');
        $this->assertTrue($back->contains('Clear search'), 'The users page offers the clear button.');
        $admin->get('/?page=users&clear_search=1');
        $admin->taskPage(1);
        $plain = $admin->get('/?page=users');
        $this->assertFalse($plain->contains('value="worker"'), 'The clear dropped the users selection.');
    }

    private function createTaskRow(): void
    {
        $admin = $this->adminSession();
        $admin->createTask('Alpha search token');
        $admin->createTask('Beta item');
    }

    private function columnHeader(string $statusName): string
    {
        // The same header shape KanbanBoardTest pins (#66 markup).
        return '<div class="card-header bg-transparent d-flex justify-content-between align-items-center py-2">'
            . '<div class="d-flex align-items-center gap-2"><span class="badge text-bg-light border">' . $statusName . '</span>';
    }
}
