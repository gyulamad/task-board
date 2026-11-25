<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

/**
 * Task #48: the task list filter card shows only the free text search bar by
 * default; an Advanced filter toggle reveals the rest - the four dropdowns,
 * the Filter button and the saved searches. The card is the shared component
 * of the task list, the kanban board and the waterflow chart, so the toggle
 * behaves the same on all three. The area starts expanded when the visitor's
 * own request is already filtered by one of the advanced keys, so an active
 * filter is never hidden from the operator - but a #113-replayed bare visit
 * keeps the area closed (#119): the filters still apply and the toggle keeps
 * its 'on' badge. The collapse only hides things visually:
 * the inputs stay in the DOM and keep submitting (the scheduler area #35
 * pattern), so every filter URL keeps working and the list-behaviour suites
 * (MultiSelectFiltersTest, SavedSearchesTest, TaskFiltersTest, ...) keep
 * passing untouched.
 */
final class AdvancedFilterToggleTest extends E2eTestCase
{
    public function test_a_replayed_stored_view_keeps_the_area_closed(): void
    {
        // #119 x #113: a stored advanced filter replays silently on a bare
        // visit from another page - the list stays filtered and the toggle
        // keeps its 'on' badge, but the panel no longer shoves itself open.
        $admin = $this->adminSession();

        $filtered = $admin->get('/?page=tasks&assignee[]=unassigned');
        $this->assertTrue($filtered->contains('class="collapse show" id="advanced-filters"'), 'an explicit advanced filter opens the area (the #48 rule)');

        // Leave and come back bare: the stored view replays (#113).
        $admin->get('/?page=kanban');
        $replayed = $admin->get('/?page=tasks');
        $this->assertTrue($replayed->contains('<input type="hidden" name="assignee[]" value="unassigned">'), 'the replayed filter still applies');
        $this->assertTrue($replayed->contains('badge text-bg-info ms-1">on</span>'), 'the toggle still shows that an advanced filter is on');
        $this->assertTrue($replayed->contains('<div class="collapse" id="advanced-filters">'), 'the replayed bare visit keeps the area closed');
        $this->assertFalse($replayed->contains('class="collapse show" id="advanced-filters"'), 'no auto-expand on a replayed view');
        $this->assertTrue($replayed->contains('aria-expanded="false"'), 'the toggle reports the closed state');
    }

    public function test_the_search_bar_shows_alone_and_the_toggle_hides_the_rest(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=tasks');
        $this->assertSame(200, $page->status);

        // the search bar and the toggle sit outside the collapsible area
        $this->assertTrue($page->contains('placeholder="Search tasks"'));
        $this->assertTrue($page->contains('data-bs-target="#advanced-filters"'));
        $this->assertTrue($page->contains('aria-controls="advanced-filters"'));
        // nothing advanced is filtered yet: the area starts collapsed
        $this->assertTrue($page->contains('<div class="collapse" id="advanced-filters">'));
        $this->assertFalse($page->contains('class="collapse show" id="advanced-filters"'), 'the area starts collapsed with no advanced filter set');
        $this->assertTrue($page->contains('aria-expanded="false"'));
        // collapsed is not gone: the advanced inputs stay in the DOM and submit
        $this->assertTrue($page->contains('id="filter-assignee"'));
        $this->assertTrue($page->contains('id="filter-status"'));
        $this->assertTrue($page->contains('id="filter-parent"'));
        $this->assertTrue($page->contains('id="filter-project"'));
        $this->assertTrue($page->contains('No saved searches yet.'));
    }

    public function test_active_advanced_filters_open_the_area_expanded(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=tasks&status[]=2&assignee[]=unassigned');
        $this->assertSame(200, $page->status);

        $this->assertTrue($page->contains('class="collapse show" id="advanced-filters"'), 'an applied advanced filter opens the area');
        $this->assertTrue($page->contains('aria-expanded="true"'));
        $this->assertTrue($page->contains('<span class="badge text-bg-info ms-1">on</span>'), 'the toggle marks the advanced area as on');
        // the applied values stay selected in the (now visible) dropdowns
        $this->assertTrue($page->contains('<option value="unassigned" selected>'));
    }

    public function test_the_search_bar_keeps_the_active_advanced_filters(): void
    {
        // The old single form submitted the dropdowns together with the search
        // text; the split forms carry each other's current values as hidden
        // inputs so neither submit path ever drops the other half.
        $admin = $this->adminSession();
        $page = $admin->get('/?page=tasks&q=Beta&status[]=2&project[]=none');
        $this->assertSame(200, $page->status);

        // the search form carries the advanced filters ...
        $this->assertTrue($page->contains('<input type="hidden" name="status[]" value="2">'));
        $this->assertTrue($page->contains('<input type="hidden" name="project[]" value="none">'));
        // ... and the advanced form carries the search text
        $this->assertTrue($page->contains('<input type="hidden" name="q" value="Beta">'));
    }

    public function test_the_toggle_is_shared_by_the_kanban_and_waterflow_boards(): void
    {
        $admin = $this->adminSession();

        foreach (['kanban', 'waterflow'] as $board) {
            $plain = $admin->get('/?page=' . $board);
            $this->assertSame(200, $plain->status);
            $this->assertTrue($plain->contains('data-bs-target="#advanced-filters"'), 'the ' . $board . ' board shares the toggle');
            $this->assertTrue($plain->contains('<div class="collapse" id="advanced-filters">'), 'the ' . $board . ' board starts collapsed too');

            $filtered = $admin->get('/?page=' . $board . '&status[]=1');
            $this->assertTrue($filtered->contains('class="collapse show" id="advanced-filters"'), 'the ' . $board . ' board opens the area when a filter is applied');
        }
    }

    public function test_saved_searches_live_inside_the_advanced_area(): void
    {
        $admin = $this->adminSession();
        $admin->get('/?page=tasks');
        $response = $admin->post('/?page=tasks', ['action' => 'save_search', 'name' => 'My saved search', 'status' => ['2']]);
        $this->assertSame(302, $response->status);

        $plain = $admin->get('/?page=tasks');
        $this->assertTrue($plain->contains('Saved searches <span class="badge text-bg-secondary">1</span>'), 'the stored search is offered in the dropdown');
        $this->assertTrue($plain->contains('<div class="collapse" id="advanced-filters">'), 'a saved search alone does not force the area open');

        // opening the stored search link applies the advanced filter, so the area greets the operator expanded
        $opened = $admin->get('/?page=tasks&status[]=2');
        $this->assertTrue($opened->contains('class="collapse show" id="advanced-filters"'));
    }
}
