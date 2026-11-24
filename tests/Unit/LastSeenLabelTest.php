<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/helpers.php';

/**
 * Task #30: the 'active: 23 minutes ago' label of the admin page's Last active
 * column. The formatter is a pure function, so every wording rule is pinned
 * here with a fixed clock; $now is what the page feeds it (the database's own
 * current timestamp, as a unix timestamp — see tb_last_seen_label()).
 */
final class LastSeenLabelTest extends TestCase
{
    private const NOW = 1700000000;

    private function label(int|string|null $lastSeen, ?int $now = null): string
    {
        return tb_last_seen_label($lastSeen, $now ?? self::NOW);
    }

    public function test_a_user_never_seen_reads_never(): void
    {
        $this->assertSame('never', $this->label(null));
        $this->assertSame('never', $this->label(''));
    }

    public function test_a_stamp_less_than_a_minute_old_reads_just_now(): void
    {
        $this->assertSame('just now', $this->label(self::NOW - 30));
        $this->assertSame('just now', $this->label(self::NOW));
    }

    public function test_minutes_read_singular_and_plural_like_the_task_example(): void
    {
        $this->assertSame('1 minute ago', $this->label(self::NOW - 60));
        $this->assertSame('23 minutes ago', $this->label(self::NOW - 23 * 60));
        $this->assertSame('59 minutes ago', $this->label(self::NOW - 59 * 60));
    }

    public function test_hours_read_like_the_task_example(): void
    {
        $this->assertSame('1h 12m ago', $this->label(self::NOW - 72 * 60));
        $this->assertSame('2h ago', $this->label(self::NOW - 2 * 3600));
        $this->assertSame('23h 59m ago', $this->label(self::NOW - (23 * 3600 + 59 * 60)));
    }

    public function test_days_carry_hours_for_a_month_then_fall_back_to_plain_days(): void
    {
        $this->assertSame('1d ago', $this->label(self::NOW - 86400));
        $this->assertSame('3d 4h ago', $this->label(self::NOW - (3 * 86400 + 4 * 3600)));
        $this->assertSame('29d 23h ago', $this->label(self::NOW - (29 * 86400 + 23 * 3600)));
        $this->assertSame('45d ago', $this->label(self::NOW - 45 * 86400));
    }

    public function test_a_stamp_in_the_future_never_reads_negative(): void
    {
        $this->assertSame('just now', $this->label(self::NOW + 3600));
    }

    public function test_an_unparsable_stamp_reads_unknown(): void
    {
        $this->assertSame('unknown', $this->label(-5));
    }
}
