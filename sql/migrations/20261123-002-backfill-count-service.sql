-- Backfill users.count_service from the live orders table.
-- Run once per database (tracked in schema_migrations); safe to rerun —
-- the statement is idempotent and cheap enough against the hot-path index.
UPDATE `users` u
SET u.`count_service` = (SELECT COUNT(*) FROM `orders` o WHERE o.`from_id` = u.`from_id`);
