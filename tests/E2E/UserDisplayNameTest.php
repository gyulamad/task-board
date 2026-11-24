<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #63: display names render on one line always, everywhere. The standard
 * name renderer tb_user_with_activity() wraps the activity dot and the name
 * in a single text-nowrap block, so a multi-word display name can never be
 * broken across lines by the surrounding layout; the scheduler line and the
 * messenger keep the same guarantee (v2, rebased on the #65 messenger).
 */
final class UserDisplayNameTest extends E2eTestCase
{
    private function createRegularUser(AppSession $admin, string $username): AppSession
    {
        $admin->post('/?page=users', [
            'action' => 'add_user',
            'username' => $username,
            'display_name' => ucfirst($username),
            'password' => $username . 'Pass12345',
            'role' => 'user',
        ]);
        return new AppSession($username, $username . 'Pass12345');
    }

    private function renameUser(AppSession $admin, int $userId, string $displayName): void
    {
        $admin->post('/?page=user_edit&id=' . $userId, [
            'action' => 'edit_user', 'id' => (string)$userId,
            'username' => 'wanda', 'display_name' => $displayName, 'role' => 'user',
        ]);
    }

    private function userIdFromName(string $username): int
    {
        $s = TestServer::pdo()->prepare('SELECT id FROM users WHERE username=?');
        $s->execute([$username]);
        return (int)$s->fetchColumn();
    }

    public function test_display_names_render_in_one_no_wrap_block_everywhere(): void
    {
        $admin = $this->adminSession();
        $wanda = $this->createRegularUser($admin, 'wanda');
        $this->renameUser($admin, $this->userIdFromName('wanda'), 'Wanda Nomove');
        $taskId = $admin->createTask('No-wrap task', ['assignees' => [$this->userIdFromName('wanda')]]);

        // The navbar and the task list pin the name into the same no-wrap
        // block as its activity dot.
        $board = $admin->get('/?');
        $this->assertMatchesRegularExpression(
            '/<span class="text-nowrap"><span class="tb-activity-dot[^"]*"[^>]*><\/span>Wanda Nomove<\/span>/',
            $board->body,
            'the name sits in the same no-wrap block as its dot'
        );
        $this->assertTrue(substr_count($board->body, '<span class="text-nowrap">') >= 2, 'every rendered name uses the no-wrap block');

        // The task page: creator, assignees and the comment authors all go
        // through the same renderer.
        $task = $admin->get('/?page=task&id=' . $taskId);
        $this->assertTrue($task->contains('Created by <span class="text-nowrap">'));
        $this->assertTrue($task->contains('Assignees:</strong> <span class="text-nowrap">'));

        // The kanban card assignee line and the messenger people list carry
        // the same block.
        $kanban = $admin->get('/?page=kanban');
        $this->assertTrue($kanban->contains('<span class="text-nowrap">'), 'kanban cards render names through the no-wrap block.');
        $messages = $admin->get('/?page=messages');
        $this->assertTrue($messages->contains('href="?page=messages&u=' . $this->userIdFromName('wanda') . '"'), 'the messenger people list links the user.');
        $this->assertTrue($messages->contains('<span class="text-nowrap">'), 'the messenger renders names through the no-wrap block.');
    }
}
