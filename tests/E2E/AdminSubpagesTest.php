<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #42: the Administration page is split into separate Users and Task
 * statuses pages, and every task status gets an optional colour that renders
 * on all status badges of the board.
 *
 * Page separation: the hub (?page=admin) links to ?page=users and
 * ?page=statuses; both are admin-only and answer 403 to everyone else, like
 * the other dedicated admin pages (?page=activity_settings, ?page=updates).
 *
 * Status colours: stored as #rrggbb in statuses.color (NULL = the default
 * badge look the board has always had), edited through a colour picker plus a
 * "Default colour" checkbox on the Task statuses page, and rendered by
 * tb_status_badge() with an automatically readable text colour (dark text on
 * light fills, white text on dark ones).
 */
final class AdminSubpagesTest extends E2eTestCase
{
    /** A non-admin account for the permission tests. */
    private function regularUser(): AppSession
    {
        $admin = $this->adminSession();
        $admin->post('/?page=users', [
            'action' => 'add_user',
            'username' => 'worker',
            'display_name' => 'Worker',
            'password' => 'WorkerPass1234',
            'role' => 'user',
        ]);
        return new AppSession('worker', 'WorkerPass1234');
    }

    private function statusColor(int $id): ?string
    {
        $q = TestServer::pdo()->prepare('SELECT color FROM statuses WHERE id=?');
        $q->execute([$id]);
        $color = $q->fetchColumn();
        $q->closeCursor();
        return $color === false || $color === null ? null : (string)$color;
    }

    /** Creates a project through the real form and returns its id. */
    private function createProject(AppSession $session, string $name): int
    {
        $session->get('/?page=project_new');
        $response = $session->post('/?page=project_new', [
            'action' => 'save_project', 'id' => '0', 'name' => $name,
            'description' => 'Created by the test suite', 'status_id' => '1', 'assignees' => [],
        ]);
        if (!preg_match('/page=project&id=(\d+)/', (string)$response->header('location'), $m)) {
            throw new \RuntimeException('Project creation did not redirect to the project page.');
        }
        return (int)$m[1];
    }

    /** Creates a task already assigned to a project. */
    private function createTaskInProject(AppSession $session, string $title, int $projectId): int
    {
        $session->get('/?page=new');
        $response = $session->post('/?page=new', [
            'action' => 'save_task', 'id' => '0', 'title' => $title,
            'description' => 'Created by the test suite', 'status_id' => '1',
            'parent_id' => '', 'project_id' => (string)$projectId, 'assignees' => [],
        ]);
        if (!preg_match('/page=task&id=(\d+)/', (string)$response->header('location'), $m)) {
            throw new \RuntimeException('Task creation did not redirect to the task page.');
        }
        return (int)$m[1];
    }

    public function test_regular_users_get_forbidden_on_the_separated_pages(): void
    {
        $worker = $this->regularUser();
        foreach (['users', 'statuses'] as $pageName) {
            $response = $worker->get('/?page=' . $pageName);
            $this->assertSame(403, $response->status, '?page=' . $pageName . ' is admin-only.');
            $this->assertTrue($response->contains('Forbidden.'));
        }
        // The admin keeps full access to both pages.
        $admin = $this->adminSession();
        foreach (['users', 'statuses'] as $pageName) {
            $this->assertSame(200, $admin->get('/?page=' . $pageName)->status);
        }
    }

    public function test_user_actions_redirect_back_to_the_users_page(): void
    {
        $admin = $this->adminSession();
        $created = $admin->post('/?page=users', [
            'action' => 'add_user',
            'username' => 'dana',
            'display_name' => 'Dana User',
            'password' => 'DanaPass12345',
            'role' => 'user',
        ]);
        $this->assertStringContainsString('?page=users', (string)$created->header('location'));

        $id = (int)TestServer::pdo()->query("SELECT id FROM users WHERE username='dana'")->fetchColumn();
        $updated = $admin->post('/?page=users', [
            'action' => 'edit_user', 'id' => (string)$id,
            'username' => 'dana', 'display_name' => 'Dana Renamed', 'role' => 'user',
        ]);
        $this->assertStringContainsString('?page=users', (string)$updated->header('location'));

        $deleted = $admin->post('/?page=users', ['action' => 'delete_user', 'id' => (string)$id]);
        $this->assertStringContainsString('?page=users', (string)$deleted->header('location'));
    }

