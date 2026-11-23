<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';

/**
 * Unit tests for history_comment_id(): the helper that links "Comment added"
 * history rows back to the comment they refer to, so the task page can show
 * the fact only and scroll to the comment itself (#62).
 *
 * These run without any server or database.
 */
final class HistoryCommentIdTest extends TestCase
{
    public function test_reads_an_explicit_comment_reference(): void
    {
        $row = ['event' => 'Comment added', 'details' => 'A comment was added. (comment:42)', 'user_id' => 3, 'created_at' => '2026-11-23 07:00:00'];
        $this->assertSame(42, history_comment_id($row, []));
    }

    public function test_resolves_body_details_by_author_and_timestamp(): void
    {
        // #54 v2 rows carry the comment body in the details; the history row is
        // recorded right after the comment insert, so the author and the exact
        // timestamp identify the comment (#62).
        $comments = [
            ['id' => 7, 'user_id' => 3, 'created_at' => '2026-11-23 07:00:00', 'body' => 'older'],
            ['id' => 8, 'user_id' => 4, 'created_at' => '2026-11-23 07:00:05', 'body' => 'other user'],
        ];
        $row = ['event' => 'Comment added', 'details' => 'older', 'user_id' => 3, 'created_at' => '2026-11-23 07:00:00'];
        $this->assertSame(7, history_comment_id($row, $comments));
    }

    public function test_resolves_when_the_history_row_lands_a_second_later(): void
    {
        // The two inserts usually share one timestamp, but a statement can
        // straddle a second boundary - the positional match still holds.
        $comments = [
            ['id' => 21, 'user_id' => 2, 'created_at' => '2026-11-23 07:40:00', 'body' => 'the comment'],
        ];
        $row = ['event' => 'Comment added', 'details' => 'the comment', 'user_id' => 2, 'created_at' => '2026-11-23 07:40:01'];
        $this->assertSame(21, history_comment_id($row, $comments));
    }

    public function test_tie_breaks_on_the_newest_comment(): void
    {
        // Same author and same second: the newest inserted comment wins, so the
        // resolution stays deterministic even for rapid-fire comments.
        $comments = [
            ['id' => 7, 'user_id' => 3, 'created_at' => '2026-11-23 07:00:00', 'body' => 'first'],
            ['id' => 8, 'user_id' => 3, 'created_at' => '2026-11-23 07:00:00', 'body' => 'second'],
        ];
        $row = ['event' => 'Comment added', 'details' => 'first', 'user_id' => 3, 'created_at' => '2026-11-23 07:00:00'];
        $this->assertSame(8, history_comment_id($row, $comments));
    }

    public function test_ignores_comments_written_after_the_row(): void
    {
        // A later comment by the same author belongs to a later history row and
        // must not hijack this one.
        $comments = [
            ['id' => 7, 'user_id' => 3, 'created_at' => '2026-11-23 07:00:00', 'body' => 'matched'],
            ['id' => 9, 'user_id' => 3, 'created_at' => '2026-11-23 07:00:05', 'body' => 'later'],
        ];
        $row = ['event' => 'Comment added', 'details' => 'matched', 'user_id' => 3, 'created_at' => '2026-11-23 07:00:00'];
        $this->assertSame(7, history_comment_id($row, $comments));
        $row = ['event' => 'Comment added', 'details' => 'matched', 'user_id' => 3, 'created_at' => '2026-11-23 06:59:59'];
        $this->assertNull(history_comment_id($row, $comments), 'nothing written by the author before the row: no anchor');
    }

    public function test_ignores_other_events(): void
    {
        $row = ['event' => 'Task created', 'details' => 'A comment was added. (comment:42)', 'user_id' => 3, 'created_at' => '2026-11-23 07:00:00'];
        $this->assertNull(history_comment_id($row, []));
    }
}
