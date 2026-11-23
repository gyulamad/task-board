<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

/**
 * Task #51 - realtime list views.
 *
 * The task list, the Kanban board, the waterflow chart and the Users page
 * render their live content inside one <div id="tb-list-region"
 * data-tb-refresh="10"> and emit the shared auto-refresh script once. These
 * tests pin the region placement (it must wrap exactly the list content and
 * nothing else) and the script emission, which is what the client-side
 * refresh depends on; the refresh itself is a browser behaviour.
 */
final class RealtimeListTest extends E2eTestCase
{
    private const REGION = 'id="tb-list-region"';
    private const SCRIPT = "document.getElementById('tb-list-region')";

    public function test_the_task_list_carries_the_refresh_region_around_the_bulk_table(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?');

        $this->assertSame(200, $page->status);
        $this->assertSame(1, substr_count($page->body, self::REGION), 'The task list must have exactly one refresh region.');
        $this->assertSame(1, substr_count($page->body, self::SCRIPT), 'The shared refresh script must be emitted once.');
        $this->assertTrue($page->contains('data-tb-refresh="10"'), 'The region carries the 10 second default.');

        // The region sits inside the bulk form and wraps the table with the
        // row checkboxes - the form itself, its CSRF field and the hidden
        // filter inputs stay outside and are never swapped. The first
        // </form> after the region start is the bulk form's own close (the
        // navbar and filter forms all close before the region starts).
        $form = strpos($page->body, 'id="bulk-form"');
        $region = (int)strpos($page->body, self::REGION);
        $checkAll = (int)strpos($page->body, 'id="check-all"');
        $formEnd = strpos($page->body, '</form>', $region);
        $this->assertNotFalse($form);
        $this->assertNotFalse($formEnd);
        $this->assertTrue($form < $region && $region < $checkAll && $checkAll < $formEnd, 'The region must wrap the task table inside the bulk form.');

        // The bulk wiring must be swap-safe: delegated listeners and a re-sync
        // on the region swap event instead of element-bound listeners.
        $this->assertTrue($page->contains("e.target.id==='check-all'"), 'Select-all must be delegated so it survives a swap.');
        $this->assertTrue($page->contains("tb-list-region:swapped"), 'The bulk buttons must re-sync after every swap.');
    }

    public function test_the_kanban_board_carries_the_refresh_region_around_the_columns(): void
    {
        $admin = $this->adminSession();
        $admin->createTask('Kanban refresh task', ['description' => 'Realtime payload 51']);
        $page = $admin->get('/?page=kanban');

        $this->assertSame(200, $page->status);
        $this->assertSame(1, substr_count($page->body, self::REGION), 'The Kanban board must have exactly one refresh region.');
        $this->assertSame(1, substr_count($page->body, self::SCRIPT), 'The shared refresh script must be emitted once.');
        $region = (int)strpos($page->body, self::REGION);
        $columns = (int)strpos($page->body, 'row-cols-xl-3');
        $this->assertTrue($region !== 0 && $columns > $region, 'The region must sit before the status columns it wraps.');
        $this->assertTrue($page->contains('Kanban refresh task'), 'The board renders the tasks inside the region.');
    }

    public function test_the_waterflow_chart_carries_the_refresh_region_around_the_chart(): void
    {
        $admin = $this->adminSession();
        $admin->createTask('Waterflow refresh task', ['description' => 'Realtime payload 51']);
        $page = $admin->get('/?page=waterflow');

        $this->assertSame(200, $page->status);
        $this->assertSame(1, substr_count($page->body, self::REGION), 'The waterflow chart must have exactly one refresh region.');
        $this->assertSame(1, substr_count($page->body, self::SCRIPT), 'The shared refresh script must be emitted once.');
        $region = (int)strpos($page->body, self::REGION);
        // An empty chart renders the "no tasks" card, a non-empty one the
        // level badges - either way inside the region that opens before it.
        $chart = strpos($page->body, 'No tasks match the current filters.');
        if ($chart === false) {
            $chart = strpos($page->body, 'Level 0');
        }
        $this->assertNotFalse($chart);
        $this->assertTrue($chart > $region, 'The region must start before the chart content it wraps.');
    }

    public function test_the_users_page_carries_the_refresh_region_around_the_user_table(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=users');

        $this->assertSame(200, $page->status);
        $this->assertSame(1, substr_count($page->body, self::REGION), 'The Users page must have exactly one refresh region.');
        $this->assertSame(1, substr_count($page->body, self::SCRIPT), 'The shared refresh script must be emitted once.');
        $this->assertTrue($page->contains('data-tb-refresh="10"'), 'The region carries the 10 second default.');

        // The create-user form with its text inputs must stay OUTSIDE the
        // swapped region - only the "All users" table refreshes.
        $createForm = (int)strpos($page->body, 'value="add_user"');
        $region = (int)strpos($page->body, self::REGION);
        $table = (int)strpos($page->body, 'All users');
        $this->assertTrue($createForm !== 0 && $createForm < $region && $region < $table, 'The region must wrap the user table only, not the create form.');
    }

    public function test_pages_without_a_list_do_not_refresh(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Detail page task', ['description' => 'Realtime payload 51']);

        foreach (['/?page=task&id=' . $taskId, '/?page=projects', '/?page=new', '/?page=admin'] as $url) {
            $page = $admin->get($url);
            $this->assertSame(200, $page->status);
            $this->assertSame(0, substr_count($page->body, 'tb-list-region'), "No region and no script may leak onto {$url}.");
        }
    }
}
