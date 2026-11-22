<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #35: the scheduler settings on the task create/edit pages live in a
 * separated area of their own behind a show/hide toggle. The three inputs sit
 * outside the main <form> element but stay attached to it through the HTML5
 * form attribute, so the one "Save task" button still persists everything and
 * the POST shape is unchanged (SchedulerTest keeps passing untouched). A task
 * that already has a schedule opens the area expanded; hidden inputs still
 * submit, so collapsing never changes what a save stores.
 */
final class SchedulerFormTest extends E2eTestCase
{
    public function test_the_scheduler_area_is_separated_collapsed_and_attached_to_the_task_form(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Unscheduled task');
        $edit = $admin->get('/?page=edit&id=' . $id);
        $this->assertSame(200, $edit->status);

        // the task form carries the id the scheduler inputs point at
        $this->assertTrue($edit->contains('id="task-form"'));
        // the show/hide toggle and the area exist
        $this->assertTrue($edit->contains('data-bs-target="#scheduler-area"'));
        $this->assertTrue($edit->contains('id="scheduler-area"'));
        // a task without a schedule starts collapsed
        $this->assertFalse($edit->contains('class="collapse show" id="scheduler-area"'));
        // the three scheduler inputs live outside the main form but stay attached to it
        $this->assertTrue($edit->contains('name="schedule_period" form="task-form"'));
        $this->assertTrue($edit->contains('name="schedule_status_id" form="task-form"'));
        $this->assertTrue($edit->contains('name="schedule_assignees[]" form="task-form"'));
        // and they are no longer inline in the main form's field row
        $this->assertFalse($edit->contains('<select class="form-select" name="schedule_period">'), 'the old inline select (without the form attribute) is gone');
    }

    public function test_the_create_page_has_the_same_toggle_area(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=new');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('data-bs-target="#scheduler-area"'));
        $this->assertTrue($page->contains('name="schedule_period" form="task-form"'));
        $this->assertFalse($page->contains('class="collapse show" id="scheduler-area"'), 'the area starts collapsed on create');
    }

    public function test_a_scheduled_task_opens_the_area_expanded(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Scheduled task');
        // give the task a schedule through the regular save flow (#31 fields)
        $response = $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Scheduled task',
            'status_id' => '1',
            'schedule_period' => 'daily',
        ]);
        $this->assertSame(302, $response->status);

        $edit = $admin->get('/?page=edit&id=' . $id);
        $this->assertTrue($edit->contains('class="collapse show" id="scheduler-area"'), 'the area starts expanded when the task already has a schedule');
        $this->assertTrue($edit->contains('aria-expanded="true"'));
        $this->assertTrue($edit->contains('text-bg-info'), 'the toggle badge shows the schedule is on');
        // the stored period stays preselected
        $this->assertTrue($edit->contains('value="daily" selected'));
    }

    public function test_a_collapsed_area_still_submits_its_values(): void
    {
        // The collapse only hides the inputs visually; they stay in the DOM and
        // keep submitting, so a schedule set earlier survives saves made while
        // the area is collapsed (the operator never loses the setting by
        // tucking it away).
        $admin = $this->adminSession();
        $id = $admin->createTask('Survives the collapse');
        $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Survives the collapse',
            'status_id' => '1',
            'schedule_period' => 'weekly',
        ]);

        // an edit-page save with the scheduler inputs present but the area
        // collapsed (the HTTP client cannot click the toggle - the markup
        // guarantees the inputs submit regardless of the visual state)
        $response = $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Survives the collapse (edited title)',
            'status_id' => '1',
            'schedule_period' => 'weekly',
        ]);
        $this->assertSame(302, $response->status);

        $row = TestServer::pdo()->query('SELECT title, schedule_period FROM tasks WHERE id=' . $id)->fetch();
        $this->assertSame('Survives the collapse (edited title)', $row['title']);
        $this->assertSame('weekly', $row['schedule_period'], 'the schedule survives the save');
    }
}
