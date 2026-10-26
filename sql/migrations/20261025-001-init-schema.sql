-- Init schema migration (runs exactly once, tracked in schema_migrations)
--
-- This is the complete initial database schema, extracted from sql/sql.php.
-- Every statement here is pure SQL executed once per database by the
-- migration runner (recorded in schema_migrations). The runner guarantees
-- single execution, so plain INSERTs are safe for seed data.
--
-- Ordering matters:
--   * orders_archive is created AFTER orders (LIKE copies the table def at
--     this moment, before hot-path indexes exist, so the archive stays PK-only).
--   * schema_migrations table is created last as the tracker.

-- 1) Base tables
CREATE TABLE IF NOT EXISTS `users` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `from_id` bigint(13) NOT NULL,
    `step` varchar(50) COLLATE utf8mb4_bin DEFAULT 'none',
    `coin` int DEFAULT 0,
    `count_service` int DEFAULT 0,
    `count_charge` int DEFAULT 0,
    `phone` varchar(20) COLLATE utf8mb4_bin DEFAULT NULL,
    `test_account` varchar(10) COLLATE utf8mb4_bin DEFAULT 'no',
    `count_warn` varchar(10) COLLATE utf8mb4_bin DEFAULT '0',
    `view_status` varchar(20) COLLATE utf8mb4_bin DEFAULT 'active',
    `timestamp` varchar(50) COLLATE utf8mb4_bin DEFAULT '0',
    `status` varchar(15) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `panels` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `name` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `login_link` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `username` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `password` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `count_create` varchar(50) COLLATE utf8mb4_bin DEFAULT '0',
    `qr_code` varchar(30) COLLATE utf8mb4_bin DEFAULT 'active',
    `protocols` varchar(50) COLLATE utf8mb4_bin DEFAULT 'vless|',
    `flow` varchar(15) COLLATE utf8mb4_bin DEFAULT 'flowon',
    `code` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `token` varchar(500) COLLATE utf8mb4_bin NOT NULL,
    `type` varchar(30) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(20) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `hiddify_panels` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `name` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `login_link` TEXT COLLATE utf8mb4_bin NOT NULL,
    `token` TEXT COLLATE utf8mb4_bin NOT NULL,
    `count_create` varchar(50) COLLATE utf8mb4_bin DEFAULT '0',
    `qr_code` varchar(30) COLLATE utf8mb4_bin DEFAULT 'active',
    `code` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `type` varchar(30) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(20) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `sanayi_panel_setting` (
    `code` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
    `inbound_id` varchar(10) COLLATE utf8mb4_bin DEFAULT NULL,
    `example_link` TEXT COLLATE utf8mb4_bin DEFAULT NULL,
    `flow` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `marzban_inbounds` (
    `panel` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
    `inbound` TEXT COLLATE utf8mb4_bin DEFAULT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin DEFAULT NULL,
    `status` varchar(20) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `orders` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `from_id` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `location` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `protocol` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
    `date` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `volume` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `link` TEXT COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL,
    `type` varchar(20) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- orders_archive is created here, AFTER orders but BEFORE hot-path indexes.
-- LIKE copies the table definition at this moment (PK-only), so the archive
-- does not carry indexes it never queries (it is only read by `row`).
CREATE TABLE IF NOT EXISTS `orders_archive` LIKE `orders`;

CREATE TABLE IF NOT EXISTS `factors` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `from_id` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `sends` (
    `send` varchar(50) PRIMARY KEY,
    `step` varchar(50) DEFAULT NULL,
    `user` INT(11) DEFAULT NULL,
    `type` varchar(50) DEFAULT NULL,
    `text` varchar(7000) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `category` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `limit` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `date` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `name` varchar(100) COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `category_limit` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `limit` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `name` varchar(100) COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `category_date` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `date` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `name` varchar(100) COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `service_factors` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `from_id` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `location` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `protocol` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `plan` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(200) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `lock` (
    `id` int(11) AUTO_INCREMENT PRIMARY KEY,
    `chat_id` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
    `name` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `payment_setting` (
    `zarinpal_token` varchar(200) COLLATE utf8mb4_bin DEFAULT 'none',
    `idpay_token` varchar(200) COLLATE utf8mb4_bin DEFAULT 'none',
    `nowpayment_token` varchar(200) COLLATE utf8mb4_bin DEFAULT 'none',
    `card_number` varchar(20) COLLATE utf8mb4_bin DEFAULT 'none',
    `card_number_name` varchar(100) COLLATE utf8mb4_bin DEFAULT 'none',
    `zarinpal_status` varchar(15) COLLATE utf8mb4_bin DEFAULT 'inactive',
    `idpay_status` varchar(15) COLLATE utf8mb4_bin DEFAULT 'inactive',
    `nowpayment_status` varchar(15) COLLATE utf8mb4_bin DEFAULT 'inactive',
    `card_status` varchar(15) COLLATE utf8mb4_bin DEFAULT 'inactive'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `test_account_setting` (
    `panel` varchar(20) COLLATE utf8mb4_bin DEFAULT 'none',
    `volume` varchar(20) COLLATE utf8mb4_bin DEFAULT '0',
    `time` varchar(20) COLLATE utf8mb4_bin DEFAULT '0',
    `status` varchar(50) COLLATE utf8mb4_bin DEFAULT 'inactive'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `test_account` (
    `row` int(200) AUTO_INCREMENT PRIMARY KEY,
    `from_id` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `location` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `date` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `volume` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `link` TEXT COLLATE utf8mb4_bin NOT NULL,
    `price` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `admins` (
    `chat_id` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `settings` (
    `log_channel` varchar(50) COLLATE utf8mb4_bin DEFAULT NULL,
    `count_warn_ban` varchar(50) COLLATE utf8mb4_bin DEFAULT '3',
    `show_account_status_changer_btn` varchar(15) COLLATE utf8mb4_bin DEFAULT 'active',
    `show_charge_account_btn` varchar(15) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `spam_setting` (
    `type` varchar(20) COLLATE utf8mb4_bin DEFAULT 'ban',
    `time` varchar(20) COLLATE utf8mb4_bin DEFAULT '3',
    `count_message` varchar(20) COLLATE utf8mb4_bin DEFAULT '10',
    `status` varchar(50) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `task_queue` (
    `id` int AUTO_INCREMENT PRIMARY KEY,
    `type` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `payload` TEXT COLLATE utf8mb4_bin NOT NULL,
    `status` ENUM('pending','running','done','failed') COLLATE utf8mb4_bin DEFAULT 'pending',
    `created_at` datetime NOT NULL,
    `started_at` datetime DEFAULT NULL,
    `locked_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `task_results` (
    `task_id` int PRIMARY KEY,
    `result` TEXT COLLATE utf8mb4_bin NOT NULL,
    `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `copens` (
    `copen` varchar(100) COLLATE utf8mb4_bin NOT NULL,
    `percent` varchar(50) COLLATE utf8mb4_bin NOT NULL,
    `count_use` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(50) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `notes` (
    `note` TEXT COLLATE utf8mb4_bin NOT NULL,
    `code` varchar(30) COLLATE utf8mb4_bin NOT NULL,
    `type` varchar(20) COLLATE utf8mb4_bin NOT NULL,
    `status` varchar(20) COLLATE utf8mb4_bin DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `auth_setting` (
    `iran_number` varchar(15) COLLATE utf8mb4_bin DEFAULT NULL,
    `virtual_number` varchar(15) COLLATE utf8mb4_bin DEFAULT NULL,
    `both_number` varchar(15) COLLATE utf8mb4_bin DEFAULT NULL,
    `status` varchar(15) COLLATE utf8mb4_bin DEFAULT 'inactive'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- 2) Seed data (init migration runs once, so plain INSERTs are safe)
INSERT INTO `auth_setting` (`iran_number`, `virtual_number`, `both_number`)
SELECT 'inactive', 'inactive', 'inactive'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `auth_setting`);

INSERT INTO `settings` (`count_warn_ban`, `show_account_status_changer_btn`, `show_charge_account_btn`)
SELECT '3', 'active', 'active'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `settings`);

INSERT INTO `spam_setting` (`type`)
SELECT 'ban'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `spam_setting`);

INSERT INTO `test_account_setting` (`panel`)
SELECT 'none'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `test_account_setting`);

INSERT INTO `sends` (`send`)
SELECT 'no'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `sends` WHERE `send` = 'no');

INSERT INTO `payment_setting` (`zarinpal_token`, `idpay_token`, `nowpayment_token`)
SELECT 'none', 'none', 'none'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `payment_setting`);

-- 3) Migration tracker table (bootstrapped here so the runner has a place to
--    record applied migrations; the runner also creates it defensively).
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `filename` varchar(255) COLLATE utf8mb4_bin PRIMARY KEY,
    `applied_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
