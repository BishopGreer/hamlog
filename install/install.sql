-- HamLog Installation Schema
-- PHP 8.4 / MySQL 8.0+

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Users
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `callsign` VARCHAR(20) DEFAULT NULL,
  `name` VARCHAR(100) DEFAULT NULL,
  `dxcc_entity` INT UNSIGNED DEFAULT 291,
  `grid_locator` VARCHAR(10) DEFAULT NULL,
  `is_admin` TINYINT UNSIGNED DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stations (callsigns — personal or club)
CREATE TABLE IF NOT EXISTS `stations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `owner_id` INT UNSIGNED NOT NULL,
  `callsign` VARCHAR(20) NOT NULL,
  `name` VARCHAR(100) DEFAULT NULL,
  `description` TEXT DEFAULT NULL,
  `dxcc_entity` INT UNSIGNED DEFAULT 291,
  `grid_locator` VARCHAR(10) DEFAULT NULL,
  `latitude` DECIMAL(10,6) DEFAULT NULL,
  `longitude` DECIMAL(10,6) DEFAULT NULL,
  `is_club_station` TINYINT UNSIGNED DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`owner_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Club station memberships
CREATE TABLE IF NOT EXISTS `club_members` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `station_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `role` ENUM('operator','admin') DEFAULT 'operator',
  `added_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_member` (`station_id`, `user_id`),
  FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Logbooks
CREATE TABLE IF NOT EXISTS `logbooks` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `station_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `is_default` TINYINT UNSIGNED DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- QSOs
CREATE TABLE IF NOT EXISTS `qsos` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `logbook_id` INT UNSIGNED NOT NULL,
  `station_id` INT UNSIGNED NOT NULL,
  `call` VARCHAR(20) NOT NULL,
  `date_on` DATE NOT NULL,
  `time_on` TIME NOT NULL,
  `date_off` DATE DEFAULT NULL,
  `time_off` TIME DEFAULT NULL,
  `band` VARCHAR(20) DEFAULT NULL,
  `freq` DECIMAL(15,6) DEFAULT NULL,
  `mode` VARCHAR(20) NOT NULL,
  `submode` VARCHAR(20) DEFAULT NULL,
  `rst_sent` VARCHAR(10) DEFAULT '59',
  `rst_rcvd` VARCHAR(10) DEFAULT '59',
  `name` VARCHAR(100) DEFAULT NULL,
  `qth` VARCHAR(100) DEFAULT NULL,
  `gridsquare` VARCHAR(10) DEFAULT NULL,
  `dxcc` INT UNSIGNED DEFAULT NULL,
  `country` VARCHAR(100) DEFAULT NULL,
  `cont` VARCHAR(2) DEFAULT NULL,
  `ituz` INT UNSIGNED DEFAULT NULL,
  `cqz` INT UNSIGNED DEFAULT NULL,
  `iota` VARCHAR(10) DEFAULT NULL,
  `tx_pwr` DECIMAL(10,2) DEFAULT NULL,
  `comment` TEXT DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `lotw_qsl_sent` ENUM('N','Y','Q','R','I') DEFAULT 'N',
  `lotw_qsl_rcvd` ENUM('N','Y','R','I') DEFAULT 'N',
  `eqsl_qsl_sent` ENUM('N','Y','Q','R','I') DEFAULT 'N',
  `eqsl_qsl_rcvd` ENUM('N','Y','R','I') DEFAULT 'N',
  `qsl_sent` ENUM('N','Y','Q','R','I') DEFAULT 'N',
  `qsl_rcvd` ENUM('N','Y','R','I') DEFAULT 'N',
  `clublog_upload_status` ENUM('N','Y','M','D','E') DEFAULT 'N',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_call` (`call`),
  INDEX `idx_date` (`date_on`),
  INDEX `idx_band` (`band`),
  INDEX `idx_mode` (`mode`),
  INDEX `idx_dxcc` (`dxcc`),
  INDEX `idx_logbook` (`logbook_id`),
  FOREIGN KEY (`logbook_id`) REFERENCES `logbooks`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DXCC entities reference table
CREATE TABLE IF NOT EXISTS `dxcc_entities` (
  `adif` INT UNSIGNED PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `prefix` VARCHAR(20) DEFAULT NULL,
  `continent` VARCHAR(2) DEFAULT NULL,
  `cqz` INT UNSIGNED DEFAULT NULL,
  `ituz` INT UNSIGNED DEFAULT NULL,
  `latitude` DECIMAL(10,6) DEFAULT NULL,
  `longitude` DECIMAL(10,6) DEFAULT NULL,
  `tz` DECIMAL(5,2) DEFAULT NULL,
  `deleted` TINYINT UNSIGNED DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Upload / sync records
CREATE TABLE IF NOT EXISTS `uploads` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `station_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `type` ENUM('lotw','eqsl','clublog','adif_import','adif_export') NOT NULL,
  `filename` VARCHAR(255) DEFAULT NULL,
  `qso_count` INT UNSIGNED DEFAULT 0,
  `status` ENUM('pending','processing','success','failed') DEFAULT 'pending',
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Settings (key/value store)
CREATE TABLE IF NOT EXISTS `settings` (
  `key` VARCHAR(100) PRIMARY KEY,
  `value` TEXT DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default settings
INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
  ('site_name', 'HamLog'),
  ('allow_registration', '1'),
  ('clublog_app_key', ''),
  ('eqsl_app_key', ''),
  ('qrz_api_key', ''),
  ('hamqth_api_key', ''),
  ('installed', '1');

-- Seed common DXCC entities (USA, Canada, England, etc.)
INSERT IGNORE INTO `dxcc_entities` (`adif`,`name`,`prefix`,`continent`,`cqz`,`ituz`,`latitude`,`longitude`,`tz`) VALUES
(291,'United States','K','NA',5,8,37.7,-96.5,-5.0),
(1,'Canada','VE','NA',2,4,56.0,-96.0,-5.0),
(223,'England','G','EU',14,27,51.5,-0.12,0.0),
(209,'Germany','DL','EU',14,28,51.0,9.0,1.0),
(250,'Japan','JA','AS',25,45,36.0,138.0,9.0),
(318,'Australia','VK','OC',29,55,-26.0,131.0,10.0),
(100,'Brazil','PY','SA',11,15,-10.0,-53.0,-3.0),
(132,'France','F','EU',14,27,46.0,2.0,1.0),
(248,'Italy','I','EU',15,28,42.0,13.0,1.0),
(327,'Russia','UA','EU',16,29,55.0,37.0,3.0),
(287,'Uruguay','CX','SA',13,14,-33.0,-56.0,-3.0),
(108,'Chile','CE','SA',12,14,-30.0,-71.0,-4.0),
(112,'Colombia','HK','SA',9,12,4.0,-72.0,-5.0),
(170,'Mexico','XE','NA',7,10,19.0,-99.0,-6.0),
(206,'Netherlands','PA','EU',14,27,52.0,5.0,1.0),
(222,'Spain','EA','EU',14,37,40.0,-4.0,1.0),
(246,'Sweden','SM','EU',14,18,59.0,15.0,1.0),
(110,'China','BY','AS',24,44,35.0,103.0,8.0),
(324,'South Korea','HL','AS',25,44,36.0,127.0,9.0),
(88,'Argentina','LU','SA',13,14,-34.0,-64.0,-3.0);
