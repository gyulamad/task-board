<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';

/**
 * Task #110: the relative "how long ago" suffix and the tb_ago() render.
 * Pure helper tests - no server, no database: the clock, the zone and the
 * format are pinned explicitly so every bucket boundary is deterministic.
 */
final class RelativeTimeTest extends TestCase
{
    private const NOW = 1764000000;

    public function test_the_first_minute_renders_just_now(): void
    {
        $this->assertSame('just now', tb_ago_suffix(self::NOW - 0, self::NOW));
        $this->assertSame('just now', tb_ago_suffix(self::NOW - 59, self::NOW));
        $this->assertSame('just now', tb_ago_suffix(self::NOW, self::NOW));
    }

    public function test_minutes_bucket_with_proper_pluralization(): void
    {
        $this->assertSame('1 minute ago', tb_ago_suffix(self::NOW - 60, self::NOW));
        $this->assertSame('15 minutes ago', tb_ago_suffix(self::NOW - 900, self::NOW));
        $this->assertSame('59 minutes ago', tb_ago_suffix(self::NOW - 3599, self::NOW));
    }

    public function test_hours_bucket_with_proper_pluralization(): void
    {
        $this->assertSame('1 hour ago', tb_ago_suffix(self::NOW - 3600, self::NOW));
        $this->assertSame('2 hours ago', tb_ago_suffix(self::NOW - 7200, self::NOW));
        $this->assertSame('23 hours ago', tb_ago_suffix(self::NOW - 86399, self::NOW));
    }

    public function test_days_bucket_with_proper_pluralization(): void
    {
        $this->assertSame('1 day ago', tb_ago_suffix(self::NOW - 86400, self::NOW));
        $this->assertSame('2 days ago', tb_ago_suffix(self::NOW - 172800, self::NOW));
        $this->assertSame('6 days ago', tb_ago_suffix(self::NOW - 6 * 86400, self::NOW));
    }

    public function test_weeks_bucket_with_proper_pluralization(): void
    {
        $this->assertSame('1 week ago', tb_ago_suffix(self::NOW - 7 * 86400, self::NOW));
        $this->assertSame('2 weeks ago', tb_ago_suffix(self::NOW - 15 * 86400, self::NOW));
        $this->assertSame('4 weeks ago', tb_ago_suffix(self::NOW - 29 * 86400, self::NOW));
    }

    public function test_months_bucket_with_proper_pluralization(): void
    {
        $this->assertSame('1 month ago', tb_ago_suffix(self::NOW - 30 * 86400, self::NOW));
        $this->assertSame('1 month ago', tb_ago_suffix(self::NOW - 45 * 86400, self::NOW));
        $this->assertSame('2 months ago', tb_ago_suffix(self::NOW - 60 * 86400, self::NOW));
        $this->assertSame('12 months ago', tb_ago_suffix(self::NOW - 364 * 86400, self::NOW));
    }

    public function test_years_bucket_with_proper_pluralization(): void
    {
        $this->assertSame('1 year ago', tb_ago_suffix(self::NOW - 365 * 86400, self::NOW));
        $this->assertSame('2 years ago', tb_ago_suffix(self::NOW - 800 * 86400, self::NOW));
    }

    public function test_a_stamp_that_was_never_set_has_no_suffix(): void
    {
        // NULL/0 render the bare 'never' marker through tb_dt(); the suffix
        // must stay empty so 'never (2 days ago)' can never exist.
        $this->assertSame('', tb_ago_suffix(null, self::NOW));
        $this->assertSame('', tb_ago_suffix(0, self::NOW));
    }

    public function test_a_future_stamp_has_no_suffix(): void
    {
        // The scheduler's next-run moment is not 'ago' anything - the absolute
        // time already says when it happens, so tb_ago() renders it bare.
        $this->assertSame('', tb_ago_suffix(self::NOW + 1, self::NOW));
        $this->assertSame('', tb_ago_suffix(self::NOW + 3600, self::NOW));
    }

    public function test_without_a_pinned_clock_the_shared_default_clock_is_used(): void
    {
        $this->assertSame('just now', tb_ago_suffix(time()));
        $this->assertSame('15 minutes ago', tb_ago_suffix(time() - 900));
    }

    public function test_tb_ago_appends_the_suffix_to_the_formatted_datetime(): void
    {
        $ts = self::NOW - 900;
        $absolute = (new \DateTime('@' . $ts))->format('Y-m-d H:i:s'); // UTC
        $this->assertSame(
            $absolute . ' (15 minutes ago)',
            tb_ago($ts, null, new \DateTimeZone('UTC'), self::NOW)
        );
    }

    public function test_tb_ago_honours_a_pinned_format_and_zone(): void
    {
        $ts = self::NOW - 900;
        $expected = (new \DateTime('@' . $ts))
            ->setTimezone(new \DateTimeZone('Europe/Budapest'))
            ->format('d/m/Y H:i');
        $this->assertSame(
            $expected . ' (15 minutes ago)',
            tb_ago($ts, null, new \DateTimeZone('Europe/Budapest'), self::NOW, 'd/m/Y H:i')
        );
    }

    public function test_tb_ago_renders_never_without_a_suffix(): void
    {
        $this->assertSame('never', tb_ago(null));
        $this->assertSame('never', tb_ago(0));
    }

    public function test_tb_ago_renders_a_future_stamp_bare(): void
    {
        $future = self::NOW + 7200;
        $expected = (new \DateTime('@' . $future))->format('Y-m-d H:i:s');
        $this->assertSame($expected, tb_ago($future, null, new \DateTimeZone('UTC'), self::NOW));
    }

    public function test_tb_dt_honours_an_explicit_format(): void
    {
        $ts = 1763900000;
        $expected = (new \DateTime('@' . $ts))->format('d.m.Y H:i');
        $this->assertSame($expected, tb_dt($ts, null, new \DateTimeZone('UTC'), 'd.m.Y H:i'));
        // The default format stays byte-identical to the classic board shape.
        $this->assertSame(
            (new \DateTime('@' . $ts))->format('Y-m-d H:i:s'),
            tb_dt($ts, null, new \DateTimeZone('UTC'))
        );
    }

    public function test_the_format_lookup_without_a_session_falls_back_to_the_default(): void
    {
        // No PDO, no signed-in user (unit context): the classic default.
        $this->assertSame('Y-m-d H:i:s', tb_user_datetime_format(null));
    }

    public function test_the_format_allowlist_keeps_the_default_first(): void
    {
        $formats = tb_datetime_formats();
        reset($formats);
        $this->assertSame(TB_DATETIME_FORMAT_DEFAULT, (string)key($formats), 'The first entry is the default.');
        // Every entry must stay a renderable date() shape - the labels double
        // as the Settings select options, so a broken entry is visible there.
        foreach (array_keys($formats) as $fmt) {
            $this->assertNotFalse((new \DateTime('@' . self::NOW))->format((string)$fmt));
        }
    }
}
