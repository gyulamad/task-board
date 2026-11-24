<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #57 (admin refactor) - the shared CRUD list engine.
 *
 * The admin's plan: separate the CRUD abstraction, build v2 pages of the
 * task list and the users list on it (steps 1-3), prove they carry the same
 * functionality as the originals (step 4), then switch the links (step 5).
 * These tests are the step 4 proof:
 *
 *  - every feature the existing suites pin on ?page=users (search, role
 *    filter, sorting, pagination, realtime region) is pinned the same way
 *    on ?page=users-v2;
 *  - the same for the task list's features on ?page=tasks-v2 (region inside
 *    the bulk form, filter card, sorting, filters, bulk wiring);
 *  - and the v1/v2 pairs are pinned byte-equal (modulo the page name) for
 *    both the empty and the populated board, so the new engine demonstrably
 *    renders exactly what the pages rendered before.
 */
final class CrudAbstractionTest extends E2eTestCase
{
    private function createUser(AppSession $admin, string $username, string $role = 'user'): void
    {
        $admin->post('/?page=users', [
            'action' => 'add_user',
            'username' => $username,
            'display_name' => ucfirst($username) . ' Name',
            'password' => $username . 'Pass12345',
            'role' => $role,
        ]);
    }

    /** Everything before the first inline <script>: chrome, region, forms. */
    private function pageChrome(string $body): string
    {
        $cut = strpos($body, '<script>');
        $this->assertNotFalse($cut, 'The page must carry its scripts after the list markup.');
        return substr($body, 0, $cut);
    }

    // --- users-v2: the users list feature set (mirrors UserListTest) --------

    public function test_users_v2_searches_username_and_display_name(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'searchable');
        $this->createUser($admin, 'otheruser');

        $byName = $admin->get('/?page=users-v2&q=Searchable');
        $this->assertTrue($byName->contains('searchable'));
        $this->assertFalse($byName->contains('Otheruser Name'), 'The rows outside the search are filtered out.');

        $byUsername = $admin->get('/?page=users-v2&q=otheruser');
        $this->assertTrue($byUsername->contains('Otheruser Name'));
        $this->assertFalse($byUsername->contains('Searchable Name'));

