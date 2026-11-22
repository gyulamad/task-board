<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use TaskBoard\Tests\Support\Schema;

require_once dirname(__DIR__, 2) . '/src/helpers.php';

/**
 * Task #34: the configurable activity periods. The dot next to user names is
 * driven by a period list an administrator can edit on the settings page —
 * which age range renders as which colour or character. Everything decision
 * shaped is pure here (parsing, validation, bucketing, rendering); the
 * storage cases run against a throwaway SQLite database built from
 * schema.sql, no web server booted.
 */
final class ActivityLevelsTest extends TestCase
{
    private const NOW = 1700000000;

    private function stamp(int $secondsAgo): string
    {
        return date('Y-m-d H:i:s', self::NOW - $secondsAgo);
    }

    protected function setUp(): void
    {
        // The request-wide configuration must not leak between tests: every
        // case that needs one sets it explicitly, the rest expect defaults.
        unset($GLOBALS['TB_ACTIVITY_LEVELS']);
    }

    // ── the default periods keep the #30 behaviour byte-identical ──

    public function test_the_default_periods_are_the_pre_34_traffic_light(): void
    {
        $this->assertSame([
            ['until_minutes' => 15, 'color' => 'green', 'character' => null],
            ['until_minutes' => 60, 'color' => 'yellow', 'character' => null],
            ['until_minutes' => null, 'color' => 'red', 'character' => null],
        ], tb_activity_levels_default());
    }

    public function test_with_the_defaults_the_level_is_exactly_the_30_scale(): void
    {
        $this->assertSame('green', tb_activity_level($this->stamp(0), self::NOW));
        $this->assertSame('green', tb_activity_level($this->stamp(15 * 60), self::NOW));
        $this->assertSame('yellow', tb_activity_level($this->stamp(15 * 60 + 1), self::NOW));
        $this->assertSame('yellow', tb_activity_level($this->stamp(60 * 60), self::NOW));
        $this->assertSame('red', tb_activity_level($this->stamp(60 * 60 + 1), self::NOW));
        $this->assertSame('red', tb_activity_level(null, self::NOW));
        $this->assertSame('red', tb_activity_level('not a date', self::NOW));
    }

    public function test_with_the_defaults_the_dot_markup_is_byte_identical_to_30(): void
    {
        $this->assertSame('<span class="tb-activity-dot tb-activity-green" title="Active in the last 15 minutes"></span>', tb_activity_dot($this->stamp(60), self::NOW));
        $this->assertSame('<span class="tb-activity-dot tb-activity-yellow" title="Not active in the last hour"></span>', tb_activity_dot($this->stamp(30 * 60), self::NOW));
        $this->assertSame('<span class="tb-activity-dot tb-activity-red" title="Inactive for over an hour"></span>', tb_activity_dot(null, self::NOW));
    }

    // ── configured periods: bucketing with a grey long tail ──

    /** green 0-30, yellow 30-120, red 120-24h, grey forever (the task's red -> empty grey). */
    private function fourLevels(): array
    {
        return [
            ['until_minutes' => 30, 'color' => 'green', 'character' => null],
            ['until_minutes' => 120, 'color' => 'yellow', 'character' => null],
            ['until_minutes' => 1440, 'color' => 'red', 'character' => null],
            ['until_minutes' => null, 'color' => 'grey', 'character' => null],
        ];
    }

    public function test_a_custom_period_list_buckets_by_its_own_bounds(): void
    {
        $levels = $this->fourLevels();
        $this->assertSame('green', tb_activity_level($this->stamp(30 * 60), self::NOW, $levels));
        $this->assertSame('yellow', tb_activity_level($this->stamp(31 * 60), self::NOW, $levels));
        $this->assertSame('yellow', tb_activity_level($this->stamp(2 * 3600), self::NOW, $levels));
        $this->assertSame('red', tb_activity_level($this->stamp(2 * 3600 + 1), self::NOW, $levels));
        // the empty grey: seen more than a day ago, and never seen at all
        $this->assertSame('grey', tb_activity_level($this->stamp(24 * 3600 + 1), self::NOW, $levels));
        $this->assertSame('grey', tb_activity_level(null, self::NOW, $levels));
    }

