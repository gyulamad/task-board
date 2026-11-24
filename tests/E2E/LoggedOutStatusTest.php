<?php
declare(strict_types=1);

namespace TaskBoard\Tests\E2E;

use PDO;
use TaskBoard\Tests\Support\AppSession;
use TaskBoard\Tests\Support\TestServer;

/**
 * Task #106: the logged-out status. A user who signs out through the board's
 * own sign-out button shows a configured indicator (the grey empty circle ○
 * by default, or an administrator-chosen colour or character) instead of
 * their activity dot - never both - until the next successful sign-in clears
 * the marker. Sessions that die silently never set it, so only a real
 * sign-out changes what the dot shows. The same task renders the grey
 * activity period as the EMPTY circle character (the picker has always
 * labelled it "grey (empty)"), so an empty period looks empty.
 */
final class LoggedOutStatusTest extends E2eTestCase
{
    private function createOtherUser(string $username): AppSession
    {
        $this->adminSession();
        $response = $this->admin->post('/?page=users-v2', [
            'action' => 'add_user',
            'username' => $username,
            'display_name' => ucfirst($username),
            'password' => $username . 'Pass12345',
            'role' => 'user',
        ]);
        $this->assertSame(302, $response->status, 'Creating the test user must succeed.');
        return new AppSession($username, $username . 'Pass12345');
    }

    private function userId(string $username): int
    {
        $s = TestServer::pdo()->prepare('SELECT id FROM users WHERE username=?');
        $s->execute([$username]);
        $id = $s->fetchColumn();
        $this->assertNotFalse($id, 'User ' . $username . ' does not exist.');
        return (int)$id;
    }

    /** Creates a task assigned to the given user through the real form. */
    private function createTaskAssignedTo(string $username): int
    {
        $id = $this->userId($username);
        $response = $this->admin->post('/?page=new', [
            'action' => 'save_task',
            'id' => '0',
            'title' => 'Logged-out dot carrier',
            'description' => 'A task whose assignee row renders the dot under test.',
            'status_id' => '1',
            'complexity' => '1',
            'priority' => '1',
            'parent_id' => '',
            'project_id' => '',
            'assignees' => [(string)$id],
        ]);
        $this->assertSame(302, $response->status, 'Creating the carrier task must succeed.');
        $s = TestServer::pdo()->prepare("SELECT id FROM tasks WHERE title='Logged-out dot carrier' ORDER BY id DESC");
        $s->execute();
        return (int)$s->fetchColumn();
    }

    private function loggedOutColumnNames(): array
    {
        $pdo = TestServer::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            return array_column($pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        }
        return array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    /** Rebuilds the users table in its pre-#106 shape (no logged_out column). */
    private function dropLoggedOutColumn(): void
    {
        $pdo = TestServer::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec('ALTER TABLE users DROP COLUMN logged_out');
            return;
        }
        // SQLite: rebuild the table in its pre-#106 shape. Legacy rename
        // semantics keep the other tables' REFERENCES clauses pointing at
        // "users" (the statuses self-heal test uses the same trick).
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->exec('PRAGMA legacy_alter_table=ON');
        $pdo->exec('ALTER TABLE users RENAME TO users_pre106');
        $pdo->exec('PRAGMA legacy_alter_table=OFF');
        $pdo->exec("CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(80) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(120) NOT NULL,
            role VARCHAR(10) NOT NULL DEFAULT 'user',
            theme VARCHAR(20) NOT NULL DEFAULT 'light',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TIMESTAMP NULL DEFAULT NULL
        )");
        $pdo->exec('INSERT INTO users(id,username,password_hash,display_name,role,theme,created_at,last_seen_at) SELECT id,username,password_hash,display_name,role,theme,created_at,last_seen_at FROM users_pre106');
        $pdo->exec('DROP TABLE users_pre106');
        $pdo->exec('PRAGMA foreign_keys=ON');
    }

    // ── rendering: the built-in default and the shapes ──

    public function test_the_default_indicator_is_the_grey_empty_circle(): void
    {
        $this->assertSame(['color' => 'grey', 'character' => null], tb_logged_out_indicator_default());
        $this->assertSame('<span class="tb-activity-empty" title="Signed out">&#9675;</span>', tb_logged_out_dot());
    }