    public function test_status_actions_redirect_back_to_the_statuses_page(): void
    {
        $admin = $this->adminSession();
        $added = $admin->post('/?page=statuses', ['action' => 'add_status', 'name' => 'BLOCKED']);
        $this->assertStringContainsString('?page=statuses', (string)$added->header('location'));

        $id = (int)TestServer::pdo()->query("SELECT id FROM statuses WHERE name='BLOCKED'")->fetchColumn();
        $updated = $admin->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => (string)$id, 'name' => 'STOPPED', 'sort_order' => '4',
        ]);
        $this->assertStringContainsString('?page=statuses', (string)$updated->header('location'));

        $deleted = $admin->post('/?page=statuses', ['action' => 'delete_status', 'id' => (string)$id]);
        $this->assertStringContainsString('?page=statuses', (string)$deleted->header('location'));
    }

    public function test_a_new_status_can_carry_a_colour(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=statuses', ['action' => 'add_status', 'name' => 'REVIEW', 'color' => '#0000FF']);
        $id = (int)TestServer::pdo()->query("SELECT id FROM statuses WHERE name='REVIEW'")->fetchColumn();
        $this->assertSame('#0000ff', $this->statusColor($id), 'The colour is stored normalized in lowercase.');

        // Dark fill: the badge renders with a white text colour for readability.
        $admin->createTask('Colour on the list', ['status_id' => (string)$id]);
        $list = $admin->get('/?');
        $this->assertStringContainsString(
            '<span class="badge border" style="background-color:#0000ff;color:#ffffff">REVIEW</span>',
            $list->body,
            'The task list renders the coloured badge.'
        );
    }

    public function test_a_new_status_without_a_colour_keeps_the_default_look(): void
    {
        $admin = $this->adminSession();
        // The "Default colour" checkbox clears the picker value.
        $admin->post('/?page=statuses', ['action' => 'add_status', 'name' => 'REVIEW', 'color' => '#0000FF', 'color_default' => '1']);
        $id = (int)TestServer::pdo()->query("SELECT id FROM statuses WHERE name='REVIEW'")->fetchColumn();
        $this->assertNull($this->statusColor($id));

        $admin->createTask('Default look on the list', ['status_id' => (string)$id]);
        $list = $admin->get('/?');
        $this->assertStringContainsString(
            '<span class="badge text-bg-light border">REVIEW</span>',
            $list->body,
            'Without a colour the badge looks exactly like before #42.'
        );
    }

    public function test_an_existing_status_can_be_coloured_and_cleared(): void
    {
        $admin = $this->adminSession();
        $id = (int)TestServer::pdo()->query("SELECT id FROM statuses WHERE name='TODO'")->fetchColumn();

        $admin->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => (string)$id,
            'name' => 'TODO', 'sort_order' => '1', 'color' => '#FFC107',
        ]);
        $this->assertSame('#ffc107', $this->statusColor($id));

        // Light fill: the badge picks the dark text colour automatically.
        $taskPage = $admin->taskPage($admin->createTask('Coloured TODO'));
        $this->assertStringContainsString(
            '<span class="badge border" style="background-color:#ffc107;color:#1a1a1a">TODO</span>',
            $taskPage->body,
            'The task page header renders the coloured badge with dark text.'
        );

        // The "Default colour" checkbox clears the stored colour again.
        $admin->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => (string)$id,
            'name' => 'TODO', 'sort_order' => '1', 'color' => '#FFC107', 'color_default' => '1',
        ]);
        $this->assertNull($this->statusColor($id));
        $this->assertStringContainsString(
            '<span class="badge text-bg-light border">TODO</span>',
            $admin->get('/?')->body,
            'Cleared colour restores the built-in badge look.'
        );
    }

    public function test_an_invalid_status_colour_is_rejected_and_keeps_the_old_value(): void
    {
        $admin = $this->adminSession();
        $id = (int)TestServer::pdo()->query("SELECT id FROM statuses WHERE name='TODO'")->fetchColumn();

        foreach (['not-a-colour', '#12345', 'ff0000', '#12345g', '#ff00'] as $bad) {
            $response = $admin->post('/?page=statuses', [
                'action' => 'edit_status', 'id' => (string)$id,
                'name' => 'TODO', 'sort_order' => '1', 'color' => $bad,
            ]);
            $this->assertSame(200, $response->status);
            $this->assertTrue(
                $response->contains('Status colour must be a #RRGGBB hex value, or empty for the default look.'),
                'Colour ' . $bad . ' is rejected with a clear message.'
            );
        }
        $this->assertNull($this->statusColor($id), 'A rejected colour is never stored.');
    }

    public function test_the_colour_renders_on_every_status_badge_of_the_board(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=statuses', [
            'action' => 'edit_status', 'id' => '1',
            'name' => 'TODO', 'sort_order' => '1', 'color' => '#0D6EFD',
        ]);
        $taskId = $admin->createTask('Badge everywhere');

        $expected = '<span class="badge border" style="background-color:#0d6efd;color:#ffffff">TODO</span>';
        // Task list and task page.
        $this->assertStringContainsString($expected, $admin->get('/?')->body, 'Task list badge.');
        $this->assertStringContainsString($expected, $admin->taskPage($taskId)->body, 'Task page badge.');

        // Project page: the project itself and its task rows.
        $projectId = $this->createProject($admin, 'Coloured project');
        $projectTaskId = $this->createTaskInProject($admin, 'Badge in project', $projectId);
        $projectPage = $admin->get('/?page=project&id=' . $projectId);
        $this->assertStringContainsString($expected, $projectPage->body, 'Project page header badge.');
        $this->assertStringContainsString($expected, $admin->get('/?page=projects')->body, 'Projects list badge.');

        // Archive page.
        $admin->post('/?page=task&id=' . $projectTaskId, ['action' => 'archive_task', 'id' => (string)$projectTaskId, 'return' => '?']);
        $this->assertStringContainsString($expected, $admin->get('/?page=archive')->body, 'Archive badge.');
    }
}
