-- 6) task_queue: idx_status_locked_at
-- Stale-task recovery filters by status AND locked_at range:
--   UPDATE `task_queue` SET ... WHERE `status` = 'running' AND `locked_at` < NOW() - INTERVAL 5 MINUTE
-- Existing idx_status_id (status, id) cannot be used for locked_at range.
-- This index enables index range scan on locked_at for status='running'.
SET @ddl := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE() AND table_name = 'task_queue'
       AND index_name = 'idx_status_locked_at') = 0,
    'ALTER TABLE `task_queue` ADD INDEX `idx_status_locked_at` (`status`, `locked_at`)',
    'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;