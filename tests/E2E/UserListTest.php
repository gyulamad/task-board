<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #57: the admin Users page gets the same list features as the other
 * CRUD lists - free text search over username and display name, a role
 * filter, sortable columns through the shared sort helpers and pagination -
 * reusing the exact mechanics of the task/projects/documents lists (DRY).
 * The realtime region (#51) keeps wrapping the list, so the 10 second
 * auto-refresh survives unchanged.
 */
final class UserListTest extends E2eTestCase
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

    public function test_the_users_list_searches_username_and_display_name(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'searchable');
        $this->createUser($admin, 'otheruser');

        // A display-name hit finds the row: the search covers both columns.
        $byName = $admin->get('/?page=users&q=Searchable');
        $this->assertTrue($byName->contains('searchable'));
        $this->assertFalse($byName->contains('Otheruser Name'), 'The rows outside the search are filtered out.');

        $byUsername = $admin->get('/?page=users&q=otheruser');
        $this->assertTrue($byUsername->contains('Otheruser Name'));
        $this->assertFalse($byUsername->contains('Searchable Name'));

        // A literal % must not act as a wildcard (the task search discipline).
        $percent = $admin->get('/?page=users&q=%25');
        $this->assertTrue($percent->contains('No users match the filters.'));
    }

    public function test_the_users_list_filters_by_role(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'plainuser');
        $this->createUser($admin, 'bossuser', 'admin');

        $admins = $admin->get('/?page=users&role[]=admin');
        $this->assertTrue($admins->contains('bossuser'));
        $this->assertFalse($admins->contains('Plainuser Name'), 'The user role is filtered out.');

        $both = $admin->get('/?page=users&role[]=admin&role[]=user');
        $this->assertTrue($both->contains('bossuser'));
        $this->assertTrue($both->contains('Plainuser Name'));

        // Legacy scalar links behave like a one-value array (#25 convention).
        $legacy = $admin->get('/?page=users&role=user');
        $this->assertTrue($legacy->contains('plainuser'));
        $this->assertFalse($legacy->contains('Bossuser Name'));

        // Unknown values are ignored, so a crafted URL filters nothing away.
        $crafted = $admin->get('/?page=users&role[]=superadmin');
        $this->assertTrue($crafted->contains('plainuser'));
    }

    public function test_the_users_list_columns_sort(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'alpha');
        $this->createUser($admin, 'zulu');

        $desc = $admin->get('/?page=users&sort=name&dir=desc');
        $this->assertTrue($desc->contains('Zulu Name'), 'The names render.');
        $this->assertGreaterThan(
            strpos($desc->body, 'Zulu Name'),
            strpos($desc->body, 'Alpha Name'),
            'Descending puts Zulu before Alpha.'
        );
        $this->assertTrue($desc->contains('aria-sort="descending"'), 'The active column exposes the direction.');

        // The unknown sort key falls back to the default and the database
        // stays intact (the same allowlist discipline as the task list).
        $crafted = $admin->get('/?page=users&sort=name;DROP%20TABLE%20users&dir=desc');
        $this->assertSame(200, $crafted->status);
        $this->assertTrue($crafted->contains('Alpha Name'), 'The crafted sort is ignored and the list renders.');
        $this->assertSame('2', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM users WHERE username IN ('alpha','zulu')")->fetchColumn());
    }

    public function test_the_users_list_paginates(): void
    {
        $admin = $this->adminSession();
        for ($i = 1; $i <= 22; $i++) {
            $this->createUser($admin, 'pageuser' . str_pad((string)$i, 2, '0', STR_PAD_LEFT));
        }

        $page1 = $admin->get('/?page=users');
        $this->assertTrue($page1->contains('23 users'), 'The header counts all users.');
        $this->assertTrue($page1->contains('Page 1 of 2'));
        $this->assertTrue($page1->contains('pageuser01'));

        $page2 = $admin->get('/?page=users&p=2');
        $this->assertTrue($page2->contains('pageuser22'), 'The last rows land on page 2.');
        $this->assertFalse($page2->contains('pageuser01'), 'Page 1 rows stay on page 1.');
    }

    public function test_the_search_sort_and_pagination_combine(): void
    {
        $admin = $this->adminSession();
        $this->createUser($admin, 'combine');
        $this->createUser($admin, 'unrelated');

        // The sort header links keep the active search term.
        $page = $admin->get('/?page=users&q=combine');
        $this->assertTrue($page->contains('amp;sort=name'), 'The header link carries the search.');
        $this->assertTrue($page->contains('Combine Name'));

        $sorted = $admin->get('/?page=users&q=combine&sort=username&dir=asc');
        $this->assertTrue($sorted->contains('page=users&amp;q=combine'), 'The pagination and headers keep the search.');
    }

    public function test_the_realtime_region_and_crud_forms_stay_intact(): void
    {
        $admin = $this->adminSession();

        $page = $admin->get('/?page=users');
        $this->assertSame(1, substr_count($page->body, '<div id="tb-list-region" data-tb-refresh="10">'), 'Exactly one realtime region (#51).');
        $createForm = (int)strpos($page->body, 'value="add_user"');
        $region = (int)strpos($page->body, '<div id="tb-list-region"');
        $this->assertTrue($createForm !== 0 && $createForm < $region, 'The create form stays outside the swapped region.');
        $this->assertTrue($page->contains('Search username or display name'), 'The search bar renders inside the region.');
    }
}
