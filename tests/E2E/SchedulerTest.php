<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * End-to-end coverage for the task scheduler (#18).
 *
 * The scheduler lets a task opt in to a recurring period. The external cron job
 * calls ?page=cronjob (no login, no CSRF) and every scheduled task that has not
 * been updated for longer than its period is reset to TODO, unassigned, and gets
 * the automatic "Reopen by auto-scheduler" comment plus a history entry.
 */
final class SchedulerTest extends E2eTestCase
{
    /** @var array<string, string> MySQL interval per period, used to backdate tasks */
    private const MYSQL_INTERVALS = [
        'hourly'   => '2 HOUR',
        'daily'    => '2 DAY',
        'weekly'   => '2 WEEK',
        'biweekly' => '4 WEEK',
        'monthly'  => '2 MONTH',
    ];

    /** @var array<string, string> SQLite modifier per period (double the period, so the task is safely overdue) */
    private const SQLITE_MODIFIERS = [
        'hourly'   => '-2 hours',
        'daily'    => '-2 days',
        'weekly'   => '-14 days',
        'biweekly' => '-28 days',
        'monthly'  => '-2 months',
    ];

    /** Sets tasks.updated_at back to twice the period so the task is overdue. */
    private function backdateTask(int $taskId, string $period): void
    {
        $pdo = TestServer::pdo();
        if (TestServer::dbMode() === 'mysql') {
            $pdo->exec('UPDATE tasks SET updated_at = DATE_SUB(NOW(), INTERVAL ' . self::MYSQL_INTERVALS[$period] . ') WHERE id=' . $taskId);
        } else {
            $pdo->exec("UPDATE tasks SET updated_at = datetime('now', '" . self::SQLITE_MODIFIERS[$period] . "') WHERE id=" . $taskId);
        }
    }

    /** Creates a task, then schedules it through the real edit form. */
    private function createScheduledTask(AppSession $session, string $title, string $period, int $statusId = 1, array $assignees = [], int $scheduleStatusId = 0, array $scheduleAssignees = []): int
    {
        $id = $session->createTask($title);
        $response = $session->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => $title,
            'description' => 'Scheduled task for the auto-scheduler tests',
            'status_id' => (string)$statusId,
            'parent_id' => '',
            'schedule_period' => $period,
            'schedule_status_id' => $scheduleStatusId ? (string)$scheduleStatusId : '',
            'schedule_assignees' => $scheduleAssignees,
            'assignees' => $assignees,
        ]);
        $this->assertSame(302, $response->status, 'Saving the schedule should redirect back to the task page.');
        return $id;
    }

    private function todoStatusId(): int
    {
        return (int)TestServer::pdo()->query("SELECT id FROM statuses WHERE name='TODO' ORDER BY id LIMIT 1")->fetchColumn();
    }

    private function taskRow(int $taskId): array
    {
        return TestServer::pdo()->query('SELECT status_id, schedule_period, schedule_status_id, schedule_assignees FROM tasks WHERE id=' . $taskId)->fetch();
    }

    private function assignedIds(int $taskId): array
    {
        return array_map('intval', array_column(
            TestServer::pdo()->query('SELECT user_id FROM task_assignees WHERE task_id=' . $taskId . ' ORDER BY user_id')->fetchAll(),
            'user_id'
        ));
    }

    /** Creates a second user (like the real admin page does) and returns its id. */
    private function createJane(): int
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'jane',
            'display_name' => 'Jane Doe',
            'password' => 'JanePass12345',
            'role' => 'user',
        ]);
        $this->assertSame(302, $response->status);
        return (int)TestServer::pdo()->query("SELECT id FROM users WHERE username='jane'")->fetchColumn();
    }

    private function commentCount(int $taskId): int
    {
        return (int)TestServer::pdo()->query('SELECT COUNT(*) FROM comments WHERE task_id=' . $taskId)->fetchColumn();
    }

    public function test_edit_form_saves_and_displays_the_schedule(): void
    {
        $admin = $this->adminSession();
        $id = $this->createScheduledTask($admin, 'Recurring maintenance', 'daily', 3, ['1']);

        $row = $this->taskRow($id);
        $this->assertSame('daily', $row['schedule_period']);
        $this->assertSame(3, (int)$row['status_id']);

        $page = $admin->taskPage($id);
        $this->assertTrue($page->contains('Schedule:'));
        $this->assertTrue($page->contains('Daily'));
        $this->assertTrue($page->contains('auto-reopen'));

        // The edit form preselects the stored period and offers "None" again.
        $edit = $admin->get('/?page=edit&id=' . $id);
        $this->assertTrue($edit->contains('Auto-reopen schedule'));
        $this->assertTrue($edit->contains('value="daily" selected'));

        $cleared = $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Recurring maintenance',
            'description' => '',
            'status_id' => '3',
            'parent_id' => '',
            'schedule_period' => '',
            'assignees' => [],
        ]);
        $this->assertSame(302, $cleared->status);
        $this->assertNull($this->taskRow($id)['schedule_period']);
        $page = $admin->taskPage($id);
        $this->assertFalse($page->contains('Schedule:'));
    }

    public function test_invalid_schedule_period_is_rejected(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Unscheduled task');
        $response = $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Unscheduled task',
            'description' => '',
            'status_id' => '1',
            'parent_id' => '',
            'schedule_period' => 'every-fortnight',
            'assignees' => [],
        ]);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Invalid schedule period.'));
        $this->assertNull($this->taskRow($id)['schedule_period']);
    }

    public function test_cronjob_reopens_a_stale_scheduled_done_task(): void
    {
        $admin = $this->adminSession();
        $id = $this->createScheduledTask($admin, 'Security review', 'hourly', 3, ['1']);
        $this->backdateTask($id, 'hourly');

        $response = $admin->get('/?page=cronjob');
        $this->assertSame(200, $response->status);
        $this->assertStringContainsStringIgnoringCase('reopened 1 task', $response->body);
        $this->assertStringContainsStringIgnoringCase('#' . $id, $response->body);

        $row = $this->taskRow($id);
        $this->assertSame($this->todoStatusId(), (int)$row['status_id']);
        $this->assertSame('hourly', $row['schedule_period'], 'The schedule itself must survive the reopen.');
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM task_assignees WHERE task_id=' . $id)->fetchColumn());

        $this->assertSame(1, $this->commentCount($id));
        $comment = TestServer::pdo()->query('SELECT body FROM comments WHERE task_id=' . $id)->fetchColumn();
        $this->assertSame('Reopen by auto-scheduler', $comment);

        $history = TestServer::pdo()->query("SELECT COUNT(*) FROM task_history WHERE task_id={$id} AND event='Reopened by auto-scheduler'")->fetchColumn();
        $this->assertSame('1', (string)$history);
    }

    public function test_cronjob_leaves_recent_scheduled_tasks_alone(): void
    {
        $admin = $this->adminSession();
        $id = $this->createScheduledTask($admin, 'Fresh task', 'weekly', 3, ['1']);

        $response = $admin->get('/?page=cronjob');
        $this->assertStringContainsStringIgnoringCase('nothing to reopen', $response->body);

        $row = $this->taskRow($id);
        $this->assertSame(3, (int)$row['status_id']);
        $this->assertSame(0, $this->commentCount($id));
        $this->assertSame('1', (string)TestServer::pdo()->query('SELECT COUNT(*) FROM task_assignees WHERE task_id=' . $id)->fetchColumn());
    }

    public function test_cronjob_skips_tasks_already_todo_and_unassigned(): void
    {
        $admin = $this->adminSession();
        $id = $this->createScheduledTask($admin, 'Waiting in the queue', 'daily', 1, []);
        $this->backdateTask($id, 'daily');

        $response = $admin->get('/?page=cronjob');
        $this->assertStringContainsStringIgnoringCase('nothing to reopen', $response->body);
        $this->assertSame(0, $this->commentCount($id), 'A TODO, unassigned task must not be spammed with reopen comments.');
        $this->assertSame($this->todoStatusId(), (int)$this->taskRow($id)['status_id']);
    }

    public function test_cronjob_reopens_a_stale_in_progress_task_and_unassigns_it(): void
    {
        $admin = $this->adminSession();
        $id = $this->createScheduledTask($admin, 'Stale work', 'monthly', 2, ['1']);
        $this->backdateTask($id, 'monthly');

        $response = $admin->get('/?page=cronjob');
        $this->assertStringContainsStringIgnoringCase('reopened 1 task', $response->body);

        $this->assertSame($this->todoStatusId(), (int)$this->taskRow($id)['status_id']);
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM task_assignees WHERE task_id=' . $id)->fetchColumn());
        $this->assertSame(1, $this->commentCount($id));
    }

    public function test_cronjob_ignores_stale_tasks_without_a_schedule(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Plain task');
        $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Plain task',
            'description' => '',
            'status_id' => '3',
            'parent_id' => '',
            'assignees' => ['1'],
        ]);
        $this->backdateTask($id, 'hourly'); // stale, but no schedule_period set

        $response = $admin->get('/?page=cronjob');
        $this->assertStringContainsStringIgnoringCase('nothing to reopen', $response->body);
        $this->assertSame(3, (int)$this->taskRow($id)['status_id']);
        $this->assertSame(0, $this->commentCount($id));
    }

    public function test_all_schedule_periods_reopen_when_overdue(): void
    {
        $admin = $this->adminSession();
        $ids = [];
        foreach (array_keys(self::MYSQL_INTERVALS) as $period) {
            $ids[$period] = $this->createScheduledTask($admin, 'Period ' . $period, $period, 3, []);
            $this->backdateTask($ids[$period], $period);
        }

        $response = $admin->get('/?page=cronjob');
        $this->assertStringContainsStringIgnoringCase('reopened 5 task', $response->body);
        foreach ($ids as $period => $id) {
            $this->assertSame($this->todoStatusId(), (int)$this->taskRow($id)['status_id'], "The {$period} task should have been reopened.");
            $this->assertSame(1, $this->commentCount($id));
        }
    }

    public function test_cronjob_runs_without_a_session(): void
    {
        $this->adminSession();
        $response = $this->session->get('/?page=cronjob'); // anonymous client
        $this->assertSame(200, $response->status, 'The cron controller must not redirect to the login page.');
        $this->assertSame('text/plain; charset=utf-8', $response->header('content-type'));
        $this->assertStringContainsStringIgnoringCase('auto-scheduler', $response->body);
    }

    public function test_the_form_persists_and_redisplays_the_reopen_targets(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Targeted recurrence');

        $save = $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Targeted recurrence',
            'description' => '',
            'status_id' => '3',
            'parent_id' => '',
            'schedule_period' => 'biweekly',
            'schedule_status_id' => '2',
            'schedule_assignees' => ['1'],
            'assignees' => [],
        ]);
        $this->assertSame(302, $save->status);

        $row = $this->taskRow($id);
        $this->assertSame(2, (int)$row['schedule_status_id'], 'The scheduled status must be stored.');
        $this->assertSame('1', (string)$row['schedule_assignees'], 'The scheduled assignee list must be stored as a csv.');

        // The edit form must offer the targets again on the next visit. The
        // task's own status is DONE here, so the selected value="2" can only
        // belong to the reopen status select.
        $edit = $admin->get('/?page=edit&id=' . $id);
        $this->assertTrue($edit->contains('Reopen status'));
        $this->assertTrue($edit->contains('Reopen assignees'));
        $this->assertTrue($edit->contains('value="2" selected'), 'The stored reopen status must be preselected.');
        $this->assertTrue($edit->contains('value="1" selected'), 'The stored reopen assignees must be preselected.');

        // The task page states the targets, so the schedule is not opaque.
        $page = $admin->taskPage($id);
        $this->assertTrue($page->contains('reopens to IN PROGRESS, assignees: <span class="text-nowrap">Administrator</span>'), 'the reopen targets render on one line per name (#63).');

        // Clearing the period clears the targets with the same save: one form.
        $clear = $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Targeted recurrence',
            'description' => '',
            'status_id' => '3',
            'parent_id' => '',
            'schedule_period' => '',
            'schedule_status_id' => '',
            'schedule_assignees' => [],
            'assignees' => [],
        ]);
        $this->assertSame(302, $clear->status);
        $row = $this->taskRow($id);
        $this->assertNull($row['schedule_status_id']);
        $this->assertNull($row['schedule_assignees']);
    }

    public function test_a_period_only_schedule_stores_no_targets(): void
    {
        // The #18 form posts only the period: the targets must stay NULL so the
        // cron keeps the exact legacy behavior for existing schedules.
        $admin = $this->adminSession();
        $id = $this->createScheduledTask($admin, 'Legacy schedule', 'daily', 3, ['1']);

        $row = $this->taskRow($id);
        $this->assertNull($row['schedule_status_id']);
        $this->assertNull($row['schedule_assignees']);
    }

    public function test_invalid_scheduler_targets_are_rejected(): void
    {
        $admin = $this->adminSession();
        $id = $admin->createTask('Target validation');
        $base = [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Target validation',
            'description' => '',
            'status_id' => '1',
            'parent_id' => '',
            'schedule_period' => 'weekly',
        ];

        $badStatus = $admin->post('/?page=edit&id=' . $id, $base + ['schedule_status_id' => '999', 'schedule_assignees' => []]);
        $this->assertSame(200, $badStatus->status);
        $this->assertTrue($badStatus->contains('Choose a valid schedule status.'));

        $badAssignee = $admin->post('/?page=edit&id=' . $id, $base + ['schedule_status_id' => '', 'schedule_assignees' => ['999']]);
        $this->assertSame(200, $badAssignee->status);
        $this->assertTrue($badAssignee->contains('Choose valid schedule assignees.'));

        // Nothing was stored for the rejected saves.
        $row = $this->taskRow($id);
        $this->assertNull($row['schedule_status_id']);
        $this->assertNull($row['schedule_assignees']);
    }

    public function test_cronjob_applies_the_scheduled_status_and_assignees(): void
    {
        $admin = $this->adminSession();
        $janeId = $this->createJane();
        $inProgressId = (int)TestServer::pdo()->query("SELECT id FROM statuses WHERE name='IN PROGRESS' ORDER BY id LIMIT 1")->fetchColumn();

        // DONE and assigned to the admin; scheduled to reopen to IN PROGRESS
        // with exactly Jane as the assignee.
        $id = $this->createScheduledTask($admin, 'Rotation task', 'hourly', 3, ['1'], $inProgressId, [$janeId]);
        $this->backdateTask($id, 'hourly');

        $response = $admin->get('/?page=cronjob');
        $this->assertStringContainsStringIgnoringCase('reopened 1 task', $response->body);

        $row = $this->taskRow($id);
        $this->assertSame($inProgressId, (int)$row['status_id'], 'The cron must apply the scheduled status.');
        $this->assertSame([$janeId], $this->assignedIds($id), 'The cron must replace the assignees with the scheduled list.');
        $this->assertSame('hourly', $row['schedule_period'], 'The schedule and its targets survive the reopen.');
        $this->assertSame($inProgressId, (int)$row['schedule_status_id']);

        $this->assertSame(1, $this->commentCount($id));
        $details = (string)TestServer::pdo()->query("SELECT details FROM task_history WHERE task_id={$id} AND event='Reopened by auto-scheduler'")->fetchColumn();
        $this->assertStringContainsString('IN PROGRESS', $details);
        $this->assertStringContainsString('Jane Doe', $details);
    }

    public function test_cronjob_skips_tasks_already_in_their_target_state(): void
    {
        $admin = $this->adminSession();
        $janeId = $this->createJane();
        $inProgressId = (int)TestServer::pdo()->query("SELECT id FROM statuses WHERE name='IN PROGRESS' ORDER BY id LIMIT 1")->fetchColumn();

        $id = $this->createScheduledTask($admin, 'Already on target', 'daily', 3, ['1'], $inProgressId, [$janeId]);
        // Push the task into its own target state through the real edit form.
        $response = $admin->post('/?page=edit&id=' . $id, [
            'action' => 'save_task',
            'id' => (string)$id,
            'title' => 'Already on target',
            'description' => '',
            'status_id' => (string)$inProgressId,
            'parent_id' => '',
            'schedule_period' => 'daily',
            'schedule_status_id' => (string)$inProgressId,
            'schedule_assignees' => [(string)$janeId],
            'assignees' => [(string)$janeId],
        ]);
        $this->assertSame(302, $response->status);
        $this->backdateTask($id, 'daily');

        $cron = $admin->get('/?page=cronjob');
        $this->assertStringContainsStringIgnoringCase('nothing to reopen', $cron->body);
        $this->assertSame(0, $this->commentCount($id), 'A task already in its target state must not be spammed with reopen comments.');
        $this->assertSame($inProgressId, (int)$this->taskRow($id)['status_id']);
        $this->assertSame([$janeId], $this->assignedIds($id));
    }
}
