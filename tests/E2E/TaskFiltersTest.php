<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

final class TaskFiltersTest extends E2eTestCase
{
    private function seedTasks(): array
    {
        $admin = $this->adminSession();
        $alpha = $admin->createTask('Alpha search token', ['description' => 'unique description zebra', 'status_id' => '1']);
        $beta = $admin->createTask('Beta item', ['description' => 'Alpha appears in this description', 'status_id' => '2']);
        $gamma = $admin->createTask('Gamma item', ['status_id' => '3']);
        $admin->post('/?page=edit&id=' . $gamma, [
            'action' => 'save_task',
            'id' => (string)$gamma,
            'title' => 'Gamma item',
            'description' => 'Gamma description',
            'status_id' => '3',
            'parent_id' => '',
            'assignees' => ['1'],
        ]);
        return [$admin, $alpha, $beta, $gamma];
    }

    public function test_status_filter_returns_only_matching_tasks(): void
    {
        [$admin, $alpha, $beta, $gamma] = $this->seedTasks();

        $todo = $admin->get('/?page=tasks&status=1');
        $this->assertTrue($todo->contains('#' . $alpha . ' · Alpha search token'));
        $this->assertFalse($todo->contains('#' . $beta . ' · Beta item'));
        $this->assertFalse($todo->contains('#' . $gamma . ' · Gamma item'));

        $done = $admin->get('/?page=tasks&status=3');
        $this->assertTrue($done->contains('#' . $gamma . ' · Gamma item'));
        $this->assertFalse($done->contains('#' . $alpha . ' · Alpha search token'));
    }

    public function test_assignee_filter_returns_only_assigned_tasks(): void
    {
        [$admin, $alpha, $beta, $gamma] = $this->seedTasks();

        $assigned = $admin->get('/?page=tasks&assignee=1');
        $this->assertTrue($assigned->contains('#' . $gamma . ' · Gamma item'));
        $this->assertFalse($assigned->contains('#' . $alpha . ' · Alpha search token'));
        $this->assertFalse($assigned->contains('#' . $beta . ' · Beta item'));
    }

    public function test_search_matches_title_and_description(): void
    {
        [$admin, $alpha, $beta] = $this->seedTasks();

        $byTitle = $admin->get('/?page=tasks&q=Alpha+search');
        $this->assertTrue($byTitle->contains('#' . $alpha . ' · Alpha search token'));
        $this->assertFalse($byTitle->contains('#' . $beta . ' · Beta item'));

        // "zebra" only exists in Alpha's description; "Alpha appears" only in Beta's.
        $byDescription = $admin->get('/?page=tasks&q=zebra');
        $this->assertTrue($byDescription->contains('#' . $alpha . ' · Alpha search token'));
        $this->assertFalse($byDescription->contains('#' . $beta . ' · Beta item'));

        $betaViaDescription = $admin->get('/?page=tasks&q=appears+in+this');
        $this->assertTrue($betaViaDescription->contains('#' . $beta . ' · Beta item'));
        $this->assertFalse($betaViaDescription->contains('#' . $alpha . ' · Alpha search token'));
    }

    public function test_filters_can_be_combined(): void
    {
        [$admin, $alpha, $beta, $gamma] = $this->seedTasks();

        $combined = $admin->get('/?page=tasks&status=2&q=Beta');
        $this->assertTrue($combined->contains('#' . $beta . ' · Beta item'));
        $this->assertFalse($combined->contains('#' . $alpha . ' · Alpha search token'));
        $this->assertFalse($combined->contains('#' . $gamma . ' · Gamma item'));
    }

    public function test_search_with_no_hits_shows_the_empty_state(): void
    {
        $admin = $this->adminSession();
        $this->seedTasks();
        $none = $admin->get('/?page=tasks&q=does-not-exist-anywhere');
        $this->assertTrue($none->contains('No tasks found.'));
        $this->assertFalse($none->contains('page=task&id='));
    }
}
