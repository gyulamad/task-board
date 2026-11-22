<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';

/**
 * Task #31: the stored scheduler target assignees are a comma-separated user
 * id list saved by the task edit form. The parser must return a sorted list
 * of unique positive integer ids (so two lists compare as sets), drop
 * unparsable fragments, and treat NULL/'', the values a pre-#31 schedule has,
 * as "no target assignees".
 */
final class ScheduleTargetTest extends TestCase
{
    public function test_null_and_empty_mean_no_target_assignees(): void
    {
        $this->assertSame([], schedule_target_assignees(null));
        $this->assertSame([], schedule_target_assignees(''));
        $this->assertSame([], schedule_target_assignees('   '));
    }

    public function test_a_single_id_parses_to_a_single_element_list(): void
    {
        $this->assertSame([3], schedule_target_assignees('3'));
        $this->assertSame([3], schedule_target_assignees(' 3 '));
    }

    public function test_lists_are_deduplicated_and_sorted_as_sets(): void
    {
        $this->assertSame([1, 3], schedule_target_assignees('3,1'));
        $this->assertSame([1, 3], schedule_target_assignees('3,3,1'));
        $this->assertSame([1, 2, 3], schedule_target_assignees(' 2 , 1 , 3 '));
    }

    public function test_unparsable_and_non_positive_fragments_are_dropped(): void
    {
        $this->assertSame([1, 2], schedule_target_assignees('1,x,2'));
        $this->assertSame([], schedule_target_assignees('0'));
        $this->assertSame([], schedule_target_assignees('0,-4,x'));
        $this->assertSame([7], schedule_target_assignees('abc,7,,9x'));
    }

    public function test_a_group_concat_value_from_the_cron_query_parses_the_same_way(): void
    {
        // The cron reads the currently assigned ids with GROUP_CONCAT(user_id),
        // which yields the same comma shape (null when the task is unassigned).
        $this->assertSame([2, 5], schedule_target_assignees('2,5'));
        $this->assertSame([], schedule_target_assignees(null));
    }
}