    public function test_the_bucket_index_hits_every_boundary_exactly(): void
    {
        $levels = $this->fourLevels();
        $this->assertSame(0, tb_activity_bucket($this->stamp(30 * 60), self::NOW, $levels));
        $this->assertSame(1, tb_activity_bucket($this->stamp(30 * 60 + 1), self::NOW, $levels));
        $this->assertSame(2, tb_activity_bucket($this->stamp(120 * 60 + 1), self::NOW, $levels));
        $this->assertSame(3, tb_activity_bucket($this->stamp(1440 * 60 + 1), self::NOW, $levels));
    }

    public function test_a_future_stamp_never_reads_negative_and_lands_in_the_first_period(): void
    {
        $this->assertSame('green', tb_activity_level(date('Y-m-d H:i:s', self::NOW + 3600), self::NOW, $this->fourLevels()));
    }

    // ── rendering: colours and characters ──

    public function test_the_dot_uses_the_configured_grey_class_and_generated_titles(): void
    {
        $levels = $this->fourLevels();
        $this->assertSame('<span class="tb-activity-dot tb-activity-green" title="Seen within the last 30 minutes"></span>', tb_activity_dot($this->stamp(10), self::NOW, $levels));
        $this->assertSame('<span class="tb-activity-dot tb-activity-yellow" title="Seen within the last 2 hours"></span>', tb_activity_dot($this->stamp(90 * 60), self::NOW, $levels));
        $this->assertSame('<span class="tb-activity-dot tb-activity-red" title="Seen within the last day"></span>', tb_activity_dot($this->stamp(3 * 3600), self::NOW, $levels));
        $this->assertSame('<span class="tb-activity-dot tb-activity-grey" title="Last seen longer ago than every period above"></span>', tb_activity_dot(null, self::NOW, $levels));
    }

    public function test_a_character_period_renders_its_escaped_glyph_instead_of_a_disc(): void
    {
        $levels = [
            ['until_minutes' => 15, 'color' => 'green', 'character' => null],
            ['until_minutes' => null, 'color' => null, 'character' => 'x'],
        ];
        // the fresh stamp sits in the coloured first period, the old one in the glyph period
        $this->assertSame('<span class="tb-activity-dot tb-activity-green" title="Seen within the last 15 minutes"></span>', tb_activity_dot($this->stamp(10), self::NOW, $levels));
        $this->assertSame('<span class="tb-activity-char" title="Last seen longer ago than every period above">x</span>', tb_activity_dot($this->stamp(16 * 60), self::NOW, $levels));
    }

    public function test_a_hostile_character_is_escaped(): void
    {
        $levels = [
            ['until_minutes' => null, 'color' => null, 'character' => '<b>'],
        ];
        $this->assertSame('<span class="tb-activity-char" title="Last seen longer ago than every period above">&lt;b&gt;</span>', tb_activity_dot(null, self::NOW, $levels));
    }

    public function test_a_character_only_period_reports_red_as_its_level_so_colour_callers_keep_working(): void
    {
        $levels = [
            ['until_minutes' => 15, 'color' => 'green', 'character' => null],
            ['until_minutes' => null, 'color' => null, 'character' => 'x'],
        ];
        $this->assertSame('red', tb_activity_level(null, self::NOW, $levels));
    }

    // ── validation ──

    public function test_a_well_formed_period_list_passes_validation(): void
    {
        $this->assertNull(tb_activity_levels_validation_error($this->fourLevels()));
        $this->assertNull(tb_activity_levels_validation_error(tb_activity_levels_default()));
        $this->assertNull(tb_activity_levels_validation_error([
            ['until_minutes' => 1, 'color' => 'green', 'character' => null],
            ['until_minutes' => null, 'color' => null, 'character' => '·'],
        ]));
    }