    public function test_a_signed_out_row_renders_the_indicator_and_never_both_dots(): void
    {
        // A fresh stamp would render green; the logged-out flag must replace
        // it completely, not stack next to it.
        $recent = time() - 30;
        $rendered = tb_user_with_activity(['display_name' => 'Bob', 'last_seen_at' => $recent, 'logged_out' => 1]);
        $this->assertSame('<span class="text-nowrap"><span class="tb-activity-empty" title="Signed out">&#9675;</span>Bob</span>', $rendered);
        $this->assertStringNotContainsString('tb-activity-dot', $rendered, 'The activity dot must not render next to the logged-out indicator.');
        $this->assertStringNotContainsString('tb-activity-green', $rendered);
    }

    public function test_rows_without_the_flag_keep_the_plain_activity_dot(): void
    {
        // Rows that predate the column (or synthetic arrays) render exactly
        // the pre-#106 markup: no caller has to know about the feature.
        $recent = time() - 30;
        $rendered = tb_user_with_activity(['display_name' => 'Bob', 'last_seen_at' => $recent]);
        $this->assertSame('<span class="text-nowrap"><span class="tb-activity-dot tb-activity-green" title="Active in the last 15 minutes"></span>Bob</span>', $rendered);
    }

    public function test_the_configured_colour_and_character_shapes_render(): void
    {
        $this->assertSame('<span class="tb-activity-dot tb-activity-red" title="Signed out"></span>', tb_logged_out_dot(['color' => 'red', 'character' => null]));
        $this->assertSame('<span class="tb-activity-char" title="Signed out">' . "\u{2717}" . '</span>', tb_logged_out_dot(['color' => null, 'character' => "\u{2717}"]), 'A character glyph renders literally - h() escapes, it never entity-encodes.');
        // The disable marker lives in the stored configuration: with it in
        // effect the renderer returns '' so callers fall back to the activity
        // dot. The global is restored so the other tests see the default.
        $GLOBALS['TB_LOGGED_OUT_INDICATOR'] = null;
        try {
            $this->assertSame('', tb_logged_out_dot(), 'The stored disable marker renders nothing so callers fall back to the activity dot.');
        } finally {
            unset($GLOBALS['TB_LOGGED_OUT_INDICATOR']);
        }
    }

    public function test_the_indicator_validation_rejects_bad_saves(): void
    {
        $this->assertSame('The logged-out indicator must pick one: a colour or a character.', tb_logged_out_indicator_validation_error(['color' => 'red', 'character' => 'X']));
        $this->assertSame('The logged-out indicator uses an unknown colour.', tb_logged_out_indicator_validation_error(['color' => 'purple', 'character' => null]));
        $this->assertSame('The logged-out indicator cannot show an empty character.', tb_logged_out_indicator_validation_error(['color' => null, 'character' => '   ']));
        $this->assertSame('The logged-out indicator character is limited to 3 characters.', tb_logged_out_indicator_validation_error(['color' => null, 'character' => 'toolong']));
        $this->assertSame('The logged-out indicator needs a colour or a character.', tb_logged_out_indicator_validation_error([]));
        $this->assertNull(tb_logged_out_indicator_validation_error(['color' => 'grey', 'character' => null]));
        $this->assertNull(tb_logged_out_indicator_validation_error(['color' => null, 'character' => "\u{25CF}"]), 'The filled circle glyph is one character.');
    }

    public function test_the_form_parser_understands_every_posted_shape(): void
    {
        $this->assertNull(tb_logged_out_indicator_from_form(['style' => '', 'character' => 'whatever']), 'The empty style is the disable choice.');
        $this->assertNull(tb_logged_out_indicator_from_form(['style' => 'none']));
        $this->assertSame(['color' => 'yellow', 'character' => null], tb_logged_out_indicator_from_form(['style' => 'color:yellow']));
        $this->assertSame(['color' => null, 'character' => 'X'], tb_logged_out_indicator_from_form(['style' => 'character', 'character' => 'X']));
        $this->assertSame(['color' => null, 'character' => null], tb_logged_out_indicator_from_form(['style' => 'bogus']), 'An unknown style parses to the shape the validator rejects.');
    }

