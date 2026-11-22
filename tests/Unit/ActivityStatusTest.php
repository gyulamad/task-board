<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use TaskBoard\Tests\Support\Schema;

require_once dirname(__DIR__, 2) . '/src/helpers.php';

/**
 * Task #30 (v2): the reusable activity-status rendering — the traffic-light
 * level behind the dot (green = seen within 15 minutes, yellow = 15-60
 * minutes, red = longer or never), the dot markup, the standard user-name
 * renderer, and the session refresh inside tb_touch_last_seen() that keeps the
 * navbar dot truthful. Clock-dependent cases run with a pinned $now; the
 * tb_touch_last_seen() case runs against a throwaway SQLite database built
 * from schema.sql — no web server is booted.
 */
final class ActivityStatusTest extends TestCase
{
    private const NOW = 1700000000;

    private function level(?string $lastSeen): string
    {
        return tb_activity_level($lastSeen, self::NOW);
    }

    private function stamp(int $secondsAgo): string
    {
        return date('Y-m-d H:i:s', self::NOW - $secondsAgo);
    }

    public function test_within_fifteen_minutes_is_green(): void
    {
        $this->assertSame('green', $this->level($this->stamp(0)));
        $this->assertSame('green', $this->level($this->stamp(15 * 60)));
        $this->assertSame('green', $this->level($this->stamp(30)));
    }

    public function test_between_fifteen_minutes_and_an_hour_is_yellow(): void
    {
        $this->assertSame('yellow', $this->level($this->stamp(15 * 60 + 1)));
        $this->assertSame('yellow', $this->level($this->stamp(45 * 60)));
        $this->assertSame('yellow', $this->level($this->stamp(60 * 60)));
    }

    public function test_over_an_hour_is_red(): void
    {
        $this->assertSame('red', $this->level($this->stamp(60 * 60 + 1)));
        $this->assertSame('red', $this->level($this->stamp(25 * 3600)));
    }

    public function test_a_user_never_seen_or_unparsable_is_red(): void
    {
        $this->assertSame('red', $this->level(null));
        $this->assertSame('red', $this->level(''));
        $this->assertSame('red', $this->level('not a date'));
    }

    public function test_a_stamp_in_the_future_never_reads_negative(): void
    {
        $this->assertSame('green', $this->level(date('Y-m-d H:i:s', self::NOW + 3600)));
    }

    public function test_the_dot_carries_its_level_class_and_a_title(): void
    {
        $this->assertSame('<span class="tb-activity-dot tb-activity-green" title="Active in the last 15 minutes"></span>', tb_activity_dot($this->stamp(60), self::NOW));
        $this->assertSame('<span class="tb-activity-dot tb-activity-yellow" title="Not active in the last hour"></span>', tb_activity_dot($this->stamp(30 * 60), self::NOW));
        $this->assertSame('<span class="tb-activity-dot tb-activity-red" title="Inactive for over an hour"></span>', tb_activity_dot(null, self::NOW));
    }

    public function test_the_standard_name_renderer_puts_the_dot_before_the_escaped_name(): void
    {
        $html = tb_user_with_activity(['display_name' => 'Jane <b>Doe</b>', 'last_seen_at' => $this->stamp(60)], self::NOW);
        $this->assertSame('<span class="tb-activity-dot tb-activity-green" title="Active in the last 15 minutes"></span>Jane &lt;b&gt;Doe&lt;/b&gt;', $html);
    }

    public function test_a_deleted_user_renders_as_a_plain_name_without_a_dot(): void
    {
        $this->assertSame('Deleted user', tb_user_with_activity(null, self::NOW));
        $this->assertSame('Deleted user', tb_user_with_activity(['display_name' => null], self::NOW));
        $this->assertSame('Deleted user', tb_user_with_activity(['display_name' => ''], self::NOW));
    }

    public function test_touching_last_seen_refreshes_the_session_copy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $dbFile = sys_get_temp_dir() . '/task-board-activity-' . getmypid() . '-' . uniqid() . '.sqlite';
        Schema::createSqlite($dbFile);
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $pdo->exec("INSERT INTO users(id,username,password_hash,display_name,role) VALUES(1,'jane','x','Jane Doe','user')");
            $_SESSION['user'] = ['id' => 1, 'username' => 'jane', 'display_name' => 'Jane Doe', 'role' => 'user', 'last_seen_at' => null];

            tb_touch_last_seen($pdo);
            $s = $pdo->prepare('SELECT last_seen_at FROM users WHERE id=1');
            $s->execute();
            $stored = $s->fetchColumn();
            $this->assertNotNull($stored, 'The first touch must stamp the column.');
            // The session learns the fresh stamp; the column and the session
            // copy come from two CURRENT_TIMESTAMP reads, so allow a second
            // boundary straddle instead of demanding byte equality.
            $this->assertNotFalse(strtotime((string)$_SESSION['user']['last_seen_at']), 'The session copy must learn the fresh stamp.');
            $this->assertLessThanOrEqual(
                2,
                abs(strtotime((string)$_SESSION['user']['last_seen_at']) - strtotime((string)$stored)),
                'The session copy must be the stamp the touch just wrote.'
            );
        } finally {
            unset($pdo);
            @unlink($dbFile);
            $_SESSION = [];
        }
    }
}