    public function test_validation_rejects_broken_lists(): void
    {
        $one = [['until_minutes' => null, 'color' => 'red', 'character' => null]];
        $this->assertNotNull(tb_activity_levels_validation_error($one), 'one period');
        $this->assertNotNull(tb_activity_levels_validation_error([]), 'empty');

        $six = [];
        foreach ([10, 20, 30, 40, 50] as $m) {
            $six[] = ['until_minutes' => $m, 'color' => 'green', 'character' => null];
        }
        $six[] = ['until_minutes' => null, 'color' => 'red', 'character' => null];
        $this->assertNotNull(tb_activity_levels_validation_error($six), 'six periods');

        $noBound = [
            ['until_minutes' => null, 'color' => 'green', 'character' => null],
            ['until_minutes' => null, 'color' => 'red', 'character' => null],
        ];
        $this->assertNotNull(tb_activity_levels_validation_error($noBound), 'a non-last period without a bound');

        $notIncreasing = [
            ['until_minutes' => 60, 'color' => 'green', 'character' => null],
            ['until_minutes' => 60, 'color' => 'yellow', 'character' => null],
            ['until_minutes' => null, 'color' => 'red', 'character' => null],
        ];
        $this->assertNotNull(tb_activity_levels_validation_error($notIncreasing), 'equal bounds');

        $backwards = [
            ['until_minutes' => 60, 'color' => 'green', 'character' => null],
            ['until_minutes' => 30, 'color' => 'yellow', 'character' => null],
            ['until_minutes' => null, 'color' => 'red', 'character' => null],
        ];
        $this->assertNotNull(tb_activity_levels_validation_error($backwards), 'decreasing bounds');

        $unknownColor = [
            ['until_minutes' => 15, 'color' => 'purple', 'character' => null],
            ['until_minutes' => null, 'color' => 'red', 'character' => null],
        ];
        $this->assertNotNull(tb_activity_levels_validation_error($unknownColor), 'unknown colour');

        $both = [
            ['until_minutes' => 15, 'color' => 'green', 'character' => 'x'],
            ['until_minutes' => null, 'color' => 'red', 'character' => null],
        ];
        $this->assertNotNull(tb_activity_levels_validation_error($both), 'colour and character at once');

        $neither = [
            ['until_minutes' => 15, 'color' => null, 'character' => null],
            ['until_minutes' => null, 'color' => 'red', 'character' => null],
        ];
        $this->assertNotNull(tb_activity_levels_validation_error($neither), 'neither colour nor character');

        $longCharacter = [
            ['until_minutes' => 15, 'color' => null, 'character' => 'abcd'],
            ['until_minutes' => null, 'color' => 'red', 'character' => null],
        ];
        $this->assertNotNull(tb_activity_levels_validation_error($longCharacter), '4-character glyph');

        $zeroBound = [
            ['until_minutes' => 0, 'color' => 'green', 'character' => null],
            ['until_minutes' => null, 'color' => 'red', 'character' => null],
        ];
        $this->assertNotNull(tb_activity_levels_validation_error($zeroBound), 'zero bound');

        $malformed = [
            'garbage',
            ['until_minutes' => null, 'color' => 'red', 'character' => null],
        ];
        $this->assertNotNull(tb_activity_levels_validation_error($malformed), 'non-array period');
    }

    // ── form parsing ──

    public function test_the_form_rows_parse_into_the_storage_shape(): void
    {
        $levels = tb_activity_levels_from_form([
            'minutes' => ['30', '120', '1440', '', ''],
            'style' => ['color:green', 'color:yellow', 'color:red', 'color:grey', ''],
            'character' => ['', '', '', '', ''],
        ]);
        $this->assertSame($this->fourLevels(), $levels);
    }

