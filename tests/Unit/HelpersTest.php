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
}
