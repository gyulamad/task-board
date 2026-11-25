-- User list views (#120): the persistent home of the #113 stored list view
-- selections - the advanced filter setups and the picked saved search of
-- every list page now survive logout/login, per user and per page. The
-- session stays the working copy; this table is written on every capture,
-- cleared by the explicit clear and hydrated into a fresh session at the
-- first list-page request after a sign-in.
-- Dialect-neutral on purpose (runs verbatim on MySQL and SQLite): the
-- view_query column is TEXT on both dialects, so a rich multi-select filter
-- is never truncated the way the 255-character user_preferences rows would
-- truncate it. Fresh installs get the same table from schema.sql, and the
-- boot-time schema check recreates it if a deployment ever lands without
-- this file (the #66 self-heal discipline).
CREATE TABLE IF NOT EXISTS user_list_views (
    user_id INT UNSIGNED NOT NULL,
    page VARCHAR(40) NOT NULL,
    view_query TEXT NOT NULL,
    PRIMARY KEY (user_id, page),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
