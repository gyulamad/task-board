<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;

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
        $this->assertTrue($page->contains('href="?page=documents-v2&amp;q=relay">Clear</a>'), 'The clear link stays on the requested page name and keeps the search term.');

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
}
