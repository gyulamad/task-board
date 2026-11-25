<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\HttpResponse;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #29: saved searches on the task list. The filters of the list travel
 * in the URL only (no session, no cookies), so a saved search stores the
 * whitelisted filter part of the current list URL under a name for the
 * signed-in user, and the Saved searches dropdown re-opens that stored link.
 */
final class SavedSearchesTest extends E2eTestCase
{
    private function seedTasks(): array
    {
        $admin = $this->adminSession();
        $alpha = $admin->createTask('Alpha search token', ['description' => 'unique description zebra', 'status_id' => '1']);
        $beta = $admin->createTask('Beta item', ['description' => 'Alpha appears in this description', 'status_id' => '2']);
        return [$admin, $alpha, $beta];
    }

    /** Saves a search the way the Save search form submits it (POST fields + CSRF). */
    private function saveSearch(AppSession $session, array $filters, string $name = 'My search'): HttpResponse
    {
        return $session->post('/?page=tasks', ['action' => 'save_search', 'name' => $name] + $filters);
    }

    public function test_save_search_stores_the_filters_and_redirects_to_the_stored_link(): void
    {
        [$admin, , $beta] = $this->seedTasks();

        $response = $this->saveSearch($admin, ['q' => 'Beta', 'status' => ['2']], 'Beta only');
        $this->assertSame(302, $response->status);
        $this->assertSame('?page=tasks&q=Beta&status%5B0%5D=2', $response->header('location'), 'The redirect must be the actual stored search link.');

        $row = TestServer::pdo()->query("SELECT user_id,name,query FROM saved_searches WHERE name='Beta only'")->fetch();
        $this->assertNotFalse($row, 'The saved search should have been stored in the database.');
        $this->assertSame('Beta only', $row['name']);
        $this->assertSame('q=Beta&status%5B0%5D=2', $row['query'], 'Only the whitelisted filter part of the URL is stored.');
        $this->assertSame('1', (string)$row['user_id'], 'The saved search belongs to the signed-in user.');

        // Following the redirect shows the flash and the filtered list.
        $target = $admin->get('/?page=tasks&q=Beta&status%5B0%5D=2');
        $this->assertSame(200, $target->status);
        $this->assertTrue($target->contains('Search saved.'));
        $this->assertTrue($target->contains('#' . $beta . ' · Beta item'));
    }

    public function test_saved_search_dropdown_reopens_the_stored_link(): void
    {
        [$admin, $alpha, $beta] = $this->seedTasks();

        $this->saveSearch($admin, ['q' => 'Alpha search'], 'Alpha by title');
        $this->saveSearch($admin, ['status' => ['2']], 'Beta by status');

        $list = $admin->get('/?page=tasks');
        $this->assertTrue($list->contains('Saved searches'), 'The dropdown toggle must be rendered.');
        $this->assertTrue($list->contains('data-bs-toggle="dropdown"'));
        $this->assertTrue($list->contains('Alpha by title'));
        $this->assertTrue($list->contains('href="?page=tasks&amp;q=Alpha+search"'), 'The item links to the stored search link.');
        $this->assertTrue($list->contains('Beta by status'));
        $this->assertTrue($list->contains('href="?page=tasks&amp;status%5B0%5D=2"'));

        // Opening the stored link actually filters the list ("Alpha search"
        // only matches Alpha's title, not the word Alpha in Beta's description).
        $open = $admin->get('/?page=tasks&q=Alpha+search');
        $this->assertTrue($open->contains('#' . $alpha . ' · Alpha search token'));
        $this->assertFalse($open->contains('#' . $beta . ' · Beta item'));
    }

    public function test_save_form_carries_the_current_filters_as_hidden_fields(): void
    {
        $admin = $this->adminSession();
        $list = $admin->get('/?page=tasks&status=2&project=1&q=Beta');
        // The save form re-emits the current URL filters (the GET form cannot
        // be nested inside the POST form), so "Save search" stores what is on
        // screen without any session or cookie magic.
        $this->assertTrue($list->contains('name="action" value="save_search"'));
        $this->assertTrue($list->contains('name="q" value="Beta"'));
        $this->assertTrue($list->contains('name="status[]" value="2"'));
        $this->assertTrue($list->contains('name="project[]" value="1"'));
        $this->assertTrue($list->contains('Save search'));
    }

    public function test_save_search_requires_a_name(): void
    {
        $admin = $this->adminSession();
        $response = $this->saveSearch($admin, ['q' => 'Beta'], '   ');
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Search name is required.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM saved_searches')->fetchColumn());
    }

