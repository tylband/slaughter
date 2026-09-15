-- Sports scoreboard database schema
-- Import locally with phpMyAdmin or: mysql -u root -p < cscmonth_scoreboard.sql

CREATE DATABASE IF NOT EXISTS `cscmonth`
  DEFAULT CHARACTER SET utf8
  DEFAULT COLLATE utf8_general_ci;

USE `cscmonth`;

CREATE TABLE IF NOT EXISTS `tbl_dashboard_settings` (
  `setting_key` VARCHAR(50) NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `tbl_dashboard_teams` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `team_name` VARCHAR(50) NOT NULL,
  `overall_score` INT NOT NULL DEFAULT 0,
  `team_color` CHAR(7) NOT NULL DEFAULT '#4f7cff',
  `display_order` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `tbl_dashboard_sports` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sport_name` VARCHAR(50) NOT NULL,
  `display_order` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `tbl_dashboard_sport_scores` (
  `team_id` INT UNSIGNED NOT NULL,
  `sport_id` INT UNSIGNED NOT NULL,
  `score` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`team_id`, `sport_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `tbl_dashboard_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(80) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(120) NOT NULL DEFAULT '',
  `role` VARCHAR(30) NOT NULL DEFAULT 'manager',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_dashboard_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `tbl_dashboard_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `uq_dashboard_token` (`token_hash`),
  KEY `idx_dashboard_token_user` (`user_id`), KEY `idx_dashboard_token_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Create a password hash using PHP, then insert the user:
-- php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
-- INSERT INTO tbl_dashboard_users (username, password_hash, full_name) VALUES ('manager', 'GENERATED_HASH', 'Score Manager');

INSERT INTO `tbl_dashboard_settings` (`setting_key`, `setting_value`) VALUES
  ('title', 'League Scoreboard'),
  ('subtitle', 'Overall Team Standings')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

INSERT INTO `tbl_dashboard_teams` (`team_name`, `overall_score`, `team_color`, `display_order`)
SELECT 'Thunder Hawks', 82, '#ff5c35', 1
WHERE NOT EXISTS (SELECT 1 FROM `tbl_dashboard_teams`);

INSERT INTO `tbl_dashboard_teams` (`team_name`, `overall_score`, `team_color`, `display_order`)
SELECT 'Blue Comets', 76, '#4f7cff', 2
WHERE NOT EXISTS (SELECT 1 FROM `tbl_dashboard_teams` WHERE `team_name` = 'Blue Comets');

INSERT INTO `tbl_dashboard_teams` (`team_name`, `overall_score`, `team_color`, `display_order`)
SELECT 'Golden Wolves', 68, '#ffc247', 3
WHERE NOT EXISTS (SELECT 1 FROM `tbl_dashboard_teams` WHERE `team_name` = 'Golden Wolves');

INSERT INTO `tbl_dashboard_teams` (`team_name`, `overall_score`, `team_color`, `display_order`)
SELECT 'Emerald Kings', 61, '#20c997', 4
WHERE NOT EXISTS (SELECT 1 FROM `tbl_dashboard_teams` WHERE `team_name` = 'Emerald Kings');
