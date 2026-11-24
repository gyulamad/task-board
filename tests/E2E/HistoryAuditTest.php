<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #54 - the full change audit.
 *
 * Every task save writes one history row per changed field with the from/to
 * values recorded human-readable, and every history row links to the change
 * details page (?page=task_history&id=N) that shows who changed what from
 * what, when.
 */
final class HistoryAuditTest extends E2eTestCase
{
    /** The task_history rows of a task, newest first. */
    private function rows(int $taskId): array
    {
        return TestServer::pdo()->query('SELECT * FROM task_history WHERE task_id=' . $taskId . ' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** The recorded audit values indexed by field name. */
    private function audit(int $taskId): array
    {
        $out = [];
        foreach ($this->rows($taskId) as $row) {
            if ($row['field'] !== null) $out[$row['field']] = $row;
        }
        return $out;
    }

    public function test_updating_a_task_writes_one_audit_row_per_changed_field(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Audit target', ['description' => 'Original description.']);

        $this->assertSame(302, $admin->post('/?page=edit&id=' . $taskId, [
            'action' => 'save_task',
            'id' => (string)$taskId,
            'title' => 'Audit target renamed',
            'description' => 'Updated description.',
            'status_id' => '2',
            'complexity' => '5',
            'priority' => '7',
            'assignees' => ['1'],
        ])->status);

        $audit = $this->audit($taskId);
        $this->assertSame('Audit target', $audit['title']['old_value']);
        $this->assertSame('Audit target renamed', $audit['title']['new_value']);
        $this->assertSame('Original description.', $audit['description']['old_value']);
        $this->assertSame('Updated description.', $audit['description']['new_value']);
        $this->assertSame('TODO', $audit['status']['old_value'], 'The status is recorded as its name, not the id.');
        $this->assertSame('IN PROGRESS', $audit['status']['new_value']);
        $this->assertSame('1', $audit['complexity']['old_value']);
        $this->assertSame('5', $audit['complexity']['new_value']);
        $this->assertSame('1', $audit['priority']['old_value']);
        $this->assertSame('7', $audit['priority']['new_value']);
        $this->assertSame('—', $audit['assignees']['old_value'], 'The assignee roster is recorded human-readable.');
        $this->assertSame('Administrator', $audit['assignees']['new_value']);
        $this->assertArrayNotHasKey('parent', $audit, 'Unchanged fields must not produce audit rows.');
        $this->assertArrayNotHasKey('project', $audit);
        $this->assertSame('Field changed', $audit['title']['event']);
        $this->assertSame('Title changed', $audit['title']['details']);
        $this->assertSame('Administrator', $this->actorName($audit['title']));

        // #54 v2 feedback: the generic entry's details text names the changed
        // fields and spells out every from/to in the same sentence.
        $generic = null;
        foreach ($this->rows($taskId) as $row) {
            if ($row['event'] === 'Task updated' && $row['field'] === null) $generic = $row;
        }
        $this->assertNotNull($generic);
        $this->assertSame(
            'The followings are changed: Title, Description, Status, Complexity, Priority, Assignees. '
            . 'Title changed from "Audit target" to "Audit target renamed"; '
            . 'Description changed from "Original description." to "Updated description."; '
            . 'Status changed from "TODO" to "IN PROGRESS"; '
            . 'Complexity changed from "1" to "5"; '
            . 'Priority changed from "1" to "7"; '
            . 'Assignees changed from "—" to "Administrator".',
            $generic['details']
        );
    }

    private function actorName(array $row): string
    {
        return (string)TestServer::pdo()->query('SELECT display_name FROM users WHERE id=' . (int)$row['user_id'])->fetchColumn();
    }

    public function test_long_history_details_are_cut_at_100_chars_on_the_task_page(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Long history task', ['description' => str_repeat('a', 150)]);
        $admin->post('/?page=edit&id=' . $taskId, [
            'action' => 'save_task', 'id' => (string)$taskId, 'title' => 'Long history task',
            'description' => str_repeat('b', 150), 'status_id' => '1',
            'parent_id' => '', 'assignees' => [],
        ]);

        // The generic entry's summary and the field row's from/to values are
        // far longer than 100 characters: the task page cuts them with an
        // ellipsis (#80), and the row's details link shows the full change.
        $page = $admin->get('/?page=task&id=' . $taskId);
        $this->assertTrue($page->contains('The followings are changed: Description.'), 'The generic summary stays visible within the cut.');
        $this->assertTrue($page->contains(str_repeat('a', 100) . '…'), 'The long old value renders cut at exactly 100 characters with the ellipsis.');
        $this->assertTrue($page->contains(str_repeat('b', 100) . '…'), 'The long new value renders cut at exactly 100 characters with the ellipsis.');
        $this->assertFalse($page->contains(str_repeat('a', 150)), 'The full old value never leaks into the history list.');
        $this->assertTrue($page->contains('page=task_history&id='), 'The details link is on the row for the full audit (#54).');
    }

    public function test_an_unchanged_save_writes_no_audit_rows(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Stable task', ['description' => 'Same text.']);
        $this->assertCount(1, $this->rows($taskId), 'Creating a task writes the Task created entry.');

        $page = $admin->get('/?page=edit&id=' . $taskId);
        preg_match('/name="status_id" required>.*?<option value="(\d+)"/s', $page->body, $m);
        $this->assertSame(302, $admin->post('/?page=edit&id=' . $taskId, [
            'action' => 'save_task',
            'id' => (string)$taskId,
            'title' => 'Stable task',
            'description' => 'Same text.',
            'status_id' => $m[1],
            'assignees' => [],
        ])->status);

        $rows = $this->rows($taskId);
        $this->assertCount(2, $rows, 'The generic Task updated entry stays, without any field rows.');
        $this->assertSame('Task updated', $rows[1]['event']);
        $this->assertNull($rows[1]['field']);
        $this->assertSame('No fields were changed.', $rows[1]['details'], 'The unchanged save says so instead of hinting at changes.');
    }

    public function test_the_task_page_history_links_to_the_change_details(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Linked history');
        $admin->post('/?page=edit&id=' . $taskId, [
            'action' => 'save_task',
            'id' => (string)$taskId,
            'title' => 'Linked history v2',
            'description' => 'Same text.',
            'status_id' => '1',
            'assignees' => [],
        ]);

        $page = $admin->get('/?page=task&id=' . $taskId);
        $this->assertTrue($page->contains('page=task_history&id='), 'Every history row must link the details page.');
        $this->assertTrue($page->contains('Field &quot;title&quot;: from &quot;Linked history&quot; to &quot;Linked history v2&quot;'), 'The task page summarizes the from/to values inline.');

        // The title field row's details page: the full audit record.
        $fieldRowId = (int)TestServer::pdo()->query("SELECT id FROM task_history WHERE task_id={$taskId} AND field='title'")->fetchColumn();
        $details = $admin->get('/?page=task_history&id=' . $fieldRowId);
        $this->assertSame(200, $details->status);
        $this->assertTrue($details->contains('Change details'));
        $this->assertTrue($details->contains('Field changed'));
        $this->assertTrue($details->contains('title'));
        $this->assertTrue($details->contains('Linked history'), 'The From value renders on the details page.');
        $this->assertTrue($details->contains('Linked history v2'), 'The To value renders on the details page.');
        $this->assertTrue($details->contains('page=task&id=' . $taskId), 'The details page links back to the task.');
        $this->assertTrue($details->contains('by'), 'The actor renders on the details page.');

        // The generic entry's details page: the full audit of the save - the
        // summary sentence plus the From/To table of exactly this save's fields.
        $genericRowId = (int)TestServer::pdo()->query("SELECT id FROM task_history WHERE task_id={$taskId} AND event='Task updated' AND field IS NULL")->fetchColumn();
        $generic = $admin->get('/?page=task_history&id=' . $genericRowId);
        $this->assertTrue($generic->contains('Task updated'));
        // the save changed title AND description (the helper seeds a description)
        $this->assertTrue($generic->contains('The followings are changed: Title, Description.'), 'The generic entry says what changed.');
        $this->assertTrue($generic->contains('Title changed'), 'The changed-field table renders on the generic entry page.');
        $this->assertTrue($generic->contains('Linked history'), 'The From value renders in the group table.');
        $this->assertTrue($generic->contains('Linked history v2'), 'The To value renders in the group table.');
    }

    public function test_event_level_entries_render_without_a_field_table(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Comment audit');
        $admin->post('/?page=task&id=' . $taskId, ['action' => 'comment', 'task_id' => (string)$taskId, 'body' => 'A plain comment.']);

        $rows = $this->rows($taskId);
        $commentRow = null;
        foreach ($rows as $row) {
            if ($row['event'] === 'Comment added') $commentRow = $row;
        }
        $this->assertNotNull($commentRow);
        $this->assertNull($commentRow['field'], 'Event entries carry no field.');

        $details = $admin->get('/?page=task_history&id=' . $commentRow['id']);
        $this->assertSame(200, $details->status);
        $this->assertTrue($details->contains('Comment added'));
        // #54 v2 feedback: the entry shows the comment itself, not just that
        // something was added, plus a link to it on the task page.
        $this->assertTrue($details->contains('A plain comment.'), 'The comment text renders on the details page.');
        $this->assertFalse($details->contains('A comment was added.'), 'The generic filler text is gone.');
        $this->assertTrue($details->contains('page=task&id=' . $taskId . '#comments'), 'The details page links to the comment on the task page.');
        $this->assertStringStartsNotWith('<th', $details->body, 'No field table renders for an event entry.');
    }

    public function test_the_generic_entry_groups_exactly_its_own_save_fields(): void
    {
        // #54 v2: two saves, each changing one different field. The first
        // save's details page lists only the title row, the second's only the
        // status row - the From/To table never leaks another save's fields.
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Grouped audit');

        $admin->post('/?page=edit&id=' . $taskId, [
            'action' => 'save_task',
            'id' => (string)$taskId,
            'title' => 'Grouped audit v2',
            'description' => 'Same text.',
            'status_id' => '1',
            'assignees' => [],
        ]);
        $admin->post('/?page=edit&id=' . $taskId, [
            'action' => 'save_task',
            'id' => (string)$taskId,
            'title' => 'Grouped audit v2',
            'description' => 'Same text.',
            'status_id' => '2',
            'assignees' => [],
        ]);

        $genericIds = [];
        foreach ($this->rows($taskId) as $row) {
            if ($row['event'] === 'Task updated' && $row['field'] === null) $genericIds[] = (int)$row['id'];
        }
        $this->assertCount(2, $genericIds);

        $first = $admin->get('/?page=task_history&id=' . $genericIds[0]);
        $this->assertTrue($first->contains('The followings are changed: Title, Description.'), 'The first save changed title and description.');
        $this->assertTrue($first->contains('Title changed'));
        $this->assertFalse($first->contains('Status changed'), 'The second save\'s field row must not leak into the first save\'s page.');

        $second = $admin->get('/?page=task_history&id=' . $genericIds[1]);
        $this->assertTrue($second->contains('The followings are changed: Status.'));
        $this->assertTrue($second->contains('Status changed'));
        $this->assertTrue($second->contains('TODO'), 'The from value of the status change renders.');
        $this->assertTrue($second->contains('IN PROGRESS'));
        $this->assertFalse($second->contains('Title changed'), 'The first save\'s field row must not leak into the second save\'s page.');
    }

    public function test_the_schedule_and_project_changes_are_audited(): void
    {
        $admin = $this->adminSession();
        $projectId = 0;
        $admin->post('/?page=project_new', ['action' => 'save_project', 'name' => 'Audit project', 'status_id' => '1']);
        $list = $admin->get('/?page=projects');
        preg_match('/page=project&id=(\d+)/', $list->body, $m);
        $projectId = (int)$m[1];

        $taskId = $admin->createTask('Schedule audit target');
        $this->assertSame(302, $admin->post('/?page=edit&id=' . $taskId, [
            'action' => 'save_task',
            'id' => (string)$taskId,
            'title' => 'Schedule audit target',
            'description' => 'Same text.',
            'status_id' => '1',
            'project_id' => (string)$projectId,
            'schedule_period' => 'daily',
            'assignees' => [],
        ])->status);

        $audit = $this->audit($taskId);
        $this->assertSame('—', $audit['project']['old_value']);
        $this->assertSame('Audit project', $audit['project']['new_value']);
        $this->assertSame('—', $audit['schedule_period']['old_value']);
        $this->assertSame('daily', $audit['schedule_period']['new_value']);
    }

    public function test_anonymous_users_are_pushed_to_sign_in(): void
    {
        $anonymous = new AppSession();
        $this->assertSame(302, $anonymous->get('/?page=task_history&id=1')->status);
    }
}
