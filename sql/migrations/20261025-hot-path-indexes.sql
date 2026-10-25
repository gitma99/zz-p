-- Hot-path secondary indexes (migration from v3.1.0 baseline)
--
-- MySQL 8.0 has no `CREATE INDEX IF NOT EXISTS`, so each index is guarded
-- with `SHOW INDEX` first — same pattern as `sql/sql.php:248-252`.
--
-- Idempotent: safe to run any number of times. Only indexes not already
-- present are added.

-- 1) orders: idx_orders_from_id_code
-- `orders`.`code` holds the BASE plan name (e.g. "vip1"), shared by every
-- customer of that plan, so it has almost no selectivity on its own. A single
-- composite index on (from_id, code) covers both:
--   - `WHERE from_id = ? AND code = ?`          (18 sites across index.php/custom.php)
--   - `WHERE from_id = ?`                       (10 sites via leftmost prefix)
--
-- Guarded: skip if the index already exists.
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'orders'
       AND index_name = 'idx_orders_from_id_code') = 0,
    'ALTER TABLE `orders` ADD INDEX `idx_orders_from_id_code` (`from_id`, `code`)',
    'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) users: idx_users_from_id
-- Every webhook looks up the requesting user with
--   `SELECT * FROM `users` WHERE `from_id` = ? LIMIT 1`
-- and coin deduction uses `UPDATE `users` SET coin = coin - ? WHERE `from_id` = ? LIMIT 1`.
-- Previously a full table scan per request.
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'users'
       AND index_name = 'idx_users_from_id') = 0,
    'ALTER TABLE `users` ADD INDEX `idx_users_from_id` (`from_id`)',
    'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) service_factors: idx_service_factors_from_id
-- Purchase/renewal flow checks and deletes service factors per user with
--   `SELECT * FROM `service_factors` WHERE `from_id` = ?`
--   `DELETE FROM `service_factors` WHERE `from_id` = ?`
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'service_factors'
       AND index_name = 'idx_service_factors_from_id') = 0,
    'ALTER TABLE `service_factors` ADD INDEX `idx_service_factors_from_id` (`from_id`)',
    'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) factors: idx_factors_code
-- Payment callback looks up and updates a factor by its payment code with
--   `SELECT * FROM `factors` WHERE `code` = ?`
--   `UPDATE `factors` SET `status` = ? WHERE `code` = ?`
-- The `factors` table grows forever (one row per payment attempt, never
-- deleted), so without this index every payment callback scans all history.
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'factors'
       AND index_name = 'idx_factors_code') = 0,
    'ALTER TABLE `factors` ADD INDEX `idx_factors_code` (`code`)',
    'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
