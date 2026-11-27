<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\TestServer;

final class RelationshipsTest extends E2eTestCase
{
    public function test_tasks_can_be_linked_and_unlinked(): void
    {
        $admin = $this->adminSession();
        $blocker = $admin->createTask('Blocker task');
        $blocked = $admin->createTask('Blocked task');

        $link = $admin->post('/?page=task&id=' . $blocker, [
            'action' => 'link_task',
            'task_id' => (string)$blocker,
            'related_task_id' => (string)$blocked,
            'link_type' => 'blocks',
        ]);
        $this->assertSame(302, $link->status);

        $page = $admin->taskPage($blocker);
        $this->assertTrue($page->contains('blocks'));
        $this->assertTrue($page->contains('#' . $blocked . ' Blocked task'));
        $this->assertTrue($page->contains('Relationship added'));

        $links = TestServer::pdo()->query('SELECT COUNT(*) FROM task_links WHERE task_id=' . $blocker)->fetchColumn();
        $this->assertSame('1', (string)$links);

        $unlink = $admin->post('/?page=task&id=' . $blocker, [
            'action' => 'unlink_task',
            'task_id' => (string)$blocker,
            'related_task_id' => (string)$blocked,
            'link_type' => 'blocks',
        ]);
        $this->assertSame(302, $unlink->status);
        $pageAfter = $admin->taskPage($blocker);
        $this->assertFalse($pageAfter->contains('#' . $blocked . ' Blocked task'));
        $linksAfter = TestServer::pdo()->query('SELECT COUNT(*) FROM task_links WHERE task_id=' . $blocker)->fetchColumn();
        $this->assertSame('0', (string)$linksAfter);
    }

    public function test_all_three_link_types_are_supported(): void
    {
        $admin = $this->adminSession();
        $main = $admin->createTask('Main task');
        $other1 = $admin->createTask('Other one');
        $other2 = $admin->createTask('Other two');
        $other3 = $admin->createTask('Other three');

        foreach ([[$other1, 'blocks'], [$other2, 'depends_on'], [$other3, 'relates_to']] as [$other, $type]) {
            $admin->post('/?page=task&id=' . $main, [
                'action' => 'link_task',
                'task_id' => (string)$main,
                'related_task_id' => (string)$other,
                'link_type' => $type,
            ]);
        }
        $types = TestServer::pdo()->query('SELECT link_type FROM task_links WHERE task_id=' . $main)->fetchAll();
        $this->assertCount(3, $types);
    }

