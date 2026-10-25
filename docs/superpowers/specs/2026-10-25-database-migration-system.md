# Database Migration System — v3.1.0 Baseline

## Intent and why

`sql/sql.php` is a one-way ratchet: `CREATE TABLE IF NOT EXISTS` and
"add column/index if missing" guards only add schema objects, never remove
them. Once something lands in `sql/sql.php`, every fresh install gets it
forever — even after it proves to be a mistake. We need versioned
migrations so schema changes reach existing v3.1.0 production databases
without manual SQL, while new installs converge on the target state.

## Goals

- Existing v3.1.0 production DBs receive new schema changes (indexes,
  drops, renames) automatically, without manual DDL
- Fresh installs converge on `sql/sql.php` + all migrations
- Re-running migrations is safe (every migration file is self-guarding)
- Single migration runner shared by Docker install, bare-metal install,
  and update — no duplicate test systems

## Non-goals

- Down migrations / rollbacks
- Migration framework dependency (Phinx, Laravel, etc.)
- Data migration beyond DDL

## Anti-goals

- Editing `sql/sql.php` to add removals without a matching migration file
- Two migration runners side by side

## Constraints

- PHP 7.4 compatible runner code
- MySQL 8.0 has no `CREATE INDEX IF NOT EXISTS` / `DROP INDEX IF EXISTS` —
  every migration file must be self-guarding (`SHOW INDEX`, `SHOW COLUMNS`,
  `DROP ... IF EXISTS`)
- Must not break `sql/sql.php`'s existing self-application on container boot

## What you decided

- Option B: target-state `sql/sql.php` + versioned idempotent migration
  files in `sql/migrations/` tracked by a `schema_migrations` table
- Baseline: `v3.1.0` (last production tag; `sql/sql.php` is byte-identical
  between v3.1.0 and HEAD committed state)
- First migration: hot-path indexes on `orders`, `users`,
  `service_factors`, `factors` (your existing uncommitted index work)
- Runner hooks into `docker-entrypoint.sh`, `install.sh`, `update.sh`

## What's left to the builder

- Write `sql/migrations/20261025-hot-path-indexes.sql`
- Add `schema_migrations` table creation to `sql/sql.php`
- Write `tools/run-migrations.php`
- Hook runner into `docker-entrypoint.sh`, `install.sh`, `update.sh`
- Verify end-to-end on real MySQL 8
