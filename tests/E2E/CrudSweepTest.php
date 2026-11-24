<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

/**
 * Task #86 (the #57-F final CRUD sweep): the standing re-test of every page
 * the #57 refactor migrated. Every entity list runs on the shared CRUD
 * engine, so this walks each v2 page AND its classic alias over real HTTP
 * for an administrator and a regular user, asserting the page renders (HTTP
 * 200, no PHP warnings or fatals, a page-specific chrome marker). The
 * per-feature test classes pin the details; this class pins that the whole
 * migrated surface stays up and identical between the URL pairs.
 */
final class CrudSweepTest extends E2eTestCase
{
    /** @return array<string,string> page path => a marker that must be in the body */
    private function pages(): array
    {
        return [
            '/?page=tasks-v2' => 'New task',
            '/?page=tasks' => 'New task',
            '/?page=kanban' => 'Task list',
            '/?page=waterflow' => 'Task list',
            '/?page=projects-v2' => 'New project',
            '/?page=projects' => 'New project',
            '/?page=templates-v2' => 'Templates',
            '/?page=templates' => 'Templates',
            '/?page=documents-v2' => 'Know-hows',
            '/?page=documents' => 'Know-hows',
            '/?page=archive-v2' => 'Archive',
            '/?page=archive' => 'Archive',
            '/?page=messages-v2' => 'data-tb-chat-scroll',
            '/?page=messages' => 'data-tb-chat-scroll',
            '/?page=notifications-v2' => 'Notifications',
            '/?page=notifications' => 'Notifications',
            '/?page=users-v2' => 'All users',
            '/?page=users' => 'All users',
        ];
    }

    /** Creates a second user through the real admin form, so the messages
     * people list has a peer whose conversation can auto-select. */
    private function createSecondUser(string $username): void
    {
        $response = $this->adminSession()->post('/?page=users-v2', [
            'action' => 'add_user',
            'username' => $username,
            'display_name' => ucfirst($username),
            'password' => $username . 'Pass12345',
            'role' => 'user',
        ]);
        $this->assertSame(302, $response->status, 'Creating the sweep companion user must succeed.');
    }

    public function test_the_administrator_reaches_every_migrated_page_and_alias_without_warnings(): void
    {
        $this->adminSession();
        $this->createSecondUser('sweeppeer');
        foreach ($this->pages() as $path => $marker) {
            $response = $this->admin->get($path);
            $this->assertSame(200, $response->status, $path);
            $this->assertStringNotContainsString('Warning:', $response->body, $path);
            $this->assertStringNotContainsString('Fatal error', $response->body, $path);
            $this->assertStringNotContainsString('Deprecated:', $response->body, $path);
            $this->assertTrue($response->contains($marker), $path . ' must render its chrome (' . $marker . ')');
        }
    }

    public function test_a_regular_user_reaches_the_shared_list_pages_through_both_urls(): void
    {
        $this->createSecondUser('worker');
        $worker = new \TaskBoard\Tests\Support\AppSession('worker', 'workerPass12345');
        foreach (['/?page=tasks-v2', '/?page=tasks', '/?page=kanban', '/?page=waterflow',
                      '/?page=projects-v2', '/?page=projects', '/?page=templates-v2', '/?page=templates',
                      '/?page=documents-v2', '/?page=documents', '/?page=messages-v2', '/?page=messages',
                      '/?page=notifications-v2', '/?page=notifications'] as $path) {
            $response = $worker->get($path);
            $this->assertSame(200, $response->status, $path);
            $this->assertStringNotContainsString('Warning:', $response->body, $path);
            $this->assertStringNotContainsString('Fatal error', $response->body, $path);
        }
    }

    public function test_the_engine_carries_no_classic_page_links_any_more(): void
    {
        // Step 2 of the sweep (switch all remaining links) finished with the
        // #85 part-2 delivery: the deployed markup must hold no entry links
        // to the classic list URLs - the aliases exist, but nothing links to
        // them any more.
        $index = (string)file_get_contents(dirname(__DIR__, 2) . '/index.php');
        $this->assertSame(0, preg_match_all('/href="[^"]*page=(tasks|users|projects|templates|documents|archive|messages|notifications)[&"]/', $index), 'no rendered link may target a classic list URL');
    }
}
