-- Hot-path secondary indexes (migration from v3.1.0 baseline)
--
-- MySQL 8.0 has no `CREATE INDEX IF NOT EXISTS`, so each index is guarded
-- with `SHOW INDEX` first — same pattern as `sql/sql.php:248-252`.
--
-- Idempotent: safe to run any number of times. Only indexes not already
-- present are added.

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'orders'
       AND index_name = 'idx_orders_from_id_code') = 0,
    'ALTER TABLE `orders` ADD INDEX `idx_orders_from_id_code` (`from_id`, `code`)',
    'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'users'
       AND index_name = 'idx_users_from_id') = 0,
    'ALTER TABLE `users` ADD INDEX `idx_users_from_id` (`from_id`)',
    'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'service_factors'
       AND index_name = 'idx_service_factors_from_id') = 0,
    'ALTER TABLE `service_factors` ADD INDEX `idx_service_factors_from_id` (`from_id`)',
    'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'factors'
       AND index_name = 'idx_factors_code') = 0,
    'ALTER TABLE `factors` ADD INDEX `idx_factors_code` (`code`)',
    'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
