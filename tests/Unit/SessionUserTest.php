<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';

/**
 * Unit tests for tb_session_user(): the single place that shapes the
 * $_SESSION['user'] payload, so a rebuilt session always carries the theme
 * preference and every other field (#61).
 *
 * These run without any server or database.
 */
final class SessionUserTest extends TestCase
{
    public function test_builds_the_session_payload_from_a_users_row(): void
    {
        $row = ['id' => '7', 'username' => 'root', 'display_name' => 'Root', 'role' => 'admin', 'theme' => 'dark', 'last_seen_at' => '2026-11-23 07:00:00'];
        $this->assertSame(
            ['id' => 7, 'username' => 'root', 'display_name' => 'Root', 'role' => 'admin', 'theme' => 'dark', 'last_seen_at' => '2026-11-23 07:00:00'],
            tb_session_user($row)
        );
    }

    public function test_normalizes_a_missing_theme_instead_of_dropping_it(): void
    {
        $row = ['id' => 7, 'username' => 'root', 'display_name' => 'Root', 'role' => 'admin'];
        $payload = tb_session_user($row);
        $this->assertSame('light', $payload['theme'], 'an absent theme falls back to light, but the key is always present (#61)');
        $this->assertNull($payload['last_seen_at']);
    }
}