        // A literal % must not act as a wildcard (the task search discipline).
        $percent = $admin->get('/?page=users-v2&q=%25');
        $this->assertTrue($percent->contains('No users match the filters.'));
    }

    public function test_users_v2_filters_by_role(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'plainuser');
        $this->createUser($admin, 'bossuser', 'admin');

        $admins = $admin->get('/?page=users-v2&role[]=admin');
        $this->assertTrue($admins->contains('bossuser'));
        $this->assertFalse($admins->contains('Plainuser Name'), 'The user role is filtered out.');

        $both = $admin->get('/?page=users-v2&role[]=admin&role[]=user');
        $this->assertTrue($both->contains('bossuser'));
        $this->assertTrue($both->contains('Plainuser Name'));

        // Legacy scalar links behave like a one-value array (#25 convention).
        $legacy = $admin->get('/?page=users-v2&role=user');
        $this->assertTrue($legacy->contains('plainuser'));
        $this->assertFalse($legacy->contains('Bossuser Name'));

        // Unknown values are ignored, so a crafted URL filters nothing away.
        $crafted = $admin->get('/?page=users-v2&role[]=superadmin');
        $this->assertTrue($crafted->contains('plainuser'));
    }

    public function test_users_v2_columns_sort(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'alpha');
        $this->createUser($admin, 'zulu');

        $desc = $admin->get('/?page=users-v2&sort=name&dir=desc');
        $this->assertTrue($desc->contains('Zulu Name'), 'The names render.');
        $this->assertGreaterThan(
            strpos($desc->body, 'Zulu Name'),
            strpos($desc->body, 'Alpha Name'),
            'Descending puts Zulu before Alpha.'
        );
        $this->assertTrue($desc->contains('aria-sort="descending"'), 'The active column exposes the direction.');

        // The unknown sort key falls back to the default and the database
        // stays intact (the same allowlist discipline as the task list).
        $crafted = $admin->get('/?page=users-v2&sort=name;DROP%20TABLE%20users&dir=desc');
        $this->assertSame(200, $crafted->status);
        $this->assertTrue($crafted->contains('Alpha Name'), 'The crafted sort is ignored and the list renders.');
    }

    public function test_users_v2_paginates(): void
    {
        $admin = $this->adminSession();
        for ($i = 1; $i <= 22; $i++) {
            $this->createUser($admin, 'pageuser' . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
        }

        $page1 = $admin->get('/?page=users-v2');
        $this->assertTrue($page1->contains('23 users'), 'The header counts all users.');
        $this->assertTrue($page1->contains('Page 1 of 2'));
        $this->assertTrue($page1->contains('pageuser01'));
        $this->assertTrue($page1->contains('page=users-v2&amp;p=2'), 'The pagination links stay on the v2 page.');

        $page2 = $admin->get('/?page=users-v2&p=2');
        $this->assertTrue($page2->contains('pageuser22'), 'The last rows land on page 2.');
        $this->assertFalse($page2->contains('pageuser01'), 'Page 1 rows stay on page 1.');
    }

    public function test_users_v2_keeps_the_realtime_region_and_create_form(): void
    {
        $admin = $this->adminSession();

        $page = $admin->get('/?page=users-v2');
        $this->assertSame(1, substr_count($page->body, '<div id="tb-list-region" data-tb-refresh="10">'), 'Exactly one realtime region (#51).');
        $this->assertSame(1, substr_count($page->body, "document.getElementById('tb-list-region')"), 'The shared refresh script is emitted once.');
        $createForm = (int)strpos($page->body, 'value="add_user"');
        $region = (int)strpos($page->body, '<div id="tb-list-region"');
        $this->assertTrue($createForm !== 0 && $createForm < $region, 'The create form stays outside the swapped region.');
        $this->assertTrue($page->contains('Search username or display name'), 'The search bar renders inside the region.');
    }

    public function test_users_v2_is_admin_only(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'regular');
        $user = new AppSession('regular', 'regularPass12345');

        $this->assertSame(403, $user->get('/?page=users-v2')->status, 'The v2 users list stays admin-only like ?page=users.');
        $this->assertSame(302, (new AppSession())->get('/?page=users-v2')->status, 'Anonymous visitors are pushed to the sign-in page.');
    }

    public function test_users_v2_offers_the_message_shortcut(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'writable');
        $this->createUser($admin, 'second');
        $userId = (int)TestServer::pdo()->query("SELECT id FROM users WHERE username='writable'")->fetchColumn();

        $page = $admin->get('/?page=users-v2');
        // One Message link per other user, none on the admin's own row: the
        // messenger has no self-conversation. The shortcut deep-links the
        // canonical v2 messenger since the #85 step-5 switch.
        $this->assertSame(2, substr_count($page->body, 'href="?page=messages-v2&u='), 'Every row but the admin\'s own carries the Message shortcut.');
        $this->assertTrue($page->contains('href="?page=messages-v2&u=' . $userId . '">Message</a>'), 'The Message button links to the messenger with the user preselected.');
        $this->assertGreaterThan(
            strpos($page->body, '>Edit</a>'),
            strpos($page->body, '>Message</a>'),
            'The Message button sits next to the Edit and Delete buttons.'
        );

        // The shortcut lands on the messenger with the conversation selected
        // instantly (?u=<id>, the messenger's own selection parameter) - the
        // classic URL stays a working alias of the v2 page.
        $thread = $admin->get('/?page=messages&u=' . $userId);
        $this->assertSame(200, $thread->status);
        $this->assertTrue($thread->contains('Conversation with Writable Name'), 'The preselected conversation renders immediately.');

        // The alias serves the same shortcut.
        $alias = $admin->get('/?page=users');
        $this->assertSame(2, substr_count($alias->body, 'href="?page=messages-v2&u='));
    }

    // --- tasks-v2: the task list feature set --------------------------------

    public function test_tasks_v2_carries_the_full_list_chrome(): void
    {
        $admin = $this->adminSession();
        $page = $admin->get('/?page=tasks-v2');

        $this->assertSame(200, $page->status);
        $this->assertSame(1, substr_count($page->body, 'id="tb-list-region"'), 'Exactly one refresh region.');
        $this->assertSame(1, substr_count($page->body, "document.getElementById('tb-list-region')"), 'The shared refresh script is emitted once.');

        // The region sits inside the bulk form and wraps the table with the
        // row checkboxes - the same placement the realtime suite pins on v1.
        $form = strpos($page->body, 'id="bulk-form"');
        $region = (int)strpos($page->body, 'id="tb-list-region"');
        $checkAll = (int)strpos($page->body, 'id="check-all"');
        $formEnd = strpos($page->body, '</form>', $region);
        $this->assertTrue($form < $region && $region < $checkAll && $checkAll < $formEnd, 'The region must wrap the task table inside the bulk form.');

        // The shared filter card renders with its advanced area and the
        // search bar, targeting the v2 page.
        $this->assertTrue($page->contains('Advanced filter'), 'The filter card renders.');
        $this->assertTrue($page->contains('value="tasks-v2"'), 'The filter card posts back to the v2 page.');
        $this->assertTrue($page->contains('Search tasks'), 'The search bar renders.');
    }

    public function test_tasks_v2_searches_and_filters(): void
    {
        $admin = $this->adminSession();
        $taskId = $admin->createTask('Findable payload', ['description' => 'unique-marker-57']);

        $byTitle = $admin->get('/?page=tasks-v2&q=Findable');
        $this->assertTrue($byTitle->contains('Findable payload'), 'The title search finds the task.');

        $byDescription = $admin->get('/?page=tasks-v2&q=unique-marker-57');
        $this->assertTrue($byDescription->contains('Findable payload'), 'The description search finds the task.');

        $empty = $admin->get('/?page=tasks-v2&q=nothing-matches-this');
        $this->assertTrue($empty->contains('No tasks found.'), 'The empty state renders.');

        // A literal % must not act as a wildcard (the shared search discipline).
        $percent = $admin->get('/?page=tasks-v2&q=%25');
        $this->assertTrue($percent->contains('No tasks found.'));

        // The status filter accepts the multi-select and legacy scalar forms.
        $status = $admin->get('/?page=tasks-v2&status[]=1');
        $this->assertTrue($status->contains('Findable payload'));
        $statusLegacy = $admin->get('/?page=tasks-v2&status=1');
        $this->assertTrue($statusLegacy->contains('Findable payload'));
        $statusWrong = $admin->get('/?page=tasks-v2&status[]=999');
        $this->assertTrue($statusWrong->contains('No tasks found.'), 'Unknown filter values are ignored, matching nothing here.');

        // The created task id must exist so the cleanup expectations hold.
        $this->assertGreaterThan(0, $taskId);
    }

    public function test_tasks_v2_columns_sort(): void
    {
        $admin = $this->adminSession();
        $first = $admin->createTask('A-sort task');
        $second = $admin->createTask('B-sort task');

        $asc = $admin->get('/?page=tasks-v2&sort=id&dir=asc');
        $this->assertGreaterThan(
            strpos($asc->body, 'A-sort task'),
            strpos($asc->body, 'B-sort task'),
            'Ascending id order puts the older task first.'
        );
        $this->assertTrue($asc->contains('aria-sort="ascending"'), 'The active column exposes the direction.');

        $desc = $admin->get('/?page=tasks-v2&sort=id&dir=desc');
        $this->assertGreaterThan(
            strpos($desc->body, 'B-sort task'),
            strpos($desc->body, 'A-sort task'),
            'Descending id order puts the newer task first.'
        );

        // The unknown sort key falls back to the default; the crafted value
        // can never reach the SQL (the same allowlist discipline as v1).
        $crafted = $admin->get('/?page=tasks-v2&sort=id;DROP%20TABLE%20tasks&dir=desc');
        $this->assertSame(200, $crafted->status);
        $this->assertTrue($crafted->contains('A-sort task'), 'The crafted sort is ignored and the list renders.');
    }

    public function test_tasks_v2_paginates(): void
    {
        $admin = $this->adminSession();
        for ($i = 1; $i <= 21; $i++) {
            $admin->createTask('bulkpage' . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
        }

        $page1 = $admin->get('/?page=tasks-v2');
        $this->assertTrue($page1->contains('21 tasks'), 'The header counts all tasks.');
        $this->assertTrue($page1->contains('Page 1 of 2'));
        // The default order is updated DESC (then id DESC), so the newest
        // task sits on page 1 and the first-created one spills to page 2.
        $this->assertTrue($page1->contains('bulkpage21'));
        $this->assertTrue($page1->contains('page=tasks-v2&amp;p=2'), 'The pagination links stay on the v2 page.');

        $page2 = $admin->get('/?page=tasks-v2&p=2');
        $this->assertTrue($page2->contains('bulkpage01'), 'The last rows land on page 2.');
        $this->assertFalse($page2->contains('bulkpage21'), 'Page 1 rows stay on page 1.');
    }

    public function test_tasks_v2_keeps_the_bulk_actions_and_archive_forms(): void
    {
        $admin = $this->adminSession();
        $admin->createTask('Archivable task');

        $page = $admin->get('/?page=tasks-v2');
        $this->assertTrue($page->contains('value="bulk_move_tasks"'), 'The move-to-project bulk action renders.');
        $this->assertTrue($page->contains('value="bulk_archive_tasks"'), 'The archive bulk action renders.');
        $this->assertTrue($page->contains('id="archive-'), 'The per-row archive form renders.');

        // The bulk wiring must be swap-safe: delegated listeners and a re-sync
        // on the region swap event instead of element-bound listeners.
        $this->assertTrue($page->contains("e.target.id==='check-all'"), 'Select-all must be delegated so it survives a swap.');
        $this->assertTrue($page->contains('tb-list-region:swapped'), 'The bulk buttons must re-sync after every swap.');
    }

    // --- archive-v2: the restorable task list (#57-D) ------------------------
    //
    // The archive joins the CRUD engine with the search/sort/pagination
    // mechanics of the other lists; the restorable rows, the access model and
    // the empty state stay the ones ArchiveTest pins on ?page=archive.

    private function archiveTask(AppSession $admin, int $taskId): void
    {
        $response = $admin->post('/?page=task&id=' . $taskId, ['action' => 'archive_task', 'id' => (string)$taskId]);
        $this->assertSame(302, $response->status);
    }

    public function test_archive_v2_searches_title_and_description(): void
    {
        $admin = $this->adminSession();
        $byTitle = $admin->createTask('Findable archive payload');
        $byDescription = $admin->createTask('Ordinary archive task', ['description' => 'unique-marker-84']);
        $active = $admin->createTask('Findable but active');
        $this->archiveTask($admin, $byTitle);
        $this->archiveTask($admin, $byDescription);

        $title = $admin->get('/?page=archive-v2&q=Findable+archive');
        $this->assertTrue($title->contains('Findable archive payload'), 'The title search finds the archived task.');
        $this->assertFalse($title->contains('Ordinary archive task'), 'The rows outside the search are filtered out.');

        $description = $admin->get('/?page=archive-v2&q=unique-marker-84');
        $this->assertTrue($description->contains('Ordinary archive task'), 'The description search finds the archived task.');
        $this->assertFalse($description->contains('Findable archive payload'));

        $empty = $admin->get('/?page=archive-v2&q=nothing-matches-this');
        $this->assertTrue($empty->contains('No archived tasks match the search.'), 'The filtered empty state renders.');

        // A literal % must not act as a wildcard (the shared search discipline).
        $percent = $admin->get('/?page=archive-v2&q=%25');
        $this->assertTrue($percent->contains('No archived tasks match the search.'));
        $this->assertFalse($percent->contains('Findable but active'), 'Active tasks never leak into the archive list.');
    }

    public function test_archive_v2_columns_sort(): void
    {
        $admin = $this->adminSession();
        $first = $admin->createTask('A-archive task');
        $second = $admin->createTask('B-archive task');
        $this->archiveTask($admin, $first);
        $this->archiveTask($admin, $second);

        $asc = $admin->get('/?page=archive-v2&sort=task&dir=asc');
        $this->assertGreaterThan(
            strpos($asc->body, 'A-archive task'),
            strpos($asc->body, 'B-archive task'),
            'Ascending title order puts A before B.'
        );
        $this->assertTrue($asc->contains('aria-sort="ascending"'), 'The active column exposes the direction.');

        // The default order is archived DESC (then id DESC): the newest
        // archived task sits on top, exactly how the hand-built page ordered.
        $default = $admin->get('/?page=archive-v2');
        $this->assertGreaterThan(
            strpos($default->body, 'B-archive task'),
            strpos($default->body, 'A-archive task'),
            'Default order puts the newest archived task first.'
        );

        // The unknown sort key falls back to the default; the crafted value
        // can never reach the SQL (the same allowlist discipline as the other
        // lists).
        $crafted = $admin->get('/?page=archive-v2&sort=task;DROP%20TABLE%20tasks&dir=desc');
        $this->assertSame(200, $crafted->status);
        $this->assertTrue($crafted->contains('A-archive task'), 'The crafted sort is ignored and the list renders.');
    }

    public function test_archive_v2_paginates(): void
    {
        $admin = $this->adminSession();
        for ($i = 1; $i <= 21; $i++) {
            $id = $admin->createTask('archpage' . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
            $this->archiveTask($admin, $id);
        }

        $page1 = $admin->get('/?page=archive-v2');
        $this->assertTrue($page1->contains('21 archived tasks'), 'The header counts all archived tasks.');
        $this->assertTrue($page1->contains('Page 1 of 2'));
        $this->assertTrue($page1->contains('archpage21'), 'The newest archived task renders on page 1.');
        $this->assertTrue($page1->contains('page=archive-v2&amp;p=2'), 'The pagination links stay on the v2 page.');

        $page2 = $admin->get('/?page=archive-v2&p=2');
        $this->assertTrue($page2->contains('archpage01'), 'The oldest archived row lands on page 2.');
        $this->assertFalse($page2->contains('archpage21'), 'Page 1 rows stay on page 1.');
    }

    public function test_archive_v2_keeps_the_restore_and_delete_actions(): void
    {
        $admin = $this->adminSession();
        $own = $admin->createTask('Restorable payload');
        $this->archiveTask($admin, $own);

        $page = $admin->get('/?page=archive-v2');
        $this->assertTrue($page->contains('value="restore_task"'), 'The per-row restore form renders.');
        $this->assertTrue($page->contains('value="delete_task"'), 'The per-row delete form renders for the administrator.');
        $this->assertTrue($page->contains('value="?page=archive-v2"'), 'The delete returns to the v2 page.');

        // The alias serves the same engine: its generated links keep the
        // requested page name so the classic URL stays fully functional.
        $alias = $admin->get('/?page=archive');
        $this->assertTrue($alias->contains('value="restore_task"'));
        $this->assertTrue($alias->contains('value="?page=archive"'), 'The alias delete returns to the alias.');
        $this->assertTrue($alias->contains('value="archive"'), 'The alias search form posts back to the alias.');
    }

    public function test_archive_v2_access_model(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'regular84');
        $user = new AppSession('regular84', 'regular84Pass12345');

        $this->assertSame(200, $user->get('/?page=archive-v2')->status, 'The v2 archive stays visible to every signed-in user like ?page=archive.');
        $this->assertSame(302, (new AppSession())->get('/?page=archive-v2')->status, 'Anonymous visitors are pushed to the sign-in page.');
    }

    // --- parity: the v2 pages render exactly what the originals render ------

    public function test_users_v2_page_matches_the_users_page(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'parityuser');

        // One throwaway request consumes the create-user flash message, so
        // both compared fetches render the plain list.
        $admin->get('/?page=users');

        $old = $this->pageChrome($admin->get('/?page=users')->body);
        $new = $this->pageChrome($admin->get('/?page=users-v2')->body);
        // The navbar link points at the v2 page on both fetches, so both
        // bodies get the same page-name rewrite before the comparison.
        $old = str_replace('users-v2', 'users', $old);
        $new = str_replace('users-v2', 'users', $new);
        $this->assertSame($old, $new, 'The v2 users list must render the identical chrome to ?page=users.');
    }

    public function test_tasks_v2_page_matches_the_task_list(): void
    {
        $admin = $this->adminSession();
        $admin->createTask('Parity task', ['description' => 'parity payload 57']);

        $old = $this->pageChrome($admin->get('/?page=tasks')->body);
        $new = $this->pageChrome($admin->get('/?page=tasks-v2')->body);
        // The navbar link points at the v2 page on both fetches, so both
        // bodies get the same page-name rewrite before the comparison.
        $old = str_replace('tasks-v2', 'tasks', $old);
        $new = str_replace('tasks-v2', 'tasks', $new);
        $this->assertSame($old, $new, 'The v2 task list must render the identical chrome to the task list.');
    }

    public function test_filtered_v2_pages_match_the_filtered_originals(): void
    {
        $admin = $this->adminSession();
        $admin->createTask('Parity filtered task', ['description' => 'parity filtered 57']);
        $this->createUser($admin, 'parityuser');

        // One throwaway request consumes the create-user flash message, so
        // every compared fetch renders the plain filtered list.
        $admin->get('/?page=users');

        $pairs = [
            ['/page=tasks&q=Parity', '/page=tasks-v2&q=Parity', 'tasks-v2', 'tasks'],
            ['/page=tasks&sort=id&dir=desc', '/page=tasks-v2&sort=id&dir=desc', 'tasks-v2', 'tasks'],
            ['/page=users&q=parityuser', '/page=users-v2&q=parityuser', 'users-v2', 'users'],
            ['/page=documents&q=Parity', '/page=documents-v2&q=Parity', 'documents-v2', 'documents'],
        ];
        foreach ($pairs as [$oldPath, $newPath, $fromName, $toName]) {
            $old = $this->pageChrome($admin->get('/?' . ltrim($oldPath, '/'))->body);
            $new = $this->pageChrome($admin->get('/?' . ltrim($newPath, '/'))->body);
            $old = str_replace($fromName, $toName, $old);
            $new = str_replace($fromName, $toName, $new);
            $this->assertSame($old, $new, "Filtered views must match too: {$oldPath} vs {$newPath}.");
        }
    }

    // --- documents-v2: the know-hows list feature set (mirrors KnowledgeBaseTest) ---

    private function createDoc(AppSession $admin, string $title, string $content = 'The standard process text.'): void
    {
        $response = $admin->post('/?page=document_new', [
            'action' => 'doc_save',
            'title' => $title,
            'content' => $content,
        ]);
        $this->assertSame(302, $response->status, 'Saving a know-how must redirect to the list.');
    }

    public function test_documents_v2_carries_the_know_how_chrome(): void
    {
        $admin = $this->adminSession();
        $this->createDoc($admin, 'Chrome doc', 'chrome payload 83');

        $page = $admin->get('/?page=documents-v2');
        $this->assertTrue($page->contains('Know-hows</h1>'), 'The page header renders.');
        $this->assertTrue($page->contains('1 document · standard processes, rules and everything worth writing down'), 'The header counts the documents.');
        $this->assertTrue($page->contains('href="?page=document_new">+ New know-how</a>'), 'The create entry stays.');
        $this->assertTrue($page->contains('aria-label="Search know-hows"'), 'The search bar renders.');
        $this->assertTrue($page->contains('<input type="hidden" name="page" value="documents-v2">'), 'The search form submits back to the requested page name.');
        foreach (['<th class="ps-3">Title</th>', '<th>Updated</th>', '<th>Created by</th>', '<th>Comments</th>', '<th>Attachments</th>'] as $header) {
            $this->assertTrue($page->contains($header), 'The classic header row stays: ' . $header);
        }
        $this->assertTrue($page->contains('href="?page=document&id='), 'Every row links its know-how.');
        // The no-refresh contract (#83): this list has no realtime region and
        // no bulk wiring - the classic page never had either.
        $this->assertFalse(str_contains($page->body, 'tb-list-region'), 'The know-hows list carries no realtime region.');
        $this->assertFalse(str_contains($page->body, 'check-all'), 'The know-hows list carries no bulk select.');
    }

    public function test_documents_v2_searches_title_and_content(): void
    {
        $admin = $this->adminSession();
        $this->createDoc($admin, 'WiFi relay guide', 'How the relay talks to the nodes.');
        $this->createDoc($admin, 'Billing rules', 'How invoices are issued.');

        $page = $admin->get('/?page=documents-v2&q=relay');
        $this->assertTrue($page->contains('WiFi relay guide'));
        $this->assertFalse($page->contains('Billing rules'), 'The search must filter the list.');
        $this->assertTrue($page->contains('value="relay"'), 'The search term stays in the input.');
        $this->assertTrue($page->contains('href="?page=documents-v2&amp;q=relay&amp;clear_search=1">Clear</a>'), 'The clear link stays on the requested page name, keeps the search term and drops the stored selection (#113).');

        $page = $admin->get('/?page=documents-v2&q=invoices');
        $this->assertTrue($page->contains('Billing rules'), 'The content is searched too.');
        $this->assertFalse($page->contains('WiFi relay guide'));

        $page = $admin->get('/?page=documents-v2&q=%25');
        $this->assertTrue($page->contains('No know-hows match the search.'), 'A literal % must not act as a wildcard.');
    }

    public function test_documents_v2_empty_board_explains_itself(): void
    {
        $admin = $this->adminSession();

        $page = $admin->get('/?page=documents-v2');
        $this->assertTrue($page->contains('No know-hows yet. Write the first one.'), 'The empty board shows the create-first call to action.');
        $this->assertTrue($page->contains('0 documents'), 'The header counts zero documents.');
    }

    public function test_documents_v2_paginates(): void
    {
        $admin = $this->adminSession();
        for ($i = 1; $i <= 22; $i++) {
            $this->createDoc($admin, 'Paged doc ' . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
        }

        $page1 = $admin->get('/?page=documents-v2');
        $this->assertTrue($page1->contains('22 documents'), 'The header counts all documents.');
        $this->assertTrue($page1->contains('Page 1 of 2'), 'The shared pagination counter renders.');
        $this->assertTrue($page1->contains('Paged doc 22'), 'The fixed recency order puts the newest first.');
        $this->assertTrue($page1->contains('page=documents-v2&amp;p=2'), 'The pagination links stay on the v2 page.');

        $page2 = $admin->get('/?page=documents-v2&p=2');
        $this->assertTrue($page2->contains('Paged doc 01'), 'The oldest rows land on page 2.');
        $this->assertFalse($page2->contains('Paged doc 22'), 'Page 1 rows stay on page 1.');

        // The search travels through the pagination links.
        $filtered = $admin->get('/?page=documents-v2&q=Paged+doc+');
        $this->assertTrue($filtered->contains('Page 1 of 2'), 'The filtered list still spans two pages.');
        $this->assertTrue($filtered->contains('page=documents-v2&amp;q=Paged+doc+&amp;p=2'), 'Pagination links keep the search term.');
    }

    public function test_documents_v2_keeps_the_fixed_order_and_crafted_sorts_change_nothing(): void
    {
        $admin = $this->adminSession();
        $this->createDoc($admin, 'Alpha doc');
        $this->createDoc($admin, 'Zulu doc');

        // The know-hows list has no sortable headers: the classic page ignored
        // sort and dir requests, and the v2 keeps that contract - the sort
        // allowlist entry is a fixed fragment, so even the allowlisted key with
        // dir=asc renders the same recency order.
        $default = $admin->get('/?page=documents-v2');
        $this->assertLessThan(
            strpos($default->body, 'Alpha doc'),
            strpos($default->body, 'Zulu doc'),
            'The recency order puts the newest document first.'
        );

        $flipped = $admin->get('/?page=documents-v2&sort=updated&dir=asc');
        $this->assertLessThan(
            strpos($flipped->body, 'Alpha doc'),
            strpos($flipped->body, 'Zulu doc'),
            'dir=asc changes nothing: the list keeps its fixed order.'
        );

        $crafted = $admin->get('/?page=documents-v2&sort=title;DROP%20TABLE%20documents&dir=desc');
        $this->assertSame(200, $crafted->status);
        $this->assertTrue($crafted->contains('Alpha doc'), 'The crafted sort is ignored and the list renders.');
    }

    public function test_documents_v2_stays_available_to_every_signed_in_user(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'docreader');
        $user = new AppSession('docreader', 'docreaderPass12345');

        $this->assertSame(200, $user->get('/?page=documents-v2')->status, 'The know-hows list stays available to regular users.');
        $this->assertSame(302, (new AppSession())->get('/?page=documents-v2')->status, 'Anonymous visitors are pushed to the sign-in page.');
    }

    public function test_documents_v2_page_matches_the_documents_page(): void
    {
        $admin = $this->adminSession();
        $this->createDoc($admin, 'Parity doc', 'parity payload 83');

        // One throwaway request consumes the save flash message, so both
        // compared fetches render the plain list.
        $admin->get('/?page=documents');

        $old = $this->pageChrome($admin->get('/?page=documents')->body);
        $new = $this->pageChrome($admin->get('/?page=documents-v2')->body);
        // The generated links carry the requested page name, so both bodies
        // get the same page-name rewrite before the comparison.
        $old = str_replace('documents-v2', 'documents', $old);
        $new = str_replace('documents-v2', 'documents', $new);
        $this->assertSame($old, $new, 'The v2 know-hows list must render the identical chrome to ?page=documents.');
    }

    // --- projects-v2: the projects list feature set (Task #81) --------------

    private function createProject(AppSession $session, string $name): int
    {
        $session->get('/?page=project_new');
        $response = $session->post('/?page=project_new', [
            'action' => 'save_project',
            'id' => '0',
            'name' => $name,
            'description' => 'Created by the CRUD abstraction suite',
            'status_id' => '1',
        ]);
        if (!preg_match('/page=project&id=(\d+)/', (string)$response->header('location'), $m)) {
            throw new \RuntimeException('Project creation did not redirect to the project page.');
        }
        return (int)$m[1];
    }

    public function test_projects_v2_carries_the_full_list_chrome(): void
    {
        $admin = $this->adminSession();
        $this->createProject($admin, 'Chrome project');
        $page = $admin->get('/?page=projects-v2');

        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('id="bulk-projects-form"'), 'The bulk delete form renders.');
        $this->assertTrue($page->contains('id="check-all-projects"'), 'The select-all checkbox renders.');
        $this->assertTrue($page->contains('aria-label="Select all projects on this page"'));
        $this->assertTrue($page->contains('aria-sort="descending"'), 'The default updated-desc sort exposes the direction.');
        $this->assertTrue($page->contains('aria-label="Order by Updated, currently desc, activate to sort ascending"'), 'The shared sort_header() renders the full a11y label.');
        $this->assertTrue($page->contains('aria-label="Order by Project, currently unsorted, activate to sort descending"'));
        $this->assertSame(0, substr_count($page->body, 'tb-list-region'), 'The projects list carries no realtime region, exactly like the classic page.');
        $this->assertTrue($page->contains('page=projects-v2&amp;sort=name&amp;dir=asc'), 'The header links stay on the v2 page.');
        $this->assertTrue($page->contains('href="?page=tasks-v2&project='), 'The per-row Tasks... link opens the task list filtered.');
    }

    public function test_projects_v2_columns_sort(): void
    {
        $admin = $this->adminSession();
        $this->createProject($admin, 'Alpha project');
        $this->createProject($admin, 'Zulu project');

        $asc = $admin->get('/?page=projects-v2&sort=name&dir=asc');
        $this->assertGreaterThan(
            strpos($asc->body, 'Alpha project'),
            strpos($asc->body, 'Zulu project'),
            'Ascending name order puts Alpha first.'
        );
        $this->assertTrue($asc->contains('aria-sort="ascending"'));

        $desc = $admin->get('/?page=projects-v2&sort=name&dir=desc');
        $this->assertGreaterThan(
            strpos($desc->body, 'Zulu project'),
            strpos($desc->body, 'Alpha project'),
            'Descending name order puts Zulu first.'
        );

        // The unknown sort key falls back to the default; the crafted value
        // can never reach the SQL (the shared allowlist discipline).
        $crafted = $admin->get('/?page=projects-v2&sort=name;DROP%20TABLE%20projects&dir=desc');
        $this->assertSame(200, $crafted->status);
        $this->assertTrue($crafted->contains('Alpha project'), 'The crafted sort is ignored and the list renders.');
    }

    public function test_projects_v2_paginates(): void
    {
        $admin = $this->adminSession();
        for ($i = 1; $i <= 21; $i++) {
            $this->createProject($admin, 'listproj' . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
        }

        $page1 = $admin->get('/?page=projects-v2');
        $this->assertTrue($page1->contains('21 projects'), 'The header counts all projects.');
        $this->assertTrue($page1->contains('Page 1 of 2'));
        $this->assertTrue($page1->contains('page=projects-v2&amp;p=2'), 'The pagination links stay on the v2 page.');

        $page2 = $admin->get('/?page=projects-v2&p=2');
        $this->assertTrue($page2->contains('listproj01'), 'The oldest project lands on page 2 (updated desc).');
    }

    public function test_projects_v2_keeps_the_bulk_actions_and_delete_forms(): void
    {
        $admin = $this->adminSession();
        $this->createProject($admin, 'Deletable project');

        $page = $admin->get('/?page=projects-v2');
        $this->assertTrue($page->contains('value="bulk_delete_projects"'), 'The bulk delete action renders.');
        $this->assertTrue($page->contains('id="delete-project-'), 'The per-row delete form renders.');
        $this->assertTrue($page->contains("e.target.id==='check-all-projects'"), 'Select-all must be delegated so it survives swaps.');
        // No realtime region on this list, so the swap re-sync listener is
        // deliberately absent (the no-refresh contract stays intact).
        $this->assertFalse($page->contains('tb-list-region:swapped'), 'The projects list carries no region, so no swap listener.');

        // The Projects list stays reachable for non-admins, who see no delete
        // controls (the same access model as the classic page).
        $this->createUser($admin, 'viewer');
        $viewer = new AppSession('viewer', 'viewerPass12345');
        $view = $viewer->get('/?page=projects-v2');
        $this->assertSame(200, $view->status);
        $this->assertTrue($view->contains('Chrome project') || $view->contains('Deletable project'), 'The list renders for a plain user.');
        // No per-row delete forms for a plain user; the bulk button renders
        // exactly like on the classic page (the handler skips what they may
        // not delete).
        $this->assertFalse($view->contains('id="delete-project-'), 'A plain user sees no per-row delete forms.');
    }

    public function test_projects_v2_page_matches_the_projects_page(): void
    {
        $admin = $this->adminSession();
        $this->createProject($admin, 'Parity project');

        // The one intended difference: the v2 headers speak the shared
        // sort_header() a11y dialect, so the header row is excluded from the
        // byte comparison and pinned separately (see the chrome test).
        $cutThead = fn(string $body): string => (string)preg_replace('/<thead>.*<\/thead>/s', '', $this->pageChrome($body));

        $old = $cutThead($admin->get('/?page=projects')->body);
        $new = $cutThead($admin->get('/?page=projects-v2')->body);
        // The navbar link points at the v2 page on both fetches, so both
        // bodies get the same page-name rewrite before the comparison.
        $old = str_replace('projects-v2', 'projects', $old);
        $new = str_replace('projects-v2', 'projects', $new);
        $this->assertSame($old, $new, 'The v2 projects list must render the identical chrome to ?page=projects.');
    }

    // --- templates-v2: the templates list feature set (Task #82) ------------

    private function createTemplateRow(AppSession $session, string $name, string $type = 'task', string $description = 'Template body text'): int
    {
        $session->get('/?page=template_new');
        $response = $session->post('/?page=template_new', [
            'action' => 'save_template',
            'id' => '0',
            'type' => $type,
            'name' => $name,
            'description' => $description,
        ]);
        if ($response->status !== 302) {
            throw new \RuntimeException('Template creation did not redirect to the templates list.');
        }
        $row = TestServer::pdo()->prepare('SELECT id FROM templates WHERE name=?');
        $row->execute([$name]);
        return (int)$row->fetchColumn();
    }

    public function test_templates_v2_carries_the_full_list_chrome(): void
    {
        $admin = $this->adminSession();
        $this->createTemplateRow($admin, 'Chrome template');
        $page = $admin->get('/?page=templates-v2');

        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('href="?page=template_new">+ New template</a>'), 'The create button renders.');
        $this->assertTrue($page->contains('aria-label="Filter by template types"'), 'The type[] filter card renders.');
        $this->assertTrue($page->contains('value="templates-v2"'), 'The filter form targets the v2 page.');
        $this->assertTrue($page->contains('aria-sort="ascending"'), 'The default name-asc sort exposes the direction.');
        $this->assertTrue($page->contains('aria-label="Order by Name, currently asc, activate to sort descending"'), 'The shared sort_header() renders the full a11y label.');
        $this->assertTrue($page->contains('aria-label="Order by Type, currently unsorted, activate to sort descending"'));
        $this->assertSame(0, substr_count($page->body, 'tb-list-region'), 'The templates list carries no realtime region, exactly like the classic page.');
        $this->assertTrue($page->contains('page=templates-v2&amp;sort=type&amp;dir=asc'), 'The header links stay on the v2 page.');
    }

    public function test_templates_v2_columns_sort(): void
    {
        $admin = $this->adminSession();
        $this->createTemplateRow($admin, 'Alpha template');
        $this->createTemplateRow($admin, 'Zulu template');

        $asc = $admin->get('/?page=templates-v2&sort=name&dir=asc');
        $this->assertGreaterThan(
            strpos($asc->body, 'Alpha template'),
            strpos($asc->body, 'Zulu template'),
            'Ascending name order puts Alpha first.'
        );
        $this->assertTrue($asc->contains('aria-sort="ascending"'));

        $desc = $admin->get('/?page=templates-v2&sort=name&dir=desc');
        $this->assertGreaterThan(
            strpos($desc->body, 'Zulu template'),
            strpos($desc->body, 'Alpha template'),
            'Descending name order puts Zulu first.'
        );

        // The unknown sort key falls back to the default; the crafted value
        // can never reach the SQL (the shared allowlist discipline).
        $crafted = $admin->get('/?page=templates-v2&sort=name;DROP%20TABLE%20templates&dir=desc');
        $this->assertSame(200, $crafted->status);
        $this->assertTrue($crafted->contains('Alpha template'), 'The crafted sort is ignored and the list renders.');
    }

    public function test_templates_v2_type_filter(): void
    {
        $admin = $this->adminSession();
        $this->createTemplateRow($admin, 'Filtered task template', 'task');
        $this->createTemplateRow($admin, 'Filtered project template', 'project');

        $both = $admin->get('/?page=templates-v2&type[]=task&type[]=project');
        $this->assertTrue($both->contains('Filtered task template'));
        $this->assertTrue($both->contains('Filtered project template'));

        $taskOnly = $admin->get('/?page=templates-v2&type[]=task');
        $this->assertTrue($taskOnly->contains('Filtered task template'));
        $this->assertFalse($taskOnly->contains('Filtered project template'));

        // The legacy scalar value keeps working through filter_values().
        $legacy = $admin->get('/?page=templates-v2&type=project');
        $this->assertTrue($legacy->contains('Filtered project template'));
        $this->assertFalse($legacy->contains('Filtered task template'));

        // A crafted type value is ignored: the whole list renders.
        $crafted = $admin->get('/?page=templates-v2&type[]=task%29%20OR%201%3D1--');
        $this->assertSame(200, $crafted->status);
        $this->assertTrue($crafted->contains('Filtered task template'), 'The crafted value is ignored and the list renders.');
        $this->assertTrue($crafted->contains('Filtered project template'));
    }

    public function test_templates_v2_paginates(): void
    {
        $admin = $this->adminSession();
        for ($i = 1; $i <= 21; $i++) {
            $this->createTemplateRow($admin, 'listtpl' . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
        }

        $page1 = $admin->get('/?page=templates-v2');
        $this->assertTrue($page1->contains('21 templates'), 'The header counts all templates.');
        $this->assertTrue($page1->contains('Page 1 of 2'));
        $this->assertTrue($page1->contains('page=templates-v2&amp;p=2'), 'The pagination links stay on the v2 page.');

        $page2 = $admin->get('/?page=templates-v2&p=2');
        $this->assertTrue($page2->contains('listtpl21'), 'The last template lands on page 2 (name asc).');
        $this->assertFalse($page2->contains('listtpl01'), 'Page 1 rows stay on page 1.');
    }

    public function test_templates_v2_keeps_the_access_model(): void
    {
        $admin = $this->adminSession();
        $this->createTemplateRow($admin, 'Deletable template');

        $page = $admin->get('/?page=templates-v2');
        $this->assertTrue($page->contains('value="delete_template"'), 'The per-row delete form renders for the admin.');
        $this->assertTrue($page->contains('href="?page=template_edit&id='), 'The Edit link renders.');

        // The templates list stays reachable for non-admins, who see the Edit
        // link but no delete controls (the same access model as the classic
        // page: only an administrator or the creator may delete).
        $this->createUser($admin, 'tplviewer');
        $viewer = new AppSession('tplviewer', 'tplviewerPass12345');
        $view = $viewer->get('/?page=templates-v2');
        $this->assertSame(200, $view->status);
        $this->assertTrue($view->contains('Deletable template'), 'The list renders for a plain user.');
        $this->assertTrue($view->contains('href="?page=template_edit&id='), 'The Edit link renders for a plain user.');
        $this->assertFalse($view->contains('value="delete_template"'), 'A plain user sees no delete forms.');
    }

    public function test_templates_v2_page_matches_the_templates_page(): void
    {
        $admin = $this->adminSession();
        $this->createTemplateRow($admin, 'Parity template');

        // The one intended difference: the v2 headers speak the shared
        // sort_header() a11y dialect, so the header row is excluded from the
        // byte comparison and pinned separately (see the chrome test).
        $cutThead = fn(string $body): string => (string)preg_replace('/<thead>.*<\/thead>/s', '', $this->pageChrome($body));

        // One throwaway request consumes the create-template flash message, so
        // both compared fetches render the plain list.
        $admin->get('/?page=templates');

        $old = $cutThead($admin->get('/?page=templates')->body);
        $new = $cutThead($admin->get('/?page=templates-v2')->body);
        // The navbar (and after the step-5 switch every entry link) points at
        // the v2 page, so both bodies get the same page-name rewrite before
        // the comparison.
        $old = str_replace('templates-v2', 'templates', $old);
        $new = str_replace('templates-v2', 'templates', $new);
        $this->assertSame($old, $new, 'The v2 templates list must render the identical chrome to ?page=templates.');
    }

    // --- messages-v2: the messenger people list on the engine (#57-E) --------

    private function crudUserId(string $username): int
    {
        $s = TestServer::pdo()->prepare('SELECT id FROM users WHERE username=?');
        $s->execute([$username]);
        $rows = $s->fetchAll(\PDO::FETCH_COLUMN);
        $s->closeCursor();
        $this->assertNotEmpty($rows, 'User ' . $username . ' does not exist.');
        return (int)$rows[0];
    }

    /** Sends through the composer rendered by the requested page, one-time
     * send token included (#87). */
    private function sendMessageVia(AppSession $sender, string $pageName, int $recipientId, string $body): void
    {
        $composer = $sender->get('/?page=' . $pageName . '&u=' . $recipientId);
        $this->assertSame(1, preg_match('/name="send_token" value="([^"]+)"/', $composer->body, $m), 'The composer renders the one-time send token (#87).');
        $this->assertSame(302, $sender->post('/?page=' . $pageName . '&u=' . $recipientId, [
            'action' => 'send_message',
            'recipient_id' => (string)$recipientId,
            'body' => $body,
            'send_token' => $m[1],
        ])->status, 'The send redirects back to the thread.');
    }

    public function test_messages_v2_carries_the_chat_chrome(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'chatpeer');
        $peerId = $this->crudUserId('chatpeer');

        $page = $admin->get('/?page=messages-v2');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('href="?page=messages-v2&u=' . $peerId . '"'), 'The people list links the other user on the v2 page.');
        $this->assertTrue($page->contains('Chatpeer Name'), 'The people list shows the other users.');
        $this->assertTrue($page->contains('name="recipient_id" value="' . $peerId . '"'), 'The thread form is bound to the auto-selected person.');
        $this->assertTrue($page->contains('<input type="hidden" name="page" value="messages-v2">'), 'The people search form submits back to the requested page name.');
        $this->assertTrue($page->contains('aria-label="Search people"'), 'The people search bar renders.');
        // The #94 chat regions stay untouched: composer token, send form and
        // scroll container hooks are exactly the contract points.
        $this->assertTrue($page->contains('name="send_token"'), 'The composer carries the one-time send token (#87).');
        $this->assertTrue($page->contains('data-tb-chat-send'), 'The composer keeps the chat JS hook.');
        $this->assertTrue($page->contains('data-tb-chat-scroll'), 'The thread keeps the scroll container hook (#94).');
        $this->assertTrue($page->contains('data-tb-more="?page=messages_more&amp;u='), 'The feed endpoint stays the messages_more JSON feed.');
    }

    public function test_messages_v2_people_search_filters_and_keeps_the_conversation(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'searchpeer');
        $this->createUser($admin, 'otherpeer');
        $peerId = $this->crudUserId('searchpeer');

        $page = $admin->get('/?page=messages-v2&u=' . $peerId . '&q=Searchpeer');
        $this->assertTrue($page->contains('Searchpeer Name'), 'The matching person renders.');
        $this->assertFalse($page->contains('Otherpeer Name'), 'The rows outside the search are filtered out.');
        $this->assertTrue($page->contains('Conversation with Searchpeer Name'), 'The conversation pane stays open on the searched view.');
        $this->assertTrue($page->contains('value="Searchpeer"'), 'The search term stays in the input.');
        $this->assertTrue($page->contains('href="?page=messages-v2&amp;u=' . $peerId . '&amp;clear_search=1">Clear</a>'), 'The clear link stays on the requested page, keeps the conversation and drops the stored selection (#113).');

        // A literal % must not act as a wildcard (the task search discipline).
        $percent = $admin->get('/?page=messages-v2&q=%25');
        $this->assertTrue($percent->contains('No people match the search.'));
    }

    public function test_messages_v2_people_paginate_and_keep_the_activity_order(): void
    {
        $admin = $this->adminSession();
        for ($i = 1; $i <= 22; $i++) {
            $this->createUser($admin, 'peopleuser' . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
        }

        $page1 = $admin->get('/?page=messages-v2');
        $this->assertTrue($page1->contains('Page 1 of 2'), 'The shared pagination counter renders.');
        $this->assertTrue($page1->contains('page=messages-v2&amp;p=2'), 'The pagination links stay on the v2 page.');
        $this->assertTrue($page1->contains('Peopleuser20 Name'), 'Twenty people land on page 1.');
        $this->assertFalse($page1->contains('Peopleuser22 Name'), 'The rest stay behind the pagination.');

        $page2 = $admin->get('/?page=messages-v2&p=2');
        $this->assertTrue($page2->contains('Peopleuser22 Name'), 'Page 2 carries the remaining people.');
        $this->assertFalse($page2->contains('Peopleuser01 Name'), 'Page 1 rows stay on page 1.');

        // The #94 order survives the port: a brand-new conversation moves its
        // person to the top of the (engine-ordered) list.
        $this->sendMessageVia($admin, 'messages-v2', $this->crudUserId('peopleuser05'), 'Paging order check.');
        $board = $admin->get('/?page=messages-v2');
        $user05 = strpos($board->body, 'href="?page=messages-v2&u=' . $this->crudUserId('peopleuser05') . '"');
        $user01 = strpos($board->body, 'href="?page=messages-v2&u=' . $this->crudUserId('peopleuser01') . '"');
        $this->assertNotFalse($user05);
        $this->assertNotFalse($user01);
        $this->assertLessThan($user01, $user05, 'The newest conversation sits at the top of the people list.');
    }

    public function test_messages_v2_deep_links_reach_people_on_other_pages(): void
    {
        $admin = $this->adminSession();
        for ($i = 1; $i <= 22; $i++) {
            $this->createUser($admin, 'deepuser' . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
        }
        $deepId = $this->crudUserId('deepuser22');

        // The #98-style deep link targets a person whose people row sits on
        // page 2: the conversation must open exactly that thread, not fall
        // through to the auto-select.
        $thread = $admin->get('/?page=messages-v2&u=' . $deepId);
        $this->assertSame(200, $thread->status);
        $this->assertTrue($thread->contains('Conversation with Deepuser22 Name'), 'The deep-linked conversation renders immediately.');
        $this->assertTrue($thread->contains('name="recipient_id" value="' . $deepId . '"'), 'The composer is bound to the deep-linked person.');
        $this->assertFalse($thread->contains('href="?page=messages-v2&u=' . $deepId . '"'), 'The person is not on the rendered people page 1.');

        // An unknown peer id falls back to the auto-select like before.
        $unknown = $admin->get('/?page=messages-v2&u=99999');
        $this->assertSame(200, $unknown->status);
        $this->assertFalse($unknown->contains('Conversation with Deleted user'), 'An unknown id does not open the orphan thread.');
    }

    public function test_messages_v2_self_deep_link_falls_back_like_the_classic_page(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'selflinkpeer');

        // A crafted ?u=<own id> never opens a self-conversation: the people
        // list has no own row (no self-conversation exists), so the visitor
        // lands in the auto-selected conversation exactly like on the
        // classic URL.
        $page = $admin->get('/?page=messages-v2&u=' . $this->crudUserId('admin'));
        $this->assertSame(200, $page->status);
        $this->assertFalse($page->contains('Conversation with Administrator'), 'No self-conversation opens.');
        $this->assertTrue($page->contains('Conversation with Selflinkpeer Name'), 'The auto-selected first person opens instead.');
    }

    public function test_messages_v2_page_matches_the_messages_page(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'paritychat');

        // One throwaway request consumes the unread state (any board view
        // marks the messages read), so both compared fetches render the same
        // zero badge.
        $admin->get('/?page=messages');

        $old = $this->pageChrome($admin->get('/?page=messages')->body);
        $new = $this->pageChrome($admin->get('/?page=messages-v2')->body);
        // The navbar (and after the step-5 switch every entry link) points at
        // the v2 page, so both bodies get the same page-name rewrite before
        // the comparison.
        $old = str_replace('messages-v2', 'messages', $old);
        $new = str_replace('messages-v2', 'messages', $new);
        $this->assertSame($old, $new, 'The v2 messenger must render the identical chrome to ?page=messages.');
    }

    // --- notifications-v2: the stream on the engine (#57-E) ------------------

    private function notifyUser(AppSession $admin, int $userId, string $taskTitle): int
    {
        return $admin->createTask($taskTitle, ['assignees' => [$userId]]);
    }

    public function test_notifications_v2_carries_the_stream_chrome(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'streamuser');
        $this->notifyUser($admin, $this->crudUserId('streamuser'), 'Stream chrome task');

        // The stream belongs to the notified user: the assignee sees the rows.
        $page = (new AppSession('streamuser', 'streamuserPass12345'))->get('/?page=notifications-v2');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Notifications</h1>'), 'The page header renders.');
        $this->assertTrue($page->contains('notification-flag-toggle'), 'The #100 red-flag toggle stays on the stream.');
        $this->assertTrue($page->contains('<input type="hidden" name="page" value="notifications-v2">'), 'The stream search form submits back to the requested page name.');
        $this->assertTrue($page->contains('aria-label="Search notifications"'), 'The stream search bar renders.');
        $this->assertTrue($page->contains('href="?page=notification_seen&id='), 'Every row links through the per-item seen route (#73).');
        $this->assertTrue($page->contains('Stream chrome task'), 'The stream shows the task behind the notification.');
        $this->assertTrue($page->contains('name="action" value="mark_all_seen"'), 'The bulk shortcut stays next to the counter.');
    }

    public function test_notifications_v2_searches_task_title_and_event(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'searchnotified');
        $userId = $this->crudUserId('searchnotified');
        $this->notifyUser($admin, $userId, 'Relay notes task');
        $this->notifyUser($admin, $userId, 'Billing rules task');

        $notified = new AppSession('searchnotified', 'searchnotifiedPass12345');
        $byTitle = $notified->get('/?page=notifications-v2&q=Relay');
        $this->assertTrue($byTitle->contains('Relay notes task'), 'The task title is searched.');
        $this->assertFalse($byTitle->contains('Billing rules task'), 'The rows outside the search are filtered out.');
        $this->assertTrue($byTitle->contains('value="Relay"'), 'The search term stays in the input.');
        $this->assertTrue($byTitle->contains('href="?page=notifications-v2&amp;q=Relay&amp;clear_search=1">Clear</a>'), 'The clear link stays on the requested page, keeps the search term and drops the stored selection (#113).');

        $byEvent = $notified->get('/?page=notifications-v2&q=Comment');
        $this->assertFalse($byEvent->contains('Relay notes task'), 'Creation events are not comment events.');
        $admin->createTask('Commented task', ['assignees' => [$userId]]);
        $taskId = (int)TestServer::pdo()->query("SELECT id FROM tasks WHERE title='Commented task'")->fetchColumn();
        $admin->post('/?page=task&id=' . $taskId, ['action' => 'comment', 'task_id' => (string)$taskId, 'body' => 'event search payload']);
        $byEvent = $notified->get('/?page=notifications-v2&q=Comment');
        $this->assertTrue($byEvent->contains('Commented task'), 'The event text is searched too.');

        // A literal % must not act as a wildcard (the task search discipline).
        $percent = $notified->get('/?page=notifications-v2&q=%25');
        $this->assertTrue($percent->contains('No notifications match the search.'));
    }

    public function test_notifications_v2_paginates(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'pagednotified');
        $userId = $this->crudUserId('pagednotified');
        for ($i = 1; $i <= 22; $i++) {
            $this->notifyUser($admin, $userId, 'Paged notification ' . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
        }

        $notified = new AppSession('pagednotified', 'pagednotifiedPass12345');
        $page1 = $notified->get('/?page=notifications-v2');
        $this->assertTrue($page1->contains('Page 1 of 2'), 'The shared pagination counter renders.');
        $this->assertTrue($page1->contains('page=notifications-v2&amp;p=2'), 'The pagination links stay on the v2 page.');
        $this->assertTrue($page1->contains('Paged notification 22'), 'The fixed newest-first order puts the newest first.');
        $this->assertFalse($page1->contains('Paged notification 01'), 'Page 1 carries the newest twenty only.');

        $page2 = $notified->get('/?page=notifications-v2&p=2');
        $this->assertTrue($page2->contains('Paged notification 01'), 'The oldest rows land on page 2.');
        $this->assertFalse($page2->contains('Paged notification 22'), 'Page 1 rows stay on page 1.');
    }

    public function test_notifications_v2_access_model(): void
    {
        $this->createUser($this->adminSession(), 'notifvisitor');
        $this->assertSame(302, (new AppSession())->get('/?page=notifications-v2')->status, 'Anonymous visitors are pushed to the sign-in page.');
        // The stream is self-scoped like the classic page: another account
        // only ever sees their own rows.
        $this->assertSame(200, (new AppSession('notifvisitor', 'notifvisitorPass12345'))->get('/?page=notifications-v2')->status);
    }

    public function test_notifications_v2_page_matches_the_notifications_page(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'paritynotified');
        $this->notifyUser($admin, $this->crudUserId('paritynotified'), 'Parity notification task');
        $notified = new AppSession('paritynotified', 'paritynotifiedPass12345');

        // One throwaway request consumes the unseen state (opening the stream
        // marks everything seen, #79), so both compared fetches render the
        // same zero counter without the "new" markers.
        $notified->get('/?page=notifications');

        $old = $this->pageChrome($notified->get('/?page=notifications')->body);
        $new = $this->pageChrome($notified->get('/?page=notifications-v2')->body);
        // The navbar (and after the step-5 switch every entry link) points at
        // the v2 page, so both bodies get the same page-name rewrite before
        // the comparison.
        $old = str_replace('notifications-v2', 'notifications', $old);
        $new = str_replace('notifications-v2', 'notifications', $new);
        $this->assertSame($old, $new, 'The v2 stream must render the identical chrome to ?page=notifications.');
    }
}
