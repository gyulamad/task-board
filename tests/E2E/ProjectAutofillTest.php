<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #130: wherever the board lets the operator pick exactly one project -
 * the project filter of the task list, the kanban board or the waterflow
 * chart, or an open project page - the create form's project selector starts
 * preselected with it. The pick rides in the session, the #113 replay counts
 * like the visitor's own URL, and everything that is not a single real
 * project clears it, so the form never silently preselects a project the
 * operator did not choose last. A clone and an edit keep their own task's
 * project - the autofill belongs to the fresh create form only.
 */
final class ProjectAutofillTest extends E2eTestCase
{
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

    /** The project id the create form's selector preselects, or null. */
    private function selectedProject(AppSession $session): ?string
    {
        return $this->selectedOptionIn($this->projectSelectHtml($this->formBody($session)));
    }

    private function formBody(AppSession $session): string
    {
        $page = $session->get('/?page=new');
        $this->assertSame(200, $page->status);
        return $page->body;
    }

    /** The option list of the create form's project selector only - the page
     * carries other selects (status ids, parent task ids) whose values can
     * numerically collide with fresh project ids, so the assertions must
     * never read across them. */
    private function projectSelectHtml(string $body): string
    {
        if (preg_match('/name="project_id" id="task-project">(.*)<\/select>/s', $body, $m)) {
            return $m[1];
        }
        return '';
    }

    private function selectedOptionIn(string $selectHtml): ?string
    {
        if (preg_match('/<option value="(\d+)" selected>/', $selectHtml, $m)) {
            return $m[1];
        }
        return null;
    }

    public function test_a_single_filtered_project_prefills_the_create_form(): void
    {
        $admin = $this->adminSession();
        $alpha = $this->createProject($admin, 'Autofill alpha');
        $beta = $this->createProject($admin, 'Autofill beta');

        // A pristine visitor gets no preselection at all.
        $this->assertNull($this->selectedProject($admin), 'No project preselected without a pick.');

        // One project on the task list filter preselects the create form.
        $this->assertSame(200, $admin->get('/?page=tasks&project%5B0%5D=' . $alpha)->status);
        $this->assertSame((string)$alpha, $this->selectedProject($admin), 'The filtered project preselects the create form.');

        // The kanban and waterflow filters feed the same session pick.
        $this->assertSame(200, $admin->get('/?page=kanban&project%5B0%5D=' . $beta)->status);
        $this->assertSame((string)$beta, $this->selectedProject($admin), 'The kanban filter is the same pick.');
        $this->assertSame(200, $admin->get('/?page=waterflow&project%5B0%5D=' . $alpha)->status);
        $this->assertSame((string)$alpha, $this->selectedProject($admin), 'The waterflow filter is the same pick.');

        // Opening a project page is a pick of its own.
        $this->assertSame(200, $admin->get('/?page=project&id=' . $beta)->status);
        $this->assertSame((string)$beta, $this->selectedProject($admin), 'The open project page preselects the create form.');

        // The pick survives a trip elsewhere: the stored list view replays
        // (#113) and the replayed filter is the same single project.
        $this->assertSame(200, $admin->get('/?page=tasks&project%5B0%5D=' . $alpha)->status);
        $this->assertSame(200, $admin->get('/?page=task&id=' . $admin->createTask('A task to visit'))->status);
        $this->assertSame((string)$alpha, $this->selectedProject($admin), 'Coming back to the bare list replays the filtered view and keeps the pick.');
    }

    public function test_multiple_or_no_project_clears_the_stored_pick(): void
    {
        $admin = $this->adminSession();
        $alpha = $this->createProject($admin, 'Autofill gamma');
        $beta = $this->createProject($admin, 'Autofill delta');

        $this->assertSame(200, $admin->get('/?page=tasks&project%5B0%5D=' . $alpha)->status);
        $this->assertSame((string)$alpha, $this->selectedProject($admin));

        // Several projects at once is not a pick: the form preselects nothing.
        $this->assertSame(200, $admin->get('/?page=tasks&project%5B0%5D=' . $alpha . '&project%5B1%5D=' . $beta)->status);
        $this->assertNull($this->selectedProject($admin), 'A multi-project filter clears the pick.');

        // Back to one project, then the explicit clear: the form forgets.
        $this->assertSame(200, $admin->get('/?page=tasks&project%5B0%5D=' . $alpha)->status);
        $this->assertSame((string)$alpha, $this->selectedProject($admin));
        $this->assertSame(200, $admin->get('/?page=tasks&clear_search=1')->status);
        $this->assertNull($this->selectedProject($admin), 'The Clear search drop clears the pick too.');

        // The "No project" pseudo-value is a filter, not a project pick.
        $this->assertSame(200, $admin->get('/?page=tasks&project%5B0%5D=none')->status);
        $this->assertNull($this->selectedProject($admin), 'Filtering for project-less tasks preselects nothing.');

        // An unknown project id never stores a pick either.
        $this->assertSame(200, $admin->get('/?page=tasks&project%5B0%5D=9999')->status);
        $this->assertNull($this->selectedProject($admin), 'A filter for a deleted project preselects nothing.');
    }

    public function test_clone_and_edit_keep_their_own_project(): void
    {
        $admin = $this->adminSession();
        $alpha = $this->createProject($admin, 'Autofill epsilon');
        $beta = $this->createProject($admin, 'Autofill zeta');

        // A task with a project of its own, created through the real form.
        $admin->get('/?page=new');
        $response = $admin->post('/?page=new', [
            'action' => 'save_task', 'id' => '0', 'title' => 'Clone source with a project',
            'description' => 'The clone and the edit keep this project', 'status_id' => '1',
            'parent_id' => '', 'project_id' => (string)$beta, 'assignees' => [],
        ]);
        $this->assertSame(1, preg_match('/page=task&id=(\d+)/', (string)$response->header('location'), $m), 'The task was created.');
        $taskId = (int)$m[1];

        // A filtered pick is stored...
        $this->assertSame(200, $admin->get('/?page=tasks&project%5B0%5D=' . $alpha)->status);

        // ...but the clone keeps the source task's project, not the pick.
        $clone = $admin->get('/?page=new&clone=' . $taskId);
        $this->assertSame(200, $clone->status);
        $cloneSelect = $this->projectSelectHtml($clone->body);
        $this->assertTrue(str_contains($cloneSelect, '<option value="' . $beta . '" selected>'), 'The clone prefills the source task\'s project (#46).');
        $this->assertFalse(str_contains($cloneSelect, '<option value="' . $alpha . '" selected>'), 'The clone ignores the stored pick.');

        // And the edit form keeps the task's own project.
        $edit = $admin->get('/?page=edit&id=' . $taskId);
        $this->assertSame(200, $edit->status);
        $editSelect = $this->projectSelectHtml($edit->body);
        $this->assertTrue(str_contains($editSelect, '<option value="' . $beta . '" selected>'), 'The edit form keeps the task\'s project.');
        $this->assertFalse(str_contains($editSelect, '<option value="' . $alpha . '" selected>'), 'The edit form ignores the stored pick.');
    }
}