    public function test_fully_unused_rows_are_dropped_and_a_character_row_parses(): void
    {
        $levels = tb_activity_levels_from_form([
            'minutes' => ['15', ''],
            'style' => ['color:green', 'character'],
            'character' => ['', '·'],
        ]);
        $this->assertSame([
            ['until_minutes' => 15, 'color' => 'green', 'character' => null],
            ['until_minutes' => null, 'color' => null, 'character' => '·'],
        ], $levels);
    }

    public function test_an_empty_form_parses_to_an_empty_list_which_validation_rejects(): void
    {
        $levels = tb_activity_levels_from_form([
            'minutes' => ['', '', '', '', ''],
            'style' => ['', '', '', '', ''],
            'character' => ['', '', '', '', ''],
        ]);
        $this->assertSame([], $levels);
        $this->assertNotNull(tb_activity_levels_validation_error($levels));
    }

    // ── storage ──

    private function settingsPdo(): PDO
    {
        $dbFile = sys_get_temp_dir() . '/task-board-activity-levels-' . getmypid() . '-' . uniqid() . '.sqlite';
        Schema::createSqlite($dbFile);
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdoFile = $dbFile;
        return $pdo;
    }

    private ?string $pdoFile = null;

    protected function tearDown(): void
    {
        if ($this->pdoFile !== null && is_file($this->pdoFile)) {
            @unlink($this->pdoFile);
        }
        parent::tearDown();
    }

    public function test_an_absent_row_reads_as_the_defaults(): void
    {
        $pdo = $this->settingsPdo();
        $this->assertSame(tb_activity_levels_default(), tb_load_activity_levels($pdo));
    }

    public function test_a_saved_list_round_trips(): void
    {
        $pdo = $this->settingsPdo();
        tb_save_activity_levels($pdo, $this->fourLevels());
        $this->assertSame($this->fourLevels(), tb_load_activity_levels($pdo));
        // saving again replaces the row instead of failing on the key
        tb_save_activity_levels($pdo, tb_activity_levels_default());
        $this->assertSame(tb_activity_levels_default(), tb_load_activity_levels($pdo));
    }

    public function test_reset_removes_the_stored_list(): void
    {
        $pdo = $this->settingsPdo();
        tb_save_activity_levels($pdo, $this->fourLevels());
        tb_save_activity_levels($pdo, null);
        $this->assertSame(tb_activity_levels_default(), tb_load_activity_levels($pdo));
    }

    public function test_a_corrupt_or_invalid_stored_list_falls_back_to_the_defaults(): void
    {
        $pdo = $this->settingsPdo();
        // corrupt JSON
        $pdo->prepare('INSERT OR REPLACE INTO app_settings(name,value) VALUES(?,?)')
            ->execute([TB_ACTIVITY_SETTINGS_KEY, '{not json']);
        $this->assertSame(tb_activity_levels_default(), tb_load_activity_levels($pdo));
        // valid JSON that does not pass validation (backwards bounds)
        $pdo->prepare('INSERT OR REPLACE INTO app_settings(name,value) VALUES(?,?)')->execute([TB_ACTIVITY_SETTINGS_KEY, (string)json_encode([
            ['until_minutes' => 60, 'color' => 'green', 'character' => null],
            ['until_minutes' => 30, 'color' => 'yellow', 'character' => null],
            ['until_minutes' => null, 'color' => 'red', 'character' => null],
        ])]);
        $this->assertSame(tb_activity_levels_default(), tb_load_activity_levels($pdo));
    }

    public function test_a_board_without_the_table_yet_falls_back_to_the_defaults(): void
    {
        // a pre-#34 database: app_settings does not exist, and the reader must
        // not fatal (the schema self-heal recreates it on real deployments)
        $dbFile = sys_get_temp_dir() . '/task-board-activity-levels-notable-' . getmypid() . '-' . uniqid() . '.sqlite';
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        try {
            $this->assertSame(tb_activity_levels_default(), tb_load_activity_levels($pdo));
        } finally {
            unset($pdo);
            @unlink($dbFile);
        }
    }
}
