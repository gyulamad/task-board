<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TaskBoard\Tests\Support\SqlAliasScanner;

/**
 * Task #102 regression guard: production runs MariaDB, the suite runs
 * SQLite, and only MariaDB rejects an aggregate alias referenced inside an
 * ORDER BY expression (fatal ER_ILLEGAL_REFERENCE "Reference '...' not
 * supported (reference to group function)"). The messages-page query that
 * shipped in #94 read "ORDER BY last_id IS NULL, last_id DESC" - green on
 * SQLite, fatal on production. These tests pin both directions: the scanner
 * still flags the exact incident query, and no SQL literal in the deployed
 * files carries the hazard any more.
 */
final class SqlAliasGuardTest extends TestCase
{
    /** The exact pre-fix messages query from the 2026-11-24 outage. */
    private const INCIDENT_QUERY = 'SELECT u.id, u.display_name, u.last_seen_at, MAX(m.id) last_id FROM users u LEFT JOIN messages m ON (m.sender_id=u.id AND m.recipient_id=?) OR (m.recipient_id=u.id AND m.sender_id=?) WHERE u.id<>? GROUP BY u.id, u.display_name, u.last_seen_at ORDER BY last_id IS NULL, last_id DESC, u.display_name, u.id';

    /** The shipped fix: repeat the aggregate instead of the alias. */
    private const FIXED_QUERY = 'SELECT u.id, u.display_name, u.last_seen_at, MAX(m.id) last_id FROM users u LEFT JOIN messages m ON (m.sender_id=u.id AND m.recipient_id=?) OR (m.recipient_id=u.id AND m.sender_id=?) WHERE u.id<>? GROUP BY u.id, u.display_name, u.last_seen_at ORDER BY MAX(m.id) IS NULL, MAX(m.id) DESC, u.display_name, u.id';

    public function test_the_incident_query_from_task_102_is_flagged(): void
    {
        $violations = SqlAliasScanner::violations(self::INCIDENT_QUERY);
        $this->assertCount(1, $violations, json_encode($violations, JSON_PRETTY_PRINT));
        $this->assertStringContainsString('last_id', $violations[0]);
        $this->assertStringContainsString('MAX', $violations[0]);
    }

    public function test_a_bare_alias_term_stays_allowed(): void
    {
        $bare = str_replace(
            'ORDER BY last_id IS NULL, last_id DESC',
            'ORDER BY last_id DESC',
            self::INCIDENT_QUERY
        );
        $this->assertSame([], SqlAliasScanner::violations($bare));
    }

    public function test_the_messages_query_fix_stays_clean(): void
    {
        $this->assertSame([], SqlAliasScanner::violations(self::FIXED_QUERY));
    }

    public function test_an_alias_of_a_plain_column_is_not_a_hazard(): void
    {
        $sql = 'SELECT u.id AS last_id FROM users u ORDER BY last_id IS NULL, last_id DESC';
        // Only aliases OF AGGREGATES hit the MariaDB restriction; a plain
        // column alias may be used in expressions freely on every dialect.
        $this->assertSame([], SqlAliasScanner::violations($sql));
    }

    public function test_the_codebase_carries_no_aggregate_alias_inside_order_by_expressions(): void
    {
        $violations = [];
        foreach (SqlAliasScanner::codebaseFiles() as $file) {
            foreach (SqlAliasScanner::sqlStrings((string)file_get_contents($file)) as $sql) {
                foreach (SqlAliasScanner::violations($sql) as $violation) {
                    $violations[] = basename($file) . ': ' . $violation;
                }
            }
        }
        $this->assertSame([], $violations, "\n" . implode("\n", $violations));
    }
}
