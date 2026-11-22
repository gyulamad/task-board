# PHP Task Board

A lightweight multi-user task and message board for PHP 8.2+ and MySQL/MariaDB shared hosting.

## Included
- Session login; password hashes using PHP `password_hash()`
- Admin and regular user roles; admin can create, edit (username, display name, role, password reset) and delete users
- Task CRUD, multi-assignee selection, status and parent task
- Project CRUD with the same building blocks as tasks: name, description, status, multi-assignee selection, sortable and paginated list with bulk delete
- Each task can belong to a project (Project dropdown on the task form); the task detail page links back to it
- Projects menu; per-project [Tasks…] button and a Project filter on the task list that shows which project is listed
- Task and project templates (Templates CRUD); on the task create page a template selector prefills the description with the template text
- Filter by assignee/status/parent task/project and search by title/description
- Paginated task list with per-task Edit/Delete shortcuts and multi-select bulk actions (archive, and move the selected tasks into a project)
- Task archive: Delete/Archive actions soft-delete a task (hidden from lists, restorable); permanent deletion happens only from the Archive page, by an administrator or the creator
- Sortable task list: clicking a column name (Task, Status, Assignees, Parent, Updated) orders the list; shared helpers make it easy to reuse on other CRUD lists
- Saved searches on the task list: a Save search button stores the current list filters under a name and the Saved searches dropdown re-opens them; the searches are per user and travel as plain URLs
- Comments, attachments, history and task relationships
- Admin can add status labels and mark completion statuses; existing statuses can be renamed, reordered and deleted from the Administration page (a status still used by tasks and the last remaining status cannot be deleted)
- Admin can add status labels and mark completion statuses
- Per-task auto-reopen schedule: an external cron job resets overdue tasks to TODO, unassigns them and comments "Reopen by auto-scheduler"
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
- The task scheduler needs the `tasks.schedule_period` column. Fresh installs get it from `schema.sql`; existing boards are upgraded automatically (an idempotent `ALTER TABLE ... ADD COLUMN` runs once on the first request after deployment, so the database user needs ALTER permission for that moment).
- User deletion relies on `ON DELETE SET NULL` foreign keys for `tasks.created_by`, `projects.created_by`, `comments.user_id`, `attachments.user_id` and `task_history.user_id`. Fresh installs get this from `schema.sql`. Boards installed before this change must apply it manually (nullable columns + `ON DELETE SET NULL` on those five foreign keys; check the exact constraint names with `SHOW CREATE TABLE`) before deleting users.
- Database relationships are one-directional as entered: for example, on task A choose "blocks" and task B to mean A blocks B.
- Task history currently records task creation, updates, comments, attachments and relationship changes. For a detailed field-by-field audit trail, extend `save_task` to compare old and new values.
- Admin user management supports creating, editing (username, display name, role, password reset) and deleting users. Deleting a user keeps their tasks, comments, attachments and history; the removed account is rendered as "Deleted user", assignee links are removed. You cannot delete your own account and the last administrator cannot be demoted or deleted.
- Deleting a task cascades its comments, attachments metadata, history and links. Uploaded physical files may remain orphaned in `uploads`; periodically clean them up.
- Deleting a project keeps its tasks; they only lose the project reference.
- Project history, comments and attachments are not included: those features stay task-specific for now.

## Upgrading an existing installation
Newer versions can add tables and columns. The updater never touches the database, so
apply the SQL below in phpMyAdmin once when upgrading to the version that introduces
projects (fresh installs get it automatically from `schema.sql`):

```sql
CREATE TABLE projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_project_status FOREIGN KEY (status_id) REFERENCES statuses(id),
    CONSTRAINT fk_project_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE project_assignees (
    project_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (project_id, user_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE tasks
    ADD COLUMN project_id INT UNSIGNED NULL AFTER parent_id,
    ADD CONSTRAINT fk_task_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL;
```

## Task scheduler (auto-reopen)
Tasks can be scheduled to recur: when editing a task, pick a period under
**Auto-reopen schedule** (hourly, daily, weekly, every 2 weeks or monthly).

An external trigger (cron job, web cron or similar) must call the controller URL
periodically, for example every 5 minutes:

```
https://your-domain.example/?page=cronjob
```

The controller needs no login. On every call it reopens each scheduled task that
has not been updated for longer than its period:

- status is reset to the `TODO` status (looked up by name, falling back to the first
  non-completion status),
- all assignees are removed,
- the comment `Reopen by auto-scheduler` and a `Reopened by auto-scheduler` history
  entry are recorded,
- the task's `updated_at` is refreshed, so the next reopen can only happen after a
  full period has passed again.

Tasks that are already TODO **and** unassigned are left untouched (no comment spam
on tasks nobody has picked up yet). Tasks without a schedule are never touched.
The controller answers in plain text, e.g. `Auto-scheduler reopened 2 task(s): #12, #14.`
or `Auto-scheduler: nothing to reopen.`, which is convenient to watch in cron logs.

## Task archive
Deleting a task never removes it right away. The **Archive** button on the task page,
the per-row **Archive** buttons and the bulk **Archive selected** action on the task
list move tasks to the archive instead (`tasks.archived_at` is set; the same pattern
applies to projects once project CRUD exists). Archived tasks:

- disappear from the task list, filters and counts,
- stay viewable on their task page (read-only, with an *Archived* badge),
- can no longer be edited or selected as a parent or relationship target until restored,
- are skipped by the auto-scheduler cron job.

The **Archive** button on the task list opens the archive, where archived tasks can
be viewed, restored (returns the task unchanged to the list) or deleted permanently.
Permanent deletion requires the task to be archived and is limited to an
administrator or the task creator; it cascades comments, attachments metadata,
history and links exactly like the old direct delete did.

## Saved searches
The task list is fully URL-driven: the search box and the four filter dropdowns
submit into `?q=`, `?assignee[]=`, `?status[]=`, `?parent[]=`, `?project[]=` and the
column headers add `?sort=`/`?dir=` — nothing about a filtered list lives in the
session or a cookie. **Save search** on the task list therefore stores exactly that
URL part under a name: the saved search is the actual search link.

- Saving keeps only the known filter and sort parameters (unknown keys, the CSRF
  field and the page number are dropped, so a saved search always opens on page 1)
  and requires at least one real filter (`sort`-only or empty lists cannot be saved).
- Saved searches are personal: each user saves and sees their own, listed in the
  **Saved searches** dropdown under the filter form. The ✕ next to an entry deletes
  it; deletion is limited to the search owner or an administrator, like the templates.
- Opening a saved search navigates to the stored link, which the list page validates
  exactly like a hand-typed URL (bound parameters, allowlisted sort keys), so a
  stored value can never do more than a normal list URL could.

Fresh installs get the `saved_searches` table from `schema.sql`; existing boards are
upgraded automatically on the first request after deployment (the idempotent
self-heal in `tb_migrate_schema()` recreates the table if it is missing).

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