    public function test_the_indicator_stores_loads_and_self_validates(): void
    {
        $pdo = TestServer::pdo();

        // No stored row: the built-in default is in effect.
        $this->assertSame(tb_logged_out_indicator_default(), tb_load_logged_out_indicator($pdo));

        // A stored colour round-trips.
        tb_save_logged_out_indicator($pdo, ['color' => 'green', 'character' => null]);
        $this->assertSame(['color' => 'green', 'character' => null], tb_load_logged_out_indicator($pdo));

        // A stored character round-trips, including multi-byte glyphs.
        tb_save_logged_out_indicator($pdo, ['color' => null, 'character' => "\u{2717}"]);
        $this->assertSame(['color' => null, 'character' => "\u{2717}"], tb_load_logged_out_indicator($pdo));

        // The disable marker stores and reads back as null.
        tb_save_logged_out_indicator($pdo, null);
        $this->assertNull(tb_load_logged_out_indicator($pdo));

        // A hand-edited row that fails validation falls back to the default,
        // so a broken row cannot break the dots.
        $s = $pdo->prepare('UPDATE app_settings SET value=? WHERE name=?');
        $s->execute(['{"color":"purple","character":null}', 'logged_out_indicator']);
        $this->assertSame(tb_logged_out_indicator_default(), tb_load_logged_out_indicator($pdo));

        // Removing the row returns to the default too.
        $pdo->prepare('DELETE FROM app_settings WHERE name=?')->execute(['logged_out_indicator']);
        $this->assertSame(tb_logged_out_indicator_default(), tb_load_logged_out_indicator($pdo));
    }

    // ── the sign-out / sign-in lifecycle over the real flows ──

    public function test_signing_out_flips_the_flag_and_the_dot_until_signing_back_in(): void
    {
        $bob = $this->createOtherUser('bob');
        $bobId = $this->userId('bob');
        $adminId = $this->userId('admin');
        $taskId = $this->createTaskAssignedTo('bob');

        // Before any sign-out: both users render the plain activity dot.
        $page = $this->admin->get('/?page=task&id=' . $taskId);
        $this->assertSame(200, $page->status);
        $this->assertStringNotContainsString('title="Signed out"', $page->body);
        $this->assertTrue($page->contains('Bob'), 'The assignee must render on the task page.');

        // Sign-out is POST-only (AuthFlowTest pins the GET): a GET must not
        // set the flag either - silently dying sessions never look signed out.
        $bob->get('/?');
        $bob->get('/?page=logout');
        $this->assertSame('0', (string)TestServer::pdo()->query('SELECT logged_out FROM users WHERE id=' . $bobId)->fetchColumn(), 'A GET logout must not mark the user signed out.');

        // The real POST sign-out flips the flag.
        $bob->logout();
        $this->assertSame('1', (string)TestServer::pdo()->query('SELECT logged_out FROM users WHERE id=' . $bobId)->fetchColumn());

        // Every rendering of Bob now carries the logged-out indicator, never
        // stacked with the activity dot; the admin's own dot is untouched.
        $page = $this->admin->get('/?page=task&id=' . $taskId);
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('title="Signed out"'), 'The signed-out assignee must show the indicator on the task page.');
        $this->assertMatchesRegularExpression('/tb-activity-empty[^>]*title="Signed out"/', $page->body);
        $this->assertMatchesRegularExpression('/<span class="text-nowrap"><span class="tb-activity-empty" title="Signed out">&#9675;<\/span>Bob<\/span>/', $page->body, 'The assignee dot is replaced, not stacked.');
        $usersPage = $this->admin->get('/?page=users-v2');
        $this->assertSame(200, $usersPage->status);
        $this->assertMatchesRegularExpression('/<span class="text-nowrap"><span class="tb-activity-empty" title="Signed out">&#9675;<\/span>Bob<\/span>/', $usersPage->body);
        $this->assertStringNotContainsString('title="Signed out"></span>Administrator', $usersPage->body, 'The signed-in admin keeps the activity dot.');

