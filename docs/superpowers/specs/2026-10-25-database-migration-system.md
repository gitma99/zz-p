# Database Migration System — v3.1.0 Baseline

## Intent and why

`sql/sql.sql.php` was a one-way ratchet: `CREATE TABLE IF NOT EXISTS` and
"add column/index if missing" guards only add schema objects, never remove
them. Once something landed in `sql/sql.php`, every fresh install got it
forever — even after it proved to be a mistake. There was no way to tell a
new install "don't create `orders_archive`" while telling an existing
install "remove it."

We replaced that with versioned migrations tracked in a `schema_migrations`
table. `sql/sql.php` is now a thin compatibility shim that only ensures the
tracker table exists and returns JSON status. All schema creation lives in
versioned SQL files under `sql/migrations/`, applied by
`tools/run-migrations.php` on every boot, install, and update.

## Goals

- Existing v3.1.0 production DBs receive new schema changes automatically,
  without manual DDL
- Fresh installs converge on the init migration + all versioned migrations
- Re-running migrations is safe (each file is self-guarding, tracked in
  `schema_migrations`)
- Single migration runner shared by Docker install, bare-metal install,
  and update — no duplicate test systems

## Non-goals

- Down migrations / rollbacks
- Migration framework dependency (Phinx, Laravel, etc.)
- Data migration beyond DDL

## Constraints

- PHP 7.4 compatible runner code
- MySQL 8.0 has no `CREATE INDEX IF NOT EXISTS` / `DROP INDEX IF EXISTS` —
  every migration file must be self-guarding (`SHOW INDEX`, `SHOW COLUMNS`,
  `DROP ... IF EXISTS`)
- Migration files are pure SQL (no PHP wrappers) — executed directly via
  `mysqli::multi_query()`
- `sql/sql.php` must still return JSON status for the HTTP update path in
  `update.sh`

## What you decided

- Versioned migrations: an init migration (`001`) creates the complete base
  schema + seed data; subsequent migrations (`002`, ...) apply changes
- `sql/sql.php` stripped to a thin shim: `schema_migrations` table +
  JSON response only
- Runner applies migrations in sorted filename order, skips already-applied
  ones, records each with a timestamp
- Baseline: `v3.1.0` (last production tag; `sql/sql.php` was byte-identical
  between v3.1.0 and HEAD committed state)

## Files

| File | Role |
|------|------|
| `sql/migrations/20261025-001-init-schema.sql` | Complete initial schema: all 23 base tables + seed data + `schema_migrations` tracker. Runs once. |
| `sql/migrations/20261025-002-hot-path-indexes.sql` | Four guarded `ALTER TABLE ... ADD INDEX` statements for hot-path tables. |
| `sql/sql.php` | Thin shim: creates `schema_migrations` defensively, returns JSON status. No table creation. |
| `tools/run-migrations.php` | PHP 7.4 runner: applies unapplied migrations in sorted order, records each, drains all result sets, checks `errno` so mid-file failures are not silent. |
| `docker-entrypoint.sh`, `install.sh`, `update.sh` | Each runs the migration runner after `sql/sql.php`. |

## Behavior on existing v3.1.0 production DB

1. **First boot after deploy:** `sql/sql.php` creates `schema_migrations`
   (empty). Runner applies `001-init-schema.sql` — every `CREATE TABLE IF
   NOT EXISTS` is a no-op because tables already exist. Then applies
   `002-hot-path-indexes.sql` — adds the 4 missing indexes. Both filenames
   are recorded in `schema_migrations`.
2. **Every boot after that:** Runner sees both migrations already tracked,
   skips them. Prints "No new migrations to apply."

## Behavior on fresh install

1. `sql/sql.php` creates empty `schema_migrations`.
2. Runner applies `001-init-schema.sql` — creates all base tables + seed
   data + `schema_migrations` tracker.
3. Runner applies `002-hot-path-indexes.sql` — adds all 4 indexes.
4. Both filenames recorded. Fresh DB matches production schema.

## Verification

- End-to-end on MySQL 8.0.46: init migration creates all 24 tables
  (including `orders_archive` PK-only), indexes migration adds all 4
  indexes, runner tracks both in `schema_migrations`, re-run applies
  nothing
- Seed data (`auth_setting`, `settings`, `spam_setting`, etc.) inserted by
  init migration
- `orders_archive` stays PK-only — hot-path indexes are NOT copied into it
  (created before indexes exist via `LIKE orders`)
- Broken migration: runner exits non-zero, does not record the file
- PHP 7.4 lint clean on `sql/sql.php` and `run-migrations.php`