    public function test_save_search_requires_at_least_one_filter(): void
    {
        $admin = $this->adminSession();
        // Sort-only links just re-open the plain list, so they are not a search.
        $response = $this->saveSearch($admin, ['sort' => 'updated', 'dir' => 'asc'], 'Sort only');
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Set at least one filter before saving a search.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM saved_searches')->fetchColumn());
    }

    public function test_save_search_rejects_too_long_names(): void
    {
        $admin = $this->adminSession();
        $response = $this->saveSearch($admin, ['q' => 'Beta'], str_repeat('é', 201));
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Search name must be 200 characters or fewer.'));
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM saved_searches')->fetchColumn());
    }

    public function test_stored_query_keeps_only_known_filter_keys(): void
    {
        [$admin, , $beta] = $this->seedTasks();

        // Hand-crafted extras (other pages, pagination, unknown params) must
        // not end up in the stored link: a saved search is a list search.
        $response = $this->saveSearch($admin, [
            'q' => 'Beta',
            'status' => ['2'],
            'sort' => 'title',
            'dir' => 'desc',
            'evil' => '<script>alert(1)</script>',
            'page' => 'task',
            'id' => '99',
            'p' => '2',
        ], 'Whitelist check');

        $this->assertSame(302, $response->status);
        $row = TestServer::pdo()->query("SELECT query FROM saved_searches WHERE name='Whitelist check'")->fetch();
        $this->assertSame('q=Beta&status%5B0%5D=2&sort=title&dir=desc', $row['query']);
        $this->assertFalse(str_contains((string)$row['query'], 'evil'));
        $this->assertFalse(str_contains((string)$row['query'], 'page=task'));

        $list = $admin->get('/?page=tasks');
        $this->assertFalse($list->contains('evil'));
        $this->assertTrue($list->contains('#' . $beta . ' · Beta item'), 'The board keeps working normally.');
    }

