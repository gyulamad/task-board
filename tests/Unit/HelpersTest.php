<?php
declare(strict_types=1);

namespace TaskBoard\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/helpers.php';

/**
 * Unit tests for the extracted helper functions in src/helpers.php.
 * These run without any server or database.
 */
final class HelpersTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function test_h_escapes_html_special_characters(): void
    {
        $this->assertSame('&lt;b&gt;', h('<b>'));
        $this->assertSame('a &amp; b', h('a & b'));
        $this->assertSame('&quot;quoted&quot;', h('"quoted"'));
        $this->assertSame('&#039;single&#039;', h("'single'"));
        $this->assertSame('', h(null));
        $this->assertSame('plain text', h('plain text'));
    }

    public function test_h_passes_through_utf8_multibyte_characters(): void
    {
        $this->assertSame('Újszülött · 尤拉', h('Újszülött · 尤拉'));
    }

    public function test_h_is_safe_against_double_encoding_flags(): void
    {
        // ENT_QUOTES | ENT_SUBSTITUTE: invalid UTF-8 sequences are substituted, not fatal.
        $this->assertSame('ok', h('ok'));
        $this->assertSame('a&amp;b', h('a&b'));
    }

    public function test_user_returns_null_for_anonymous_sessions(): void
    {
        $this->assertNull(user());
        $this->assertFalse(is_admin());
    }

    public function test_user_reads_the_session_identity(): void
    {
        $_SESSION['user'] = ['id' => 7, 'username' => 'tester', 'display_name' => 'Tester', 'role' => 'user'];
        $this->assertSame('tester', user()['username']);
        $this->assertFalse(is_admin());
    }

    public function test_is_admin_requires_the_admin_role(): void
    {
        $_SESSION['user'] = ['id' => 1, 'username' => 'admin', 'display_name' => 'Admin', 'role' => 'admin'];
        $this->assertTrue(is_admin());
    }

    public function test_flash_stores_a_message_in_the_session(): void
    {
        flash('Task deleted.');
        $this->assertSame('Task deleted.', $_SESSION['flash']);
    }

    public function test_csrf_field_renders_a_hidden_input_with_the_session_token(): void
    {
        $_SESSION['csrf'] = str_repeat('ab', 32);
        $field = csrf_field();
        $this->assertStringStartsWith('<input type="hidden" name="csrf" value="', $field);
        $this->assertStringEndsWith('">', $field);
        $this->assertStringContainsString(str_repeat('ab', 32), $field);
    }

    public function test_csrf_field_escapes_hostile_tokens(): void
    {
        $_SESSION['csrf'] = '" onmouseover="alert(1)';
        $field = csrf_field();
        $this->assertStringNotContainsString('" onmouseover=', $field);
        $this->assertStringContainsString('&quot;', $field);
    }

    public function test_verify_csrf_accepts_matching_tokens_on_post(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf'] = 'token123';
        $_POST['csrf'] = 'token123';
        verify_csrf();
        // No exception and no exit: the request would continue processing.
        $this->addToAssertionCount(1);
    }

    public function test_verify_csrf_is_a_no_op_for_get_requests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SESSION['csrf'] = 'token123';
        $_POST['csrf'] = 'wrong-on-purpose';
        verify_csrf();
        $this->addToAssertionCount(1);
    }

    public function test_login_throttle_ignores_unknown_clients(): void
    {
        $key = 'unit-test-' . bin2hex(random_bytes(8));
        $this->assertFalse(tb_login_throttled($key));
        tb_login_throttle_clear($key);
    }

    public function test_login_throttle_blocks_after_ten_recent_failures(): void
    {
        $key = 'unit-test-' . bin2hex(random_bytes(8));
        try {
            for ($i = 1; $i <= 9; $i++) {
                tb_login_throttle_fail($key);
                $this->assertFalse(tb_login_throttled($key), 'failure #' . $i . ' must not block yet');
            }
            tb_login_throttle_fail($key);
            $this->assertTrue(tb_login_throttled($key), 'the 10th failure must trigger the throttle');
            tb_login_throttle_clear($key);
            $this->assertFalse(tb_login_throttled($key), 'a successful sign-in clears the bucket');
        } finally {
            tb_login_throttle_clear($key);
        }
    }

    public function test_session_destroy_clears_session_state(): void
    {
        $_SESSION['user'] = ['id' => 1];
        $_SESSION['csrf'] = 'abc';
        tb_session_destroy();
        $this->assertSame([], $_SESSION);
        $this->addToAssertionCount(1); // session_destroy() ran without errors
    }

    /** @return array<string, string> */
    private function sortColumns(): array
    {
        return ['id' => 't.id {dir}', 'title' => 't.title {dir}', 'status' => 's.name {dir}', 'updated' => 't.updated_at {dir}'];
    }

    public function test_sort_state_defaults_without_query_parameters(): void
    {
        $_GET = [];
        $sort = sort_state($this->sortColumns(), 'updated', 'desc');
        $this->assertSame(['key' => 'updated', 'dir' => 'desc', 'sql' => 't.updated_at DESC'], $sort);
    }

    public function test_sort_state_reads_sort_and_dir_from_the_query(): void
    {
        $_GET = ['sort' => 'title', 'dir' => 'asc'];
        $sort = sort_state($this->sortColumns(), 'updated', 'desc');
        $this->assertSame(['key' => 'title', 'dir' => 'asc', 'sql' => 't.title ASC'], $sort);
    }

    public function test_sort_state_rejects_unknown_columns_and_directions(): void
    {
        $_GET = ['sort' => 't.id; DROP TABLE tasks', 'dir' => 'DESC; DROP TABLE tasks'];
        $sort = sort_state($this->sortColumns(), 'updated', 'desc');
        $this->assertSame('updated', $sort['key']);
        $this->assertSame('desc', $sort['dir']);
        $this->assertSame('t.updated_at DESC', $sort['sql']);
    }

    public function test_sort_state_accepts_uppercase_direction_but_rejects_unknown_column_casing(): void
    {
        $_GET = ['sort' => 'STATUS', 'dir' => 'DESC'];
        $sort = sort_state($this->sortColumns(), 'updated', 'desc');
        $this->assertSame('updated', $sort['key'], 'column keys are matched case-sensitively against the allowlist');
        $this->assertSame('desc', $sort['dir'], 'directions are accepted case-insensitively');
        $this->assertSame('t.updated_at DESC', $sort['sql']);
    }

    public function test_sort_toggle_sorts_ascending_first_then_flips(): void
    {
        $current = ['key' => 'updated', 'dir' => 'desc'];
        $this->assertSame(['sort' => 'id', 'dir' => 'asc'], sort_toggle('id', $current));
        $current = ['key' => 'id', 'dir' => 'asc'];
        $this->assertSame(['sort' => 'id', 'dir' => 'desc'], sort_toggle('id', $current));
        $current = ['key' => 'id', 'dir' => 'desc'];
        $this->assertSame(['sort' => 'id', 'dir' => 'asc'], sort_toggle('id', $current));
    }

    public function test_sort_header_renders_link_arrow_and_aria_sort(): void
    {
        $current = ['key' => 'id', 'dir' => 'asc'];
        $html = sort_header('Task', 'id', $current, '?page=tasks&sort=id&dir=desc');
        $this->assertStringStartsWith('<th aria-sort="ascending">', $html);
        $this->assertStringContainsString('href="?page=tasks&amp;sort=id&amp;dir=desc"', $html);
        $this->assertStringContainsString('aria-label="Order by Task, currently asc, activate to sort descending"', $html);
        $this->assertStringContainsString('&#9650;', $html);
    }

    public function test_sort_header_for_an_inactive_column_has_no_arrow_or_aria_sort(): void
    {
        $current = ['key' => 'id', 'dir' => 'asc'];
        $html = sort_header('Status', 'status', $current, '?page=tasks&sort=status&dir=asc');
        $this->assertTrue(str_starts_with($html, '<th><a '), 'Inactive columns render a plain th.');
        $this->assertStringNotContainsString('aria-sort', $html);
        $this->assertStringNotContainsString('&#9650;', $html);
        $this->assertStringNotContainsString('&#9660;', $html);
    }

    public function test_sort_header_escapes_hostile_urls(): void
    {
        $current = ['key' => 'id', 'dir' => 'asc'];
        $html = sort_header('Task', 'id', $current, '?page=tasks" onmouseover="alert(1)');
        $this->assertStringNotContainsString('" onmouseover=', $html);
        $this->assertStringContainsString('&quot;', $html);
    }

    public function test_valid_themes_lists_light_and_dark(): void
    {
        $this->assertSame(['light', 'dark'], valid_themes());
    }

    public function test_normalize_theme_accepts_only_known_theme_names(): void
    {
        $this->assertSame('light', normalize_theme('light'));
        $this->assertSame('dark', normalize_theme('dark'));
        $this->assertSame('light', normalize_theme(null));
        $this->assertSame('light', normalize_theme(''));
        $this->assertSame('light', normalize_theme('blue'));
        $this->assertSame('light', normalize_theme('DARK'));
        $this->assertSame('light', normalize_theme('dark '));
    }

    public function test_current_theme_defaults_to_light_and_reads_the_session(): void
    {
        $this->assertSame('light', current_theme());

        $_SESSION['user'] = ['id' => 7, 'username' => 'tester', 'display_name' => 'Tester', 'role' => 'user', 'theme' => 'dark'];
        $this->assertSame('dark', current_theme());

        // Session values that are not a valid theme fall back to light.
        $_SESSION['user'] = ['id' => 7, 'username' => 'tester', 'display_name' => 'Tester', 'role' => 'user', 'theme' => 'something-else'];
        $this->assertSame('light', current_theme());
    }
}
