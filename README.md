# PHP Task Board

A lightweight multi-user task and message board for PHP 8.2+ and MySQL/MariaDB shared hosting.

## Included
- Session login; password hashes using PHP `password_hash()`
- Admin and regular user roles; admin can create, edit (username, display name, role, password reset) and delete users
- Task CRUD, multi-assignee selection, status and parent task
- Project CRUD with the same building blocks as tasks: name, description, status, multi-assignee selection, sortable and paginated list with bulk delete
- Each task can belong to a project (Project dropdown on the task form); the task detail page links back to it
- Creating a task with no Project selected pops a confirmation box first: OK stores the project-less task, Cancel keeps you on the form to pick one; editing keeps saving without the dialog (#47)
- Projects menu; per-project [Tasks…] button and a Project filter on the task list that shows which project is listed
- Task and project templates (Templates CRUD); on the task create page a template selector prefills the description with the template text
- Filter by assignee/status/parent task/project and search by title/description; the project filter also offers a "No project" option for the tasks that belong to no project (#28)
- Advanced filter toggle (#48): the task list, kanban and waterflow filter cards show only the free text search bar by default; an Advanced filter button reveals the four dropdowns, the Filter button and the saved searches, and the area starts expanded whenever the view is already filtered by one of the advanced keys
- Paginated task list with a sortable Project column (which project each task belongs to) and per-task Edit/Delete shortcuts and multi-select bulk actions (archive, and move the selected tasks into a project)
- Task archive: Delete/Archive actions soft-delete a task (hidden from lists, restorable); permanent deletion happens only from the Archive page, by an administrator or the creator
- Sortable task list: clicking a column name (Task, Status, Assignees, Parent, Updated) orders the list; shared helpers make it easy to reuse on other CRUD lists
- Saved searches on the task list: a Save search button stores the current list filters under a name and the Saved searches dropdown re-opens them; the searches are per user and travel as plain URLs
- Full change audit (#54): every task save writes one history row per changed field with the from/to values recorded human-readable (status and project names, task titles, assignee names), and every history row on the task page links to a change details page showing exactly who changed what from what, when
- Kanban board page (#43): one column per task status with every non-archived task in its status column; the task list's filter card and saved searches sit on top, shared as one implementation (DRY) so filters and searches behave the same on both views
- Clone tasks (#46): a Clone button on the task edit page opens the create page prefilled from the source task (title, description, status, complexity, parent, project, schedule targets and assignees); modifying and saving creates a brand-new task, cancelling leaves the original untouched
- Complexity estimation and dependency waterflow chart (#44): every task carries a complexity (default 1, edited on the task form); the Waterflow page arranges all non-archived tasks into dependency levels from their "Depends on" links and sizes each task's block width by its complexity relative to the widest task in view, with the shared filter card and saved searches on top
- Settings page (#55): a personal Settings page in the navbar, currently showing an under-development notice — it is the base the future preference screens will build on
- Task priority (#45): every task carries a priority number (default 1, edited on the task form, shown on the task page) and the task list gained a sortable Priority column so the biggest numbers sort to the top
- Comments, attachments, history and task relationships
- Know-hows knowledge base (#53): a Documents page with CRUD for every process, rule book and other know-how worth writing down — each document can carry comments and attachments of its own (the same upload allowlist, downloads and deletion rules as the tasks), with a title/content search, and editing or deleting reserved to the document's creator or an administrator
- Task and project administration split into focused pages (#42): the Administration page is a compact hub linking to Users (create, edit, delete accounts) and Task statuses (rename, reorder, completion flag, colour, delete), with the uploads maintenance action and the activity periods link kept on the hub
- Admin can add status labels and mark completion statuses; existing statuses can be renamed, reordered, given an optional #RRGGBB colour and deleted on the Task statuses page (a status still used by tasks and the last remaining status cannot be deleted). The colour renders on every status badge of the board — task list, task/project/archive pages — with an automatically readable text colour; statuses without one keep the built-in badge look
- Last activity on the Users administration page: a Last active column shows when each user was last seen ("active: 23 minutes ago", "1h 12m ago", "never"); refreshed by authenticated requests, throttled to one write per couple of minutes (#30)
- Activity dots next to every user name across the board (navbar, task/project lists and detail pages, comments, attachments, history, Users page): green = active in the last 15 minutes, yellow = not active in the last hour, red = inactive longer or never seen; one shared renderer (tb_user_with_activity) keeps them consistent (#30)
- Per-task auto-reopen schedule: an external cron job resets overdue tasks to TODO, unassigns them and comments "Reopen by auto-scheduler"
- Mobile friendly navigation: below the `lg` breakpoint the menu (links, theme switcher, sign-out) collapses behind a hamburger toggler and drops down only when toggled; from `lg` up the bar stays expanded as before (#32)
- Admin-only software updater for the latest GitHub release or a selected repository branch
- Full backup download on the Software updates page (#49): one ZIP with the complete application code (including config.php), every file of the uploads folder and an SQL dump of the whole database, built on demand and streamed straight to the administrator
- Realtime list views (#51): the task list, the Kanban board, the waterflow chart and the Users page re-render their list region every 10 seconds — status changes and activity dots show up without a manual reload, while checkbox selections and other in-region form data stay exactly as the user left them
- Message board (#56): a Messages page where users leave messages to each other (recipient picker, inbox, sent list), with a red notification showing the number of NEW (unread) messages at the top of the page and as a badge next to the navbar's Messages link; viewing the board marks the messages read, so the count always means "new since your last visit"
- User list CRUD (#57): the admin Users page gets the same list features as the other CRUD lists — free text search over username and display name, a role filter (multi-select, unknown values ignored), sortable columns (display name, username, role, created, last active) through the shared sort helpers and pagination — while the realtime region (#51) keeps wrapping the list
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
- The full backup download (#49) re-checks the administrator password and streams a ZIP that contains `config.php` and the whole database (password hashes included) to the browser; nothing is kept on the server. Store the downloaded archive somewhere safe.
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

- status is reset to the task's **reopen status** — picked on the edit form right
  next to the period; tasks without one go to the `TODO` status (looked up by name,
  falling back to the first non-completion status),
- assignees are replaced with the task's **reopen assignees** — also picked on the
  edit form (Ctrl/Cmd-click for several); tasks without any are left unassigned,
- the comment `Reopen by auto-scheduler` and a `Reopened by auto-scheduler` history
  entry are recorded,
- the task's `updated_at` is refreshed, so the next reopen can only happen after a
  full period has passed again.

The reopen targets ride on the task itself (`tasks.schedule_status_id`,
`tasks.schedule_assignees`, set together with the period by the edit form; the
database upgrades itself on the first request after deployment, like the other
scheduler columns). A status or user that was deleted after scheduling is skipped:
the reopen falls back to the default status and drops the missing assignee.

Tasks that already sit in their target state (status **and** assignees) are left
untouched (no comment spam on tasks nobody has picked up yet). Tasks without a
schedule are never touched.
The controller answers in plain text, e.g. `Auto-scheduler reopened 2 task(s): #12, #14.`
or `Auto-scheduler: nothing to reopen.`, which is convenient to watch in cron logs.

## Message board (#56)
The **Messages** page (in the navbar for every signed-in user) is a simple
board where users leave messages to each other: pick a recipient, write the
message, send. The page holds the **Inbox** (messages to you, newest first,
unread rows bold with a "new" badge) and the **Sent** list, plus the compose
form. A red notification at the top of the page shows the number of **NEW**
(unread) messages — "2 new messages" — and the navbar's Messages link carries
the same count as a red badge on every page, so a new message is visible
without opening the board. Viewing the board marks its messages read, which
keeps the count honest: it always means "new since your last visit". Users can
only message other users (never themselves), and messages survive user
deletion — both ends render as "Deleted user", the established #17 convention.
The `messages` table comes from `schema.sql` on fresh installs and is recreated
by the idempotent self-heal in `tb_migrate_schema()` on existing boards (the
same discipline as every other feature — the navbar badge reads the table on
every page, so a deployment without the upgrade SQL cannot take the board
down).

## Change details, full audit (#54)
The task page's History is a full audit trail: every save of the task form
writes one history row **per changed field** (title, description, status,
complexity, priority, parent task, project, schedule period, schedule status,
schedule assignees and the assignee roster), with the from/to values recorded
human-readable — status and project names, task titles and assignee display
names instead of raw ids. The board's generic "Task updated" entry stays, so
nothing that reads the history changes meaning.

Since the #54 v2 feedback the generic save entry says exactly what happened
instead of a vague "may have changed": its details text reads
"The followings are changed: Status, Assignees. Status changed from "TODO" to
"IN PROGRESS"; Assignees changed from "Jane" to "John"." — and the change
details page of that entry renders the same list as a From/To table of exactly
that save's fields (each changed field links to its own field row's details
page). Comment entries carry the comment itself as their details text and link
to it on the task page, so the audit shows what was written, not just that
something was added.

- Every history row carries a **details** link to the change details page
  (`?page=task_history&id=N`): the event, who made the change and when, and
  for field changes a From/To table with the exact recorded values.
- Event-level entries (task created, comments, attachments, archive, restore,
  bulk actions) keep their free-text details and render without the field
  table; their details link still shows the full record.
- The audit columns (`field`, `old_value`, `new_value`) are added to
  `task_history` by the idempotent self-heal in `tb_migrate_schema()` on
  existing boards and come from `schema.sql` on fresh installs; older rows
  keep NULL and render as event entries.

## Kanban board (#43)
The **Kanban** link in the menu opens a second view of the tasks: one column per
task status (in display order, with the status colour badge from #42 and a live
count), every non-archived task listed as a card in its status column with its
assignees, project and parent task. Empty columns say so instead of disappearing.

The filter card on top is the task list's own component
(`tb_render_task_filter_card`) and the filtering is the task list's own
(`tb_task_filters_where`) — both extracted so the two views share one
implementation (DRY). The search box, the assignee/status/parent/project
dropdowns and the saved searches behave exactly like on the task list:

- a search saved from the board stores the same whitelisted filter URL and
  returns to the board; the same saved search opens on the task list from there
  and vice versa,
- opening a saved search from the board navigates to `?page=kanban&...`, from
  the task list to `?page=tasks&...`,
- archived tasks never appear on the board (they live in the archive only).

## Clone tasks (#46)
The task edit page has a **Clone** button next to Save/Cancel. It opens the task
create page as `?page=new&clone=<id>` with every form field prefilled from the
source task — title, description, status, complexity, parent, project, the
auto-reopen schedule targets and the assignees. The prefilled form can be
modified and saved like any other create (the regular save_task action runs, so
the clone gets its own history entry) or cancelled. Comments, attachments,
history and relationships are not copied: they belong to the original task, and
the clone is a brand-new task. Cloning works from archived sources too — the
copy is always a fresh, non-archived task — and an unknown clone id answers 404.

## Task complexity and the dependency waterflow chart (#44)
Every task carries a **complexity** — how big the task is, a whole number from 1
to 1000, default 1. It is edited on the task form right next to the status and
shown on the task page; fresh installs get `tasks.complexity` from `schema.sql`,
existing boards are upgraded automatically on the first request after deployment
(the idempotent `ALTER TABLE tasks ADD COLUMN complexity` in `tb_migrate_schema()`).

The **Waterflow** link in the menu opens the dependency chart: all non-archived
tasks arranged in levels that follow their "Depends on" links — level 0 holds the
tasks nothing in the view depends on, level N the tasks whose prerequisites all
sit in earlier levels (`tb_waterflow_levels()`; dependencies on archived or
filtered-out tasks cannot block, and tasks stuck in a dependency cycle are parked
below the flow so the chart always terminates). Every task renders as a block
whose width is its complexity relative to the widest complexity in the current
view, labelled with the ×complexity and the status badge.

The filter card on top is the task list's shared component (DRY, like the kanban
board): search box, assignee/status/parent/project dropdowns and saved searches
all behave exactly like on the task list, and a search saved from the chart
returns to the chart while the same saved search still opens on the task list.

## Task priority (#45)
Every task carries a **priority** — an ordering weight, a whole number from 1 to
1000, default 1. It is edited on the task form next to the complexity and shown
on the task page; the task list has a sortable **Priority** column (the same
allowlisted sort mechanics as the other columns, so `?sort=priority&dir=desc`
works and travels inside saved searches). Fresh installs get `tasks.priority`
from `schema.sql`; existing boards are upgraded automatically on the first
request after deployment (the idempotent `ALTER TABLE tasks ADD COLUMN priority`
in `tb_migrate_schema()`). The number itself carries no behaviour — it is a
sorting/visibility aid, not an escalation rule.

## Creating a task without a project (#47)
The **Project** dropdown on the task form is optional. To keep project-less
tasks a conscious choice, saving a NEW task with no project selected pops a
confirmation box — "No Project is selected. Create this task without a
project?" — before anything is stored: **OK** creates the task without a
project exactly as before, **Cancel** aborts the save so a project can be
picked first. The dialog is the same built-in confirm pattern the board
already uses for its destructive actions (archive, delete, bulk moves), so
no new script or dependency comes with it. Editing an existing task keeps
saving without a dialog: the stored project is visible on the edit form and
unsetting it is a deliberate change, not an oversight to warn about.

## Advanced filter toggle (#48)
The filter card on the task list (and its copies on the kanban board and the
waterflow chart — one shared component) used to show everything at once. Since
#48 it opens with the **free text search bar only**, and an **Advanced filter**
button reveals the rest of the card: the assignee/status/parent/project
dropdowns, the Filter button and the saved searches with their Save search
form. The area starts **expanded** whenever the current view is already
filtered by one of the advanced keys (the toggle shows an "on" badge then), so
an applied filter is never hidden from whoever opens such a URL — a saved
search, a project's Tasks… link or a shared filtered link all land on a card
that shows what is active. The collapse only hides the inputs visually; they
stay in the DOM and keep submitting, and the two forms carry each other's
current values as hidden inputs, so a plain search keeps the active dropdown
filters and the Filter button keeps the typed search text.

## Settings page (#55)
The navbar's **Settings** entry opens the personal settings page
(`?page=settings`). It currently shows an under-development notice — the page
exists so the future preference screens (profile details, notification and
display preferences) have a home to grow into. Only signed-in users can reach
it, like every other page.

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

## Administration pages (#42)
The admin areas live on focused pages instead of one long Administration page:

- **Administration** (`?page=admin`) is the hub: link cards to the Users and
  Task statuses pages, the activity status periods and the software updater,
  plus the uploads maintenance action (clean up orphaned attachment files).
- **Users** (`?page=users`) holds the account management: the create form and
  the users table with role badges, the Last active column (#30) and the
  edit/delete actions. User create/edit/delete actions return here.
- **Task statuses** (`?page=statuses`) holds the status editor: rename, display
  order, the completion flag and the delete guard rails, plus the new colour
  picker. Every row shows the badge exactly as the board renders it, a colour
  input and a **Default colour** checkbox that restores the built-in look.

Status colours are stored as `#rrggbb` in `statuses.color`; `NULL` keeps the
default badge (`badge text-bg-light border`) the board has always rendered. The
shared `tb_status_badge()` renderer outputs the fill with an automatically
readable text colour (dark text on light fills, white on dark ones, from the
WCAG relative luminance) and falls back to the default badge for any value that
is not a well-formed `#rrggbb`. Fresh installs get the column from `schema.sql`;
existing boards are upgraded automatically on the first request after deployment
(the idempotent `ALTER TABLE statuses ADD COLUMN color` in `tb_migrate_schema()`).

## Know-hows knowledge base (#53)
The **Know-hows** page (in the navbar for every signed-in user) is the board's
own knowledge base — a place for standard processes, common documents, rule
books and anything else that needs to be written down, "like a confluence
database". Documents have CRUD, comments and attachments:

- **List** (`?page=documents`): title, excerpt, last update, creator and the
  comment/attachment counts, with a title-and-content search (the same
  `|`/`%`/`_` escaping as the task search) and pagination.
- **View** (`?page=document&id=N`): the content in the task description's
  wrap-preserving block, the attachments (upload, download, delete) and the
  comments — all mirroring the shapes and rules of the task page: the same
  upload MIME allowlist and size cap, the same uploads folder, the same
  download header sanitization and the same uploader-or-administrator
  attachment deletion.
- **Create/edit** (`?page=document_new`, `?page=document_edit&id=N`): title
  (200 characters) and plain-text content; editing and deleting stay with the
  document's creator or an administrator, the same creator-or-admin rule the
  task archive enforces. Every signed-in user can read, comment and upload.
- Deleting a document removes its comments, attachments and the stored files
  with it; deleting a user keeps their documents (creator renders as
  "Deleted user"). The three new tables (`documents`, `document_comments`,
  `document_attachments`) come from `schema.sql` on fresh installs and are
  recreated by the idempotent self-heal in `tb_migrate_schema()` on existing
  boards; the uploads orphan sweep knows the document attachments too.

## Full backup download (#49)
The Software updates page has a **Download full backup** button: an administrator
entering their current password receives one ZIP package that is the complete
restore point of the installation — the thing to take right before applying an
update (the install form's reminder to make a database backup points at it).

- `code/` mirrors the application directory as served, including `config.php`
  (a backup without it would not be restorable); `.git`, `vendor/` and stray
  `*.zip`/`*.sqlite` files parked in the root are excluded.
- `uploads/` contains the uploaded attachment files exactly as stored on disk,
  including the folder's `.htaccess`.
- `database/` holds a full SQL dump of every table (schema and data), wrapped in
  `SET FOREIGN_KEY_CHECKS=0/1` on MySQL/MariaDB so the import order cannot trip
  over foreign keys; `MANIFEST.txt` documents what the archive holds and how to
  restore it.
- The archive is built in a private temporary file and streamed to the browser —
  no copy is kept on the server. The action is a CSRF-protected POST and
  re-checks the current administrator password, exactly like the install action.

## Realtime list views (#51)
The four list views — the task list, the Kanban board, the dependency waterflow
chart and the Users administration page — refresh themselves: a small script
re-fetches the page URL every 10 seconds and swaps only the list region
(`<div id="tb-list-region" data-tb-refresh="10">`) with the freshly rendered
one, so a Kanban status change or a user's activity dot turning red shows up
without a manual reload.

- The user's in-region form state survives every tick: checkbox values (the
  bulk selection and "select all") and dropdown values (the bulk "Move to
  project" target) are re-applied after each swap. A region whose fresh
  content is identical is not touched at all, so a quiet page never flickers.
- The refresh pauses while the tab is hidden and catches up the moment the tab
  becomes visible; a failed poll just waits for the next tick, and an expired
  session finds no region on the sign-in page and stops instead of failing.
- No new endpoint and no dependency: the refresh fetches the same URL the
  browser is on and parses it client-side; the task list's bulk-selection
  wiring is event-delegated so it keeps working across swaps (it also re-syncs
  the button disabled states via the `tb-list-region:swapped` event).
- The interval is a per-page knob: the `data-tb-refresh` attribute carries the
  seconds (10 everywhere by default).

## User list CRUD (#57)
The **Users** administration page (`?page=users`) holds the same list
building blocks as the other CRUD lists, reusing their shared helpers (DRY):

- **Search** (`q`): free text over username and display name, with the same
  `|`/`%`/`_` escaping discipline as the task search, so a literal `%` is not
  a wildcard.
- **Role filter**: a multi-select (`role[]`) that matches any of the picked
  roles, accepts legacy scalar links and ignores unknown values (#25
  conventions).
- **Sortable columns**: Display name, Username, Role, Created and Last active
  order through the shared `sort_state()`/`sort_header()` helpers with the
  same allowlist discipline as the task list — unknown sort keys fall back to
  the default and the database stays untouched.
- **Pagination**: 20 users per page with the same pagination window and
  page labels as the other lists; search, role filter and sort travel through
  the header and page links.

The create-user form stays outside the swapped region, and the realtime
refresh (#51) keeps re-rendering the list every 10 seconds with the active
search and filter state preserved.

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