    public function test_saved_searches_are_per_user_and_delete_is_guarded(): void
    {
        $admin = $this->adminSession();
        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'carol',
            'display_name' => 'Carol User',
            'password' => 'CarolPass12345',
            'role' => 'user',
        ]);
        $admin->post('/?page=admin', [
            'action' => 'add_user',
            'username' => 'dave',
            'display_name' => 'Dave User',
            'password' => 'DavePass123456',
            'role' => 'user',
        ]);
        $carol = new AppSession('carol', 'CarolPass12345');
        $dave = new AppSession('dave', 'DavePass123456');

        $this->saveSearch($carol, ['q' => 'Alpha'], 'Carols search');
        $searchId = (int)TestServer::pdo()->query("SELECT id FROM saved_searches WHERE name='Carols search'")->fetchColumn();

        // Carol sees her search, Dave does not see other users' searches.
        $this->assertTrue($carol->get('/?page=tasks')->contains('Carols search'));
        $this->assertFalse($dave->get('/?page=tasks')->contains('Carols search'));
        $this->assertTrue($dave->get('/?page=tasks')->contains('No saved searches yet.'));

        // Dave is neither admin nor owner: deletion is rejected.
        $denied = $dave->post('/?page=tasks', ['action' => 'delete_search', 'id' => (string)$searchId]);
        $this->assertSame(200, $denied->status);
        $this->assertTrue($denied->contains('Only an administrator or the search owner can delete this saved search.'));
        $this->assertSame(1, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM saved_searches WHERE id=' . $searchId)->fetchColumn());

        // The owner can delete her own saved search.
        $allowed = $carol->post('/?page=tasks', ['action' => 'delete_search', 'id' => (string)$searchId, 'return' => '?page=tasks&q=Alpha']);
        $this->assertSame(302, $allowed->status);
        $this->assertSame('?page=tasks&q=Alpha', $allowed->header('location'), 'Deletion returns to the sanitized return URL.');
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM saved_searches WHERE id=' . $searchId)->fetchColumn());
        $this->assertTrue($carol->get('/?page=tasks')->contains('Saved search deleted.'));

        // An administrator can delete a saved search owned by somebody else.
        $this->saveSearch($carol, ['q' => 'Alpha'], 'Carols second search');
        $otherId = (int)TestServer::pdo()->query("SELECT id FROM saved_searches WHERE name='Carols second search'")->fetchColumn();
        $adminDelete = $admin->post('/?page=tasks', ['action' => 'delete_search', 'id' => (string)$otherId]);
        $this->assertSame(302, $adminDelete->status);
        $this->assertSame(0, (int)TestServer::pdo()->query('SELECT COUNT(*) FROM saved_searches WHERE id=' . $otherId)->fetchColumn());
    }

    public function test_deleting_a_missing_saved_search_reports_an_error(): void
    {
        $admin = $this->adminSession();
        $response = $admin->post('/?page=tasks', ['action' => 'delete_search', 'id' => '9999']);
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Saved search not found.'));
    }

    public function test_saved_search_names_and_links_are_escaped(): void
    {
        $admin = $this->adminSession();

        $response = $this->saveSearch($admin, ['q' => '"><svg onload=alert(2)>'], 'x\'y"><script>alert(1)</script>');
        $this->assertSame(302, $response->status);

        $list = $admin->get('/?page=tasks');
        $this->assertTrue($list->contains('&lt;script&gt;alert(1)&lt;/script&gt;'), 'The name is rendered escaped.');
        $this->assertFalse($list->contains('<script>alert(1)'), 'No raw markup from the name.');
        $this->assertFalse($list->contains('<svg'), 'No raw markup from the search term.');
        $this->assertTrue($list->contains('q=%22%3E%3Csvg+onload%3Dalert%282%29%3E'), 'The stored link is URL-encoded, not injected.');
    }

    public function test_the_filter_card_names_the_saved_search_the_view_came_from(): void
    {
        // Task #132: the advanced filter area offers the saved searches
        // dropdown, but nothing said WHICH search (if any) the current view
        // came from - the card now names it next to the dropdown, and a view
        // that matches no stored search shows no name at all.
        [$admin, , $beta] = $this->seedTasks();

        // A pristine list carries no indicator at all.
        $plain = $admin->get('/?page=tasks');
        $this->assertFalse($plain->contains('data-tb-saved-search-selected'), 'No saved search name on a pristine list.');

        $this->saveSearch($admin, ['q' => 'Beta', 'status' => ['2']], 'Beta only');
        $open = $admin->get('/?page=tasks&q=Beta&status%5B0%5D=2');
        $this->assertTrue($open->contains('Selected:'), 'The name next to the dropdown is labelled.');
        $this->assertTrue($open->contains('data-tb-saved-search-selected="Beta only"'), 'The card names the saved search the view came from.');

        // The session replay keeps the name: after a trip elsewhere a bare
        // list visit restores the stored view (#113), which is the same
        // search - the indicator follows the effective view, replay included.
        $task = $admin->get('/?page=task&id=' . $beta);
        $this->assertSame(200, $task->status);
        $replayed = $admin->get('/?page=tasks');
        $this->assertTrue($replayed->contains('data-tb-saved-search-selected="Beta only"'), 'The replayed stored view still names its saved search.');

        // A hand-modified filter set is nobody's stored search: the
        // indicator disappears while the dropdowns still show the values.
        $modified = $admin->get('/?page=tasks&q=Beta&status%5B0%5D=1');
        $this->assertFalse($modified->contains('data-tb-saved-search-selected'), 'A view that matches no stored search shows no name.');

        // Deleting the search takes the name with it, on the very same URL.
        $searchId = (int)TestServer::pdo()->query("SELECT id FROM saved_searches WHERE name='Beta only'")->fetchColumn();
        $deleted = $admin->post('/?page=tasks', ['action' => 'delete_search', 'id' => (string)$searchId, 'return' => '?page=tasks&q=Beta&status%5B0%5D=2']);
        $this->assertSame(302, $deleted->status);
        $after = $admin->get('/?page=tasks&q=Beta&status%5B0%5D=2');
        $this->assertFalse($after->contains('data-tb-saved-search-selected'), 'The name is gone once the search is deleted.');
    }

    public function test_first_request_recreates_a_dropped_saved_searches_table(): void
    {
        $admin = $this->adminSession();

        $pdo = TestServer::pdo();
        $pdo->exec('DROP TABLE saved_searches');

        // The very next request must self-heal the schema instead of fataling
        // (the list page reads the table unconditionally).
        $page = $admin->get('/?page=tasks');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('No saved searches yet.'), 'The list page works right after the self-heal.');

        $response = $this->saveSearch($admin, ['q' => 'Alpha'], 'After self-heal');
        $this->assertSame(302, $response->status, 'Saving works on the recreated table.');
        $this->assertSame(1, (int)$pdo->query('SELECT COUNT(*) FROM saved_searches')->fetchColumn());
    }

    public function test_tasks_page_shows_the_empty_state_without_saved_searches(): void
    {
        $admin = $this->adminSession();
        $list = $admin->get('/?page=tasks');
        $this->assertTrue($list->contains('No saved searches yet.'));
        $this->assertFalse($list->contains('data-bs-toggle="dropdown"'), 'No dropdown toggle while the user has no saved searches.');
    }
}
