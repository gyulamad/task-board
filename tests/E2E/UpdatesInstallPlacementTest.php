<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

/**
 * Task #90 - the Software updates page must keep the confirm-and-install
 * step where the administrator can see it. When a source check finds an
 * update, the install section (password field + red "Back up and install
 * update" button) renders in a red-bordered callout directly under the
 * Check source form - not at the bottom of the long page below the backup,
 * migration and pack sections, where it went unnoticed.
 *
 * The install section renders whenever a source is pinned ($sourceInfo),
 * both after a found update (check_update) and after a failed install
 * attempt (install_update keeps the pinned source so the retry form stays).
 * The failed-attempt path is used for the deterministic offline scenario:
 * the source is pinned locally before the download starts, so a download
 * that fails for any reason (404, offline, rate limit) still renders the
 * section - no GitHub API dependency in this test.
 */
final class UpdatesInstallPlacementTest extends E2eTestCase
{
    public function test_updates_page_has_no_install_section_before_a_source_is_pinned(): void
    {
        $page = $this->adminSession()->get('/?page=updates');

        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('name="action" value="check_update"'), 'The check form must be present.');
        $this->assertFalse($page->contains('Back up and install update'), 'Without a pinned source no install section is offered.');
        $this->assertFalse($page->contains('id="pending-install"'), 'Without a pinned source the install callout is not rendered.');
    }

    public function test_install_section_renders_directly_under_the_check_form(): void
    {
        // A release ref that passes the local pin validation but cannot be
        // downloaded: the install action fails and re-renders the page with
        // the pinned source visible for a retry (#90 placement).
        $response = $this->adminSession()->post('/?page=updates', [
            'action' => 'install_update',
            'source_type' => 'release',
            'source_ref' => 'v0.0.0-no-such-tag',
            'branch' => 'master',
            'current_password' => 'AdminPass1234',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Could not download the update'), 'The failed download must be reported.');
        $this->assertTrue($response->contains('id="pending-install"'), 'The install callout must render for the pinned source.');
        $this->assertTrue($response->contains('name="action" value="install_update"'), 'The install form must be offered again.');
        $this->assertTrue($response->contains('Back up and install update'), 'The red install button must be present.');

        // Placement (#90): the callout sits after the check form it belongs
        // to and above the standalone sections - on screen it appears right
        // under the Check source form instead of at the page bottom.
        $checkFormAt = strpos($response->body, 'name="action" value="check_update"');
        $calloutAt = strpos($response->body, 'id="pending-install"');
        $backupAt = strpos($response->body, 'Download a full backup');
        $this->assertNotFalse($checkFormAt);
        $this->assertNotFalse($calloutAt);
        $this->assertNotFalse($backupAt);
        $this->assertGreaterThan($checkFormAt, $calloutAt, 'The install callout must follow the check form.');
        $this->assertLessThan($backupAt, $calloutAt, 'The install callout must render above the backup section - directly under the check form.');
    }

    public function test_found_update_via_check_keeps_the_install_section_next_to_the_check_form(): void
    {
        // The reporter's exact flow: check the develop branch; when the API
        // is reachable the page shows "Update found" and the install callout
        // in one view. Rate-limited or offline environments skip this one -
        // the deterministic placement above still pins the markup.
        $response = $this->adminSession()->post('/?page=updates', [
            'action' => 'check_update',
            'source_type' => 'branch',
            'branch' => 'develop',
        ]);

        if ($response->contains('Could not download the update') || $response->contains('could not be found')) {
            $this->markTestSkipped('The GitHub API is not reachable from this environment.');
        }

        $this->assertSame(200, $response->status);
        $this->assertTrue($response->contains('Update found:'), 'The develop branch check must find an update.');
        $this->assertTrue($response->contains('id="pending-install"'), 'The install callout must render after a found update.');
        $calloutAt = strpos($response->body, 'id="pending-install"');
        $backupAt = strpos($response->body, 'Download a full backup');
        $this->assertLessThan($backupAt, $calloutAt, 'The install callout stays above the backup section - next to the check form.');
    }
}
