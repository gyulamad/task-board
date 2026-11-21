# PHP Task Board

A lightweight multi-user task and message board for PHP 8.2+ and MySQL/MariaDB shared hosting.

## Included
- Session login; password hashes using PHP `password_hash()`
- Admin and regular user roles; admin can create users
- Task CRUD, multi-assignee selection, status and parent task
- Filter by assignee/status/parent task and search by title/description
- Paginated task list with per-task Edit/Delete shortcuts and multi-select bulk delete
- Comments, attachments, history and task relationships
- Admin can add status labels and mark completion statuses
- Admin-only software updater for the latest GitHub release or a selected repository branch
- PDO prepared statements, output escaping, CSRF tokens and upload MIME checks

## Install on shared hosting
1. Create a MySQL database and database user in your hosting control panel.
2. Open phpMyAdmin, select the new database, and import `schema.sql`.
3. Copy `config.example.php` to `config.php` and fill in the database credentials.
4. Upload the project files to your website's document root.
5. Ensure the `uploads` directory is writable by PHP (usually permissions 755; use your host's recommended setting).
6. Visit `https://your-domain.example/?page=install` and create the first administrator.
7. Sign in, then use Administration to create other users.
8. After the first account is created, the installer link is hidden and `?page=install` returns HTTP 404 for both GET and POST requests.
9. The administrator can open **Software updates** from the navigation bar to check and install application updates from the latest published GitHub release or a selected branch. Releases are recommended; branches are intended for testing.

## Important security / hosting notes
- Use HTTPS. Do not use this for sensitive or regulated data without a security review.
- Session cookies are hardened (HttpOnly, SameSite=Lax, Secure on HTTPS, strict mode) and sessions idle out after 12 hours (configurable via `session_idle_seconds` in `config.php`).
- Sign-out is a CSRF-protected POST; failed sign-in attempts are throttled (10 failures per client within 10 minutes) and responses carry `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` and a conservative `Content-Security-Policy`.
- Upload the complete repository to the web root, including the `src/` directory: `index.php` requires `src/helpers.php` and exits with a clear error when the file is missing. A partial upload that copies `index.php` without `src/helpers.php` makes every page return HTTP 500.
- Keep `config.php` out of public source control. If your host supports it, place it outside the web root.
- `uploads/.htaccess` disables script execution on Apache. On non-Apache hosts, configure the uploads directory so PHP and other scripts cannot execute there.
- Set `upload_max_filesize` and `post_max_size` in PHP settings to at least 5M (or lower the app limit in config.php).
- The upload allowlist is intentionally conservative. Adjust it in `index.php` if you need more file types.
- The app uses Bootstrap from a CDN; it needs internet access to load the styling.
- The updater requires PHP's ZIP extension (`ZipArchive`), outbound HTTPS access to GitHub, and write permission for the allowlisted application files. It updates only `index.php`, `updater.php`, `src/helpers.php`, `version.json`, `schema.sql`, `README.md`, `config.example.php`, `.gitignore` and `uploads/.htaccess`; it creates missing sub-directories such as `src/` on demand and never replaces `config.php` or user-uploaded files.
- The updater rechecks the source at install time, validates archive paths, rejects symbolic links, stages file replacements, and creates a temporary backup before writing. If a write fails, it attempts to restore files already replaced. Keep an independent backup of both files and the database before updating; temporary hosting storage may be cleared by the host.
- The updater does not run database migrations. Review release notes and apply any required `schema.sql` changes manually after backing up the database. Do not install experimental branches on a production board.
- Database relationships are one-directional as entered: for example, on task A choose "blocks" and task B to mean A blocks B.
- Task history currently records task creation, updates, comments, attachments and relationship changes. For a detailed field-by-field audit trail, extend `save_task` to compare old and new values.
- Admin user management currently supports creating users. Password reset, editing/deactivating users and status rename/delete are not yet included.
- Deleting a task cascades its comments, attachments metadata, history and links. Uploaded physical files may remain orphaned in `uploads`; periodically clean them up.

## Requirements
- PHP 8.2+ recommended
- PDO MySQL extension
- Fileinfo extension
- MySQL 5.7+ / MariaDB 10.3+ recommended

## Testing and CI
The project ships with a PHPUnit test suite (`tests/`) that covers the application end to end
through its HTTP interface plus unit tests for `src/helpers.php`:

- `composer install`
- `composer test` — runs the full suite against SQLite; no other setup needed. The test
  bootstrap copies the app into a temp directory and applies two documented SQLite
  compatibility edits there (the PDO DSN and `INSERT IGNORE` → `INSERT OR IGNORE`), so the
  real source files are never touched.
- `composer test:mysql` — runs the same suite against a real MySQL/MariaDB server using the
  verbatim source. Requires `TEST_DB_HOST`, `TEST_DB_NAME`, `TEST_DB_USER`, `TEST_DB_PASS`
  and a running app server (`php -S 127.0.0.1:8099 -t .`) plus `TEST_BASE_URL`.
- `composer test:coverage` — generates an HTML + clover + text coverage report under
  `build/coverage/`; requires the PCOV or Xdebug extension.
- Without Composer, download the PHPUnit PHAR and run `php phpunit.phar --no-coverage`.

Continuous integration lives in `.github/workflows/ci.yml`: it lints every PHP file, runs the
suite on SQLite, and runs it on MySQL 8 with PCOV enabled. The MySQL job publishes the
coverage report (HTML + clover + summary) as a build artifact.

The installer flow itself is exercised by the tests (the suite creates its administrator
through `?page=install` on a fresh database for every test), and each test starts from a
cleanly imported schema.

