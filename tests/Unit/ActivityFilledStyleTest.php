<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';

/**
 * Task #112: the filled toggle for the activity status indicators. Every
 * colour period (and the logged-out indicator) renders as the solid dot or
 * the hollow circle per the administrator's pick; legacy rows without the
 * stored key keep the exact pre-#112 rendering - solid colours, hollow grey
 * (#106) - so an existing configuration never changes appearance.
 */
final class ActivityFilledStyleTest extends TestCase
{
    private const NOW = 1764000000;

    private function levels(array $levels): array
    {
        return $levels;
    }

    // --- legacy rows (no 'filled' key) render exactly the pre-#112 shapes ---

    public function test_a_legacy_colour_period_stays_a_solid_dot(): void
    {
        $levels = $this->levels([['until_minutes' => 15, 'color' => 'green', 'character' => null], ['until_minutes' => null, 'color' => 'red', 'character' => null]]);
        $this->assertSame(
            '<span class="tb-activity-dot tb-activity-green" title="Seen within the last 15 minutes"></span>',
            tb_activity_dot(self::NOW - 60, self::NOW, $levels)
        );
    }

    public function test_a_legacy_grey_period_stays_the_hollow_106_circle(): void
    {
        $levels = $this->levels([['until_minutes' => 15, 'color' => 'green', 'character' => null], ['until_minutes' => null, 'color' => 'grey', 'character' => null]]);
        $this->assertSame(
            '<span class="tb-activity-empty" title="Last seen longer ago than every period above">&#9675;</span>',
            tb_activity_dot(self::NOW - 99999, self::NOW, $levels)
        );
    }

    // --- the explicit toggle (#112) ---

    public function test_a_filled_grey_period_renders_the_solid_grey_dot(): void
    {
        // THE capability the task asks for: the grey solid dot.
        $levels = $this->levels([['until_minutes' => null, 'color' => 'grey', 'character' => null, 'filled' => true]]);
        $this->assertSame(
            '<span class="tb-activity-dot tb-activity-grey" title="Last seen longer ago than every period above"></span>',
            tb_activity_dot(self::NOW - 60, self::NOW, $levels)
        );
    }

    public function test_an_unfilled_colour_period_renders_the_hollow_circle_in_its_colour(): void
    {
        $levels = $this->levels([['until_minutes' => null, 'color' => 'green', 'character' => null, 'filled' => false]]);
        $this->assertSame(
            '<span class="tb-activity-empty tb-activity-green" title="Last seen longer ago than every period above">&#9675;</span>',
            tb_activity_dot(self::NOW - 60, self::NOW, $levels)
        );
        $levels = $this->levels([['until_minutes' => null, 'color' => 'yellow', 'character' => null, 'filled' => false]]);
        $this->assertSame(
            '<span class="tb-activity-empty tb-activity-yellow" title="Last seen longer ago than every period above">&#9675;</span>',
            tb_activity_dot(self::NOW - 60, self::NOW, $levels)
        );
    }

    public function test_the_toggle_takes_the_stored_key_over_the_legacy_default(): void
    {
        // Grey explicitly unfilled (the stored false, not the missing key)
        // keeps the exact #106 markup; red explicitly unfilled hollows out.
        $levels = $this->levels([['until_minutes' => null, 'color' => 'grey', 'character' => null, 'filled' => false]]);
        $this->assertSame(
            '<span class="tb-activity-empty" title="Last seen longer ago than every period above">&#9675;</span>',
            tb_activity_dot(self::NOW - 60, self::NOW, $levels)
        );
        $levels = $this->levels([['until_minutes' => null, 'color' => 'red', 'character' => null, 'filled' => false]]);
        $this->assertSame(
            '<span class="tb-activity-empty tb-activity-red" title="Last seen longer ago than every period above">&#9675;</span>',
            tb_activity_dot(self::NOW - 60, self::NOW, $levels)
        );
    }

    public function test_a_character_period_ignores_the_toggle(): void
    {
        $levels = $this->levels([['until_minutes' => null, 'color' => null, 'character' => '·', 'filled' => true]]);
        $this->assertSame(
            '<span class="tb-activity-char" title="Last seen longer ago than every period above">·</span>',
            tb_activity_dot(self::NOW - 60, self::NOW, $levels)
        );
    }

    // --- the form parser and the validator (#112) ---

