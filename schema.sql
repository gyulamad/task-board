CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    role ENUM('admin','user') NOT NULL DEFAULT 'user',
    theme VARCHAR(20) NOT NULL DEFAULT 'light',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE statuses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(40) NOT NULL UNIQUE,
    sort_order INT NOT NULL DEFAULT 0,
    is_done TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status_id INT UNSIGNED NOT NULL,
    -- Nullable since user management (task #17): deleting a user keeps their
    -- projects and clears the creator (rendered as "Deleted user").
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at TIMESTAMP NULL DEFAULT NULL,
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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_saved_search_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE app_settings (
    -- App-wide key/value settings (#34): the activity status periods page
    -- stores its configured period list under the name 'activity_levels' as
    -- JSON. An absent row means the built-in periods are in effect.
    name VARCHAR(80) PRIMARY KEY,
    value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO statuses (name, sort_order, is_done) VALUES
('TODO', 1, 0), ('IN PROGRESS', 2, 0), ('DONE', 3, 1);
