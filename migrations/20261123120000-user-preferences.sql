-- User preferences (#66): per-user key/value storage for board UI state.
-- First consumer: the kanban column open/close toggle - the collapsed status
-- ids of a user live in the 'kanban_collapsed_statuses' key.
-- Dialect-neutral on purpose: this runs verbatim on MySQL and on the SQLite
-- test harness alike. Fresh installs get the same table from schema.sql, and
-- tb_migrate_schema() recreates it if a deployment ever lands without this
-- file. Rows cascade away with their user.
CREATE TABLE IF NOT EXISTS user_preferences (
    user_id INT UNSIGNED NOT NULL,
    pref_key VARCHAR(100) NOT NULL,
    pref_value VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (user_id, pref_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
