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
}
