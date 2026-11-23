# Database migrations

Version-marked SQL migrations that the board applies automatically (#59/#68).

## How it works

- Every `*.sql` file in this folder whose name matches
  `<version>-<slug>.sql` (or just `<version>.sql`) is a migration.
  `<version>` is a 14-digit UTC stamp (`YYYYMMDDHHMMSS`), which sorts
  lexicographically in execution order. The optional slug is only there for
  humans: `20261123120000-add-task-colors.sql`.
- Each migration runs **once**. Applied versions are recorded in the
  `migrations` table; a file whose version is already recorded is skipped.
- Migrations apply **automatically**:
  - on the first request that sees a pending file (so a git deployment, an
    upgrade pack or a manually added file never needs hand-run SQL), and
  - right after the Software updates page installs an update, and
  - immediately after an upload on the Software updates page
    (`?page=updates` → "Upload a SQL migration").
- Files apply oldest-first, and the run stops at the first failing migration:
  the failing version is not recorded, so the (fixed) script retries on the
  next request. Later scripts never run before earlier ones succeed.

## Writing migration scripts

- Plain DDL/DML only. Statements end at a semicolon that closes the line
  (the same mechanical rule the schema importer uses) — no triggers or
  stored procedures with embedded semicolons.
- Write scripts re-runnable (`CREATE TABLE IF NOT EXISTS`, guarded `ALTER`s):
  on MySQL the driver auto-commits every DDL statement, so a failed
  multi-statement migration can leave earlier statements in place while the
  version stays unrecorded. A re-runnable script converges on the retry; on
  SQLite the failed script rolls back completely.
- Fresh installs get the full schema from `schema.sql`, so a migration must
  not assume the database is empty — guard accordingly.
- Keep a migration dialect-neutral when possible, or ship both dialects in
  one script guarded by driver-specific statements only where they differ.

## Uploading a migration without a deployment

Software updates → "Upload a SQL migration" stores the file here with the
current UTC stamp plus the original filename as the slug, applies it
immediately and reports the result. A script the driver rejects is not kept.
