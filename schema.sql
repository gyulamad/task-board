CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    theme VARCHAR(20) NOT NULL DEFAULT 'light',
    created_at BIGINT NOT NULL DEFAULT 0,
    last_seen_at BIGINT NULL DEFAULT NULL,
    -- Logged-out status (#106): 1 from the moment the user signs out through
    -- the board's own sign-out button until their next successful sign-in.
    -- The activity dot renders the configured logged-out indicator instead of
    -- the last-seen periods while this is set; sessions that die silently
    -- (cookie expiry, browser close) never set it, so only an explicit
    -- sign-out changes what the dot shows.
    logged_out TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE statuses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(40) NOT NULL UNIQUE,
    sort_order INT NOT NULL DEFAULT 0,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    -- Status colours (#42): an optional #RRGGBB value; NULL keeps the default
    -- badge look. Rendered on every status badge of the board.
    color VARCHAR(7) NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status_id INT UNSIGNED NOT NULL,
    -- Nullable since user management (task #17): deleting a user keeps their
    -- projects and clears the creator (rendered as "Deleted user").
    created_by INT UNSIGNED NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    updated_at BIGINT NOT NULL DEFAULT 0,
    CONSTRAINT fk_project_status FOREIGN KEY (status_id) REFERENCES statuses(id),
    CONSTRAINT fk_project_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE project_assignees (
    project_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (project_id, user_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status_id INT UNSIGNED NOT NULL,
    -- Complexity estimation (#44): how big a task is, default 1. Drives the
    -- block width of the dependency waterflow chart page.
    complexity INT UNSIGNED NOT NULL DEFAULT 1,
    -- Priority (#45): ordering weight of a task, default 1, whole numbers.
    priority INT UNSIGNED NOT NULL DEFAULT 1,
    -- Kanban manual order (#154): an explicit per-column position the drag &
    -- drop board writes on every drop (1..N inside the column). 0 means "no
    -- explicit position yet" - those cards keep the pre-#154 board order
    -- (updated_at DESC, id DESC) and sort after the manually placed ones.
    kanban_rank INT UNSIGNED NOT NULL DEFAULT 0,
    parent_id INT UNSIGNED NULL,
    schedule_period VARCHAR(20) NULL DEFAULT NULL,
    -- Scheduler targets (#31): the status and assignee list the cron job sets
    -- when the schedule fires. NULL keeps the #18 defaults (TODO, unassigned).
    schedule_status_id INT UNSIGNED NULL DEFAULT NULL,
    schedule_assignees TEXT NULL DEFAULT NULL,
    project_id INT UNSIGNED NULL,
    -- Nullable since user management (task #17): deleting a user keeps their
    -- tasks and clears the creator (rendered as "Deleted user").
    created_by INT UNSIGNED NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    updated_at BIGINT NOT NULL DEFAULT 0,
    archived_at BIGINT NULL DEFAULT NULL,
    CONSTRAINT fk_task_status FOREIGN KEY (status_id) REFERENCES statuses(id),
    CONSTRAINT fk_task_parent FOREIGN KEY (parent_id) REFERENCES tasks(id) ON DELETE SET NULL,
    CONSTRAINT fk_task_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    CONSTRAINT fk_task_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE task_assignees (
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (task_id, user_id),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    -- Nullable since user management (task #17): deleting a user keeps their
    -- comments and clears the author (rendered as "Deleted user").
    user_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    -- Nullable since user management (task #17): deleting a user keeps their
    -- attachments and clears the uploader (rendered as "Deleted user").
    user_id INT UNSIGNED NULL,
    stored_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE task_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    -- Nullable since user management (task #17): deleting a user keeps their
    -- history entries and clears the actor (rendered as "Deleted user").
    user_id INT UNSIGNED NULL,
    event VARCHAR(80) NOT NULL,
    details TEXT NULL,
    -- Full audit (#54): when the entry records a field change, the field
    -- name and its from/to values live here (human-readable: the status
    -- and project names, task titles, assignee display names). NULL for
    -- the event-level entries (created, comment added, archived, ...).
    field VARCHAR(40) NULL DEFAULT NULL,
    old_value TEXT NULL DEFAULT NULL,
    new_value TEXT NULL DEFAULT NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE task_links (
    task_id INT UNSIGNED NOT NULL,
    related_task_id INT UNSIGNED NOT NULL,
    link_type ENUM('blocks','depends_on','relates_to') NOT NULL,
    PRIMARY KEY (task_id, related_task_id, link_type),
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (related_task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CHECK (task_id <> related_task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('task','project') NOT NULL DEFAULT 'task',
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    updated_at BIGINT NOT NULL DEFAULT 0,
    CONSTRAINT fk_template_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE saved_searches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Saved searches (#29): the list filters travel in the URL only, so a
    -- saved search stores the filter part of the list URL under a name. They
    -- are personal: each user saves and sees their own, and deleting a user
    -- removes their searches with the account.
    user_id INT UNSIGNED NOT NULL,
    name VARCHAR(200) NOT NULL,
    -- The whitelisted filter/sort part of the list URL, rebuilt with
    -- http_build_query() at save time (no page number: a saved search opens
    -- on page 1).
    query TEXT NOT NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    updated_at BIGINT NOT NULL DEFAULT 0,
    CONSTRAINT fk_saved_search_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE app_settings (
    -- App-wide key/value settings (#34): the activity status periods page
    -- stores its configured period list under the name 'activity_levels' as
    -- JSON. An absent row means the built-in periods are in effect.
    name VARCHAR(80) PRIMARY KEY,
    value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Know-hows (#53): the knowledge base. Documents hold the standard
-- processes, rule books and any other know-how; comments and attachments
-- mirror the task page's shapes, in their own tables so the task tables'
-- task_id foreign keys stay untouched.
CREATE TABLE documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    content TEXT NULL,
    -- Nullable since user management (task #17): deleting a user keeps their
    -- documents and clears the creator (rendered as "Deleted user").
    created_by INT UNSIGNED NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    updated_at BIGINT NOT NULL DEFAULT 0,
    CONSTRAINT fk_document_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE document_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE document_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    stored_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Message board (#56): a user leaves a message to another user. Both ends are
-- nullable with ON DELETE SET NULL so deleting a user keeps the conversation
-- (rendered as "Deleted user", the #17 convention); is_read drives the red
-- "new messages" notification on the message board and in the navbar.
CREATE TABLE messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sender_id INT UNSIGNED NULL,
    recipient_id INT UNSIGNED NULL,
    body TEXT NOT NULL,
    is_read TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at BIGINT NOT NULL DEFAULT 0,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Message attachments (#94): every chat message can carry one file. The row
-- mirrors the task/document attachment convention (random stored_name inside
-- uploads/, real name and MIME kept for the download); it cascades away with
-- its message and survives a user deletion through the SET NULL uploader.
CREATE TABLE message_attachments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    stored_name VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    created_at BIGINT NOT NULL DEFAULT 0,
    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Version-marked SQL migrations (#59/#68): every migrations/<version>-<slug>.sql
-- file that has not run yet is applied automatically on the first request that
-- sees it (and by the Software updates page after an install), oldest version
-- first. This table records what already ran so each migration runs exactly
-- once; see migrations/README.md for the naming convention.
CREATE TABLE migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(64) NOT NULL UNIQUE,
    applied_at BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notifications (#58): the board drops one row per task-history event into
-- every assignee's stream (minus the actor), so each user sees at the top of
-- the board and on the Notifications page which of their tasks moved. Both
-- ends cascade: without the task (or the recipient) a notification has no
-- meaning, unlike a conversation. is_seen drives the red navbar badge.
CREATE TABLE notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    task_id INT UNSIGNED NOT NULL,
    history_id INT UNSIGNED NULL,
    event VARCHAR(100) NOT NULL,
    is_seen TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at BIGINT NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (history_id) REFERENCES task_history(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User preferences (#66): per-user key/value storage for board UI state.
-- First consumer: the kanban board column open/close toggle - the collapsed
-- status ids of a user live in the 'kanban_collapsed_statuses' key. Rows
-- cascade away with their user.
CREATE TABLE user_preferences (
    user_id INT UNSIGNED NOT NULL,
    pref_key VARCHAR(100) NOT NULL,
    pref_value VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (user_id, pref_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- User list views (#120): the persistent home of the #113 stored list view
-- selections - the advanced filter setups and the picked saved search of
-- every list page survive logout/login, per user and per page. The session
-- stays the working copy; rows cascade away with their user. TEXT keeps a
-- rich multi-select filter from ever being truncated.
CREATE TABLE user_list_views (
    user_id INT UNSIGNED NOT NULL,
    page VARCHAR(40) NOT NULL,
    view_query TEXT NOT NULL,
    PRIMARY KEY (user_id, page),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO statuses (name, sort_order, is_done) VALUES
('TODO', 1, 0), ('IN PROGRESS', 2, 0), ('DONE', 3, 1);
