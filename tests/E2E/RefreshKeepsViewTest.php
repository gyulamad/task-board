<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use TaskBoard\Tests\Support\AppSession;

/**
 * Task #155: a replayed view lands on a bare URL, and a REFRESH of that URL
 * was treated as a consecutive bare visit (no replay, the pre-#113 rule) -
 * so the picked saved search "disappeared" on refresh and came back only
 * after a trip elsewhere, exactly the asymmetric behaviour the report
 * describes. The fix keeps every server-side replay rule untouched: the
 * replayed page now hands the effective merged view to the footer and a
 * one-line script rewrites the address bar to it once on load, so a refresh
 * re-requests the full URL and the view simply stays. The #113 second-click
 * escape and the #126 poll rule are pinned unchanged.
 */
final class RefreshKeepsViewTest extends E2eTestCase
{
    private const REWRITE_MARKER = 'history.replaceState';

    public function test_a_replayed_bare_visit_rewrites_the_address_bar_to_the_merged_view(): void
    {
        $admin = $this->adminSession();
        $alpha = $admin->createTask('Alpha search token');
        $admin->createTask('Beta item');

        $admin->get('/?page=tasks&q=Alpha+search');
        $admin->taskPage($alpha);
        $replayed = $admin->get('/?page=tasks');
        $this->assertSame(200, $replayed->status);
        $this->assertTrue($replayed->contains('value="Alpha search"'), 'The stored view still replays (#113).');
        $this->assertTrue($replayed->contains(self::REWRITE_MARKER), 'The replayed page carries the address-bar rewrite.');
        $this->assertTrue($replayed->contains('var q="page=tasks\u0026q=Alpha+search"'), 'The rewrite carries the whole merged view, page key included (& is JSON-hex-escaped inside the script).');
    }

    public function test_a_refresh_of_the_rewritten_url_keeps_the_filter(): void
    {
        // The browser-side contract end to end: after the rewrite the address
        // bar holds the merged view, so the F5 re-request rides its explicit
        // params (no replay needed) and the filter renders again.
        $admin = $this->adminSession();
        $alpha = $admin->createTask('Alpha search token');
        $admin->createTask('Beta item');

        $admin->get('/?page=tasks&q=Alpha+search');
        $admin->taskPage($alpha);
        $replayed = $admin->get('/?page=tasks');
        $this->assertStringContainsString('var q="page=tasks&q=Alpha+search"', $replayed->body, 'Precondition: the replay rewrites to the merged view.');

        $refreshed = $admin->get('/?page=tasks&q=Alpha+search');
        $this->assertTrue($refreshed->contains('#' . $alpha . ' · Alpha search token'), 'The refresh keeps the filter.');
        $this->assertFalse(str_contains($refreshed->body, self::REWRITE_MARKER), 'The visitor\'s own URL is honest already - no rewrite script.');
    }

    public function test_a_kanban_replay_rewrites_the_bar_and_the_refresh_keeps_the_columns(): void
    {
        $admin = $this->adminSession();
        $admin->createTask('Todo item');
        $admin->createTask('Progress item', ['status_id' => '2']);

        $admin->get('/?page=kanban&status[]=1');
        $admin->taskPage(1);
        $replayed = $admin->get('/?page=kanban');
        $this->assertTrue($replayed->contains('var q="page=kanban\u0026status%5B0%5D=1"'), 'The kanban rewrite carries the status filter (& is JSON-hex-escaped inside the script).');
        $this->assertFalse($replayed->contains($this->columnHeader('IN PROGRESS')), 'The replayed board is still filtered.');

        // The F5 after the rewrite re-requests the rewritten URL.
        $refreshed = $admin->get('/?page=kanban&status[]=1');
        $this->assertTrue($refreshed->contains($this->columnHeader('TODO')), 'The refresh keeps the columns.');
        $this->assertFalse($refreshed->contains($this->columnHeader('IN PROGRESS')), 'The refresh does not unfilter the board.');
    }

    public function test_own_params_and_plain_visits_carry_no_rewrite_script(): void
    {
        $admin = $this->adminSession();
        $admin->createTask('Beta item');

        // The visitor's own URL already says what it renders.
        $own = $admin->get('/?page=tasks&q=Beta');
        $this->assertFalse(str_contains($own->body, self::REWRITE_MARKER), 'No rewrite on the visitor\'s own filtered URL.');

        // A pristine view has nothing to rewrite either.
        $plain = $admin->get('/?page=tasks');
        $this->assertFalse(str_contains($plain->body, self::REWRITE_MARKER), 'No rewrite on the plain list.');

        // And the #113 escape stays: the consecutive bare visit renders plain
        // and carries no rewrite, exactly like before #155.
        $admin->get('/?page=tasks&q=Beta');
        $consecutive = $admin->get('/?page=tasks');
        $this->assertTrue($consecutive->contains('Beta item'), 'The consecutive bare visit still renders the plain list (#113).');
        $this->assertFalse(str_contains($consecutive->body, self::REWRITE_MARKER), 'No rewrite on a consecutive bare visit - the escape stays an escape.');
    }

    public function test_the_rewritten_query_is_url_encoded_and_script_safe(): void
    {
        $admin = $this->adminSession();
        $admin->createTask('Beta item');

        // A search term that would break out of a naive inline script: the
        // query rides URL-encoded and the JSON encoding hex-escapes the rest,
        // so the rewrite stays data and never becomes markup.
        $admin->get('/?page=tasks&q=' . rawurlencode('</script><script>alert(1)</script>'));
        $admin->taskPage(1);
        $replayed = $admin->get('/?page=tasks');
        $this->assertTrue($replayed->contains(self::REWRITE_MARKER), 'The nasty term still replays with the rewrite.');
        $this->assertFalse(str_contains($replayed->body, '</script><script>alert'), 'No raw script breakout in the rewrite.');
        $this->assertTrue($replayed->contains('%3C%2Fscript%3E'), 'The term rides URL-encoded inside the query.');
    }

    private function columnHeader(string $statusName): string
    {
        // The same header shape KanbanBoardTest pins (#66 markup).
        return '<div class="card-header bg-transparent d-flex justify-content-between align-items-center py-2">'
            . '<div class="d-flex align-items-center gap-2"><span class="badge text-bg-light border">' . $statusName . '</span>';
    }
}