        // Signing back in clears the flag from this very request on.
        $bobAgain = new AppSession('bob', 'bobPass12345');
        $this->assertSame('0', (string)TestServer::pdo()->query('SELECT logged_out FROM users WHERE id=' . $bobId)->fetchColumn(), 'A successful sign-in must clear the marker.');
        $page = $this->admin->get('/?page=task&id=' . $taskId);
        $this->assertStringNotContainsString('title="Signed out"', $page->body);
        $this->assertTrue($page->contains('tb-activity-green'), 'Bob is back on the activity periods (fresh stamp = green).');
    }

    public function test_the_administrator_can_reconfigure_and_disable_the_indicator(): void
    {
        $bob = $this->createOtherUser('bob');
        $bob->get('/?');
        $bob->logout();
        $bobId = $this->userId('bob');
        $this->adminSession();

        // The settings page renders the new area with the current default.
        $page = $this->admin->get('/?page=activity_settings');
        $this->assertSame(200, $page->status);
        $this->assertTrue($page->contains('Logged-out indicator'));
        $this->assertTrue($page->contains('Rendered now for a signed-out user:'));
        $this->assertTrue($page->contains('name="action" value="save_logged_out_indicator"'));
        $this->assertTrue($page->contains('<option value="color:grey" selected>'), 'The built-in grey empty circle is the pre-selected choice.');

        // A colour: the signed-out user renders the red disc.
        $save = $this->admin->post('/?page=activity_settings', ['action' => 'save_logged_out_indicator', 'style' => 'color:red', 'character' => '']);
        $this->assertSame(302, $save->status);
        $page = $this->admin->get('/?page=users-v2');
        $this->assertMatchesRegularExpression('/<span class="text-nowrap"><span class="tb-activity-dot tb-activity-red" title="Signed out"><\/span>Bob<\/span>/', $page->body);

        // A character: the glyph renders literally (UTF-8, escaped for HTML only).
        $save = $this->admin->post('/?page=activity_settings', ['action' => 'save_logged_out_indicator', 'style' => 'character', 'character' => "\u{2717}"]);
        $this->assertSame(302, $save->status);
        $page = $this->admin->get('/?page=users-v2');
        $this->assertMatchesRegularExpression('/<span class="text-nowrap"><span class="tb-activity-char" title="Signed out">' . preg_quote("\u{2717}", '/') . '<\/span>Bob<\/span>/', $page->body);

        // Disabling: the signed-out user falls back to the plain activity dot.
        $save = $this->admin->post('/?page=activity_settings', ['action' => 'save_logged_out_indicator', 'style' => '', 'character' => 'ignored']);
        $this->assertSame(302, $save->status);
        $this->assertNull(tb_load_logged_out_indicator(TestServer::pdo()));
        $page = $this->admin->get('/?page=users-v2');
        $this->assertStringNotContainsString('title="Signed out"', $page->body, 'With the feature disabled no signed-out indicator renders.');
        $this->assertTrue($page->contains('Bob'), 'Bob still renders with the activity dot.');
    }

    public function test_the_settings_form_rejects_an_empty_custom_character(): void
    {
        $this->adminSession();
        $response = $this->admin->post('/?page=activity_settings', ['action' => 'save_logged_out_indicator', 'style' => 'character', 'character' => '  ']);
        $this->assertSame(200, $response->status, 'A rejected save re-renders the page with the validation message.');
        $this->assertTrue($response->contains('The logged-out indicator cannot show an empty character.'));
        $this->assertSame('0', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM app_settings WHERE name='logged_out_indicator'")->fetchColumn(), 'Nothing is stored when validation fails.');
    }

    public function test_only_administrators_can_change_the_indicator(): void
    {
        $bob = $this->createOtherUser('bob');
        $bob->get('/?');

        // A non-admin POST cannot change the indicator: the action is
        // admin-only and the settings page itself answers 403.
        $response = $bob->post('/?page=activity_settings', ['action' => 'save_logged_out_indicator', 'style' => 'color:red', 'character' => '']);
        $this->assertSame(403, $response->status, 'The settings page is admin-only.');
        $this->assertSame('0', (string)TestServer::pdo()->query("SELECT COUNT(*) FROM app_settings WHERE name='logged_out_indicator'")->fetchColumn(), 'A non-admin save must not store anything.');
        $this->assertSame(tb_logged_out_indicator_default(), tb_logged_out_indicator_current());
    }

    public function test_the_logged_out_column_is_self_healed(): void
    {
        $this->dropLoggedOutColumn();
        $this->adminSession();   // the first request runs the self-heal

        $this->assertContains('logged_out', $this->loggedOutColumnNames(), 'The #106 column must be back after the self-heal.');
        // The heal keeps the existing rows and starts them at 0 (not signed
        // out): nobody suddenly shows the indicator after an upgrade.
        $this->assertSame('0', (string)TestServer::pdo()->query('SELECT logged_out FROM users WHERE username=\'admin\'')->fetchColumn());

        // The healed column is immediately usable through the real flows.
        $bob = $this->createOtherUser('bob');
        $bob->get('/?');
        $bob->logout();
        $this->assertSame('1', (string)TestServer::pdo()->query('SELECT logged_out FROM users WHERE username=\'bob\'')->fetchColumn());
    }
}