    public function test_linking_a_task_to_itself_is_rejected(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Lonely task');

        $response = $admin->post('/?page=task&id=' . $id, [
            'action' => 'link_task',
            'task_id' => (string)$id,
            'related_task_id' => (string)$id,
            'link_type' => 'blocks',
        ]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Invalid task relationship.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM task_links')->fetchColumn());
    }

    public function test_unknown_link_types_are_rejected(): void
    {
        $admin = $this->adminSession();
        $first = $admin->createTask('First');
        $second = $admin->createTask('Second');

        $response = $admin->post('/?page=task&id=' . $first, [
            'action' => 'link_task',
            'task_id' => (string)$first,
            'related_task_id' => (string)$second,
            'link_type' => 'marries',
        ]);
        $this->assertTrue($response->contains('Invalid task relationship.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM task_links')->fetchColumn());
    }

    public function test_duplicate_links_are_ignored(): void
    {
        $admin = $this->adminSession();
        $first = $admin->createTask('First');
        $second = $admin->createTask('Second');

        for ($i = 0; $i < 3; $i++) {
            $response = $admin->post('/?page=task&id=' . $first, [
                'action' => 'link_task',
                'task_id' => (string)$first,
                'related_task_id' => (string)$second,
                'link_type' => 'relates_to',
            ]);
            $this->assertSame(302, $response->status);
        }
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM task_links')->fetchColumn());
    }

    // Task #180: the relationship picker is a search select - a search input
    // filters the native select's option list while the select itself stays
    // the real, required form control (no-JS fallback intact), an honest
    // empty option replaces the silent newest-task default, and a live hint
    // reports the match count.
    public function test_relationship_picker_is_a_search_select(): void
    {
        $admin = $this->adminSession();
        $main = $admin->createTask('Picker main');
        $alpha = $admin->createTask('Alpha reticle');

        $page = $admin->taskPage($main);

        $this->assertTrue($page->contains('data-tb-task-search'), 'The search input renders above the picker.');
        $this->assertTrue($page->contains('placeholder="Type to filter tasks..."'));
        $this->assertTrue($page->contains('aria-label="Search tasks to link"'));
        $this->assertTrue($page->contains('name="related_task_id" required data-tb-task-options'), 'The native select stays the real, required form control.');
        $this->assertTrue($page->contains('<option value="" disabled hidden selected data-tb-task-empty>No matching tasks</option>'), 'The picker starts empty instead of silently preselecting the newest task - the placeholder must carry selected, because the native default skips disabled options and would preselect the newest task.');
        $this->assertTrue($page->contains('id="tb-related-task-hint" data-tb-task-hint aria-live="polite"'), 'A live region reports the match count.');
        $this->assertTrue($page->contains('aria-describedby="tb-related-task-hint"'));
        $this->assertTrue($page->contains('value="' . $alpha . '">#' . $alpha . ' - Alpha reticle</option>'), 'Every linkable task is still an option.');
    }

    public function test_relationship_picker_excludes_the_page_task_and_archived_tasks(): void
    {
        $admin = $this->adminSession();
        $main = $admin->createTask('Picker main');
        $visible = $admin->createTask('Picker visible');
        $archived = $admin->createTask('Picker archived');
        $admin->post('/?page=task&id=' . $archived, ['action' => 'archive_task', 'id' => (string)$archived]);

        $page = $admin->taskPage($main);
        $this->assertTrue($page->contains('value="' . $visible . '">#' . $visible . ' - Picker visible</option>'));
        $this->assertFalse($page->contains('#' . $main . ' - Picker main</option>'), 'The page task never offers itself as a relationship.');
        $this->assertFalse($page->contains('#' . $archived . ' - Picker archived</option>'), 'Archived tasks stay out of the picker.');

        $archivedPage = $admin->taskPage($archived);
        $this->assertTrue($archivedPage->contains('data-tb-task-search'), 'The picker renders with its search on archived task pages too - linking stays allowed there.');
    }

    public function test_relationship_picker_script_filters_matches_and_restores_the_list(): void
    {
        $admin = $this->adminSession();
        $main = $admin->createTask('Script main');

        $body = $admin->taskPage($main)->body;

        $this->assertStringContainsString("o.text.toLowerCase().indexOf(q)!==-1", $body, 'The filter is a case-insensitive substring match on "#id - title".');
        $this->assertStringContainsString("o.hidden=!hit;o.style.display=hit?'':'none';", $body, 'Options hide via the attribute and an inline style (Safari ignores hidden on options).');
        $this->assertStringContainsString('select.value=first.value;', $body, 'Exactly one match preselects itself.');
        $this->assertStringContainsString("if(!picked||picked.hidden||picked.hasAttribute('data-tb-task-empty')){select.value='';}", $body, 'A filter that hides the picked task resets the selection instead of silently linking an invisible option.');
        $this->assertStringContainsString('empty.hidden=(shown>0);', $body, 'The honest empty option only surfaces when nothing matches.');
        $this->assertStringContainsString("'No task matches your search.'", $body);
        $this->assertStringContainsString("if(e.key==='Escape'){input.value='';render();}", $body, 'Escape empties the search and restores the full list.');
        $this->assertStringContainsString("if(e.key==='Enter'&&select.value===''){e.preventDefault();}", $body, 'Enter cannot submit mid-search on an empty selection.');
        $this->assertStringContainsString("q===''||o.text.toLowerCase()", $body, 'An emptied search restores every option.');
    }
}