    public function test_the_form_parser_stores_the_toggle_for_colour_rows_only(): void
    {
        $levels = tb_activity_levels_from_form([
            'minutes' => ['15', '', '', '', ''],
            'style' => ['color:grey', 'character', '', '', ''],
            'character' => ['', '·', '', '', ''],
            'filled' => ['1', '1', '0', '0', '0'],
        ]);
        $this->assertTrue($levels[0]['filled'], 'The ticked colour row stores filled=true.');
        $this->assertArrayNotHasKey('filled', $levels[1], 'A character row carries no shape key.');
        $this->assertSame('·', $levels[1]['character']);
    }

    public function test_an_unticked_checkbox_stores_false(): void
    {
        // The keyed hidden+checkbox pair posts '0' for an unticked row, so the
        // hollow choice survives the round trip.
        $levels = tb_activity_levels_from_form([
            'minutes' => ['15', '', '', '', ''],
            'style' => ['color:green', '', '', '', ''],
            'character' => ['', '', '', '', ''],
            'filled' => ['0', '0', '0', '0', '0'],
        ]);
        $this->assertFalse($levels[0]['filled']);
    }

    public function test_the_validator_accepts_a_boolean_toggle_and_rejects_anything_else(): void
    {
        $ok = [['until_minutes' => 15, 'color' => 'grey', 'character' => null, 'filled' => true], ['until_minutes' => null, 'color' => 'red', 'character' => null, 'filled' => false]];
        $this->assertNull(tb_activity_levels_validation_error($ok), 'A boolean toggle validates.');
        $bad = [['until_minutes' => 15, 'color' => 'grey', 'character' => null, 'filled' => 'yes'], ['until_minutes' => null, 'color' => 'red', 'character' => null]];
        $this->assertNotNull(tb_activity_levels_validation_error($bad), 'A non-boolean toggle is malformed.');
    }

    // --- the logged-out indicator (#112) ---

    public function test_the_logged_out_indicator_honours_the_toggle(): void
    {
        // Legacy default: the grey hollow circle exactly as #106 pinned it.
        $this->assertSame('<span class="tb-activity-empty" title="Signed out">&#9675;</span>', tb_logged_out_dot());
        $this->assertSame(
            '<span class="tb-activity-empty" title="Signed out">&#9675;</span>',
            tb_logged_out_dot(['color' => 'grey', 'character' => null])
        );
        // The new capability: the solid grey dot for signed-out users.
        $this->assertSame(
            '<span class="tb-activity-dot tb-activity-grey" title="Signed out"></span>',
            tb_logged_out_dot(['color' => 'grey', 'character' => null, 'filled' => true])
        );
        // A colour hollowed out by the toggle.
        $this->assertSame(
            '<span class="tb-activity-empty tb-activity-red" title="Signed out">&#9675;</span>',
            tb_logged_out_dot(['color' => 'red', 'character' => null, 'filled' => false])
        );
        // A colour without the key stays solid (legacy behaviour).
        $this->assertSame(
            '<span class="tb-activity-dot tb-activity-red" title="Signed out"></span>',
            tb_logged_out_dot(['color' => 'red', 'character' => null])
        );
    }

    public function test_the_logged_out_form_parser_carries_the_toggle(): void
    {
        $this->assertSame(
            ['color' => 'grey', 'character' => null, 'filled' => true],
            tb_logged_out_indicator_from_form(['style' => 'color:grey', 'character' => '', 'filled' => '1'])
        );
        $this->assertSame(
            ['color' => 'grey', 'character' => null, 'filled' => false],
            tb_logged_out_indicator_from_form(['style' => 'color:grey', 'character' => '', 'filled' => '0'])
        );
        // Character and disable shapes carry no toggle.
        $this->assertArrayNotHasKey('filled', tb_logged_out_indicator_from_form(['style' => 'character', 'character' => '·', 'filled' => '1']) ?? []);
        $this->assertNull(tb_logged_out_indicator_from_form(['style' => '', 'character' => '', 'filled' => '1']));
    }

    public function test_the_logged_out_validator_accepts_a_boolean_toggle_only(): void
    {
        $this->assertNull(tb_logged_out_indicator_validation_error(['color' => 'grey', 'character' => null, 'filled' => false]));
        $this->assertNotNull(tb_logged_out_indicator_validation_error(['color' => 'grey', 'character' => null, 'filled' => 'x']));
    }
}
