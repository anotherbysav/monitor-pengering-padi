-- =====================================================================
--  MONITOR SUHU & KELEMBAPAN  ::  Skema Database (MariaDB / MySQL)
--  Dijalankan otomatis oleh backend/setup.php
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key`   VARCHAR(80)  NOT NULL,
  `setting_value` TEXT         NULL,
  `value_type`    ENUM('int','float','bool','string','json') NOT NULL DEFAULT 'string',
  `label`         VARCHAR(120) NOT NULL DEFAULT '',
  `unit`          VARCHAR(20)  NOT NULL DEFAULT '',
  `group_name`    VARCHAR(40)  NOT NULL DEFAULT 'umum',
  `min_value`     DOUBLE       NULL,
  `max_value`     DOUBLE       NULL,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`),
  KEY `idx_settings_group` (`group_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `material_profiles` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(40)  NOT NULL,
  `name`            VARCHAR(80)  NOT NULL,
  `description`     TEXT         NULL,
  `initial_moisture`   DECIMAL(5,2) NOT NULL DEFAULT 26.00 COMMENT 'kelembapan awal rata-rata (%)',
  `target_moisture`    DECIMAL(5,2) NOT NULL DEFAULT 14.00 COMMENT 'kelembapan target akhir (%)',
  `stop_moisture`      DECIMAL(5,2) NOT NULL DEFAULT 13.50 COMMENT 'batas bawah / berhenti (%)',
  `safe_temp_min`      DECIMAL(5,2) NOT NULL DEFAULT 32.00,
  `optimal_temp`       DECIMAL(5,2) NOT NULL DEFAULT 38.00,
  `safe_temp_max`      DECIMAL(5,2) NOT NULL DEFAULT 45.00,
  `limit_temp_max`     DECIMAL(5,2) NOT NULL DEFAULT 52.00 COMMENT 'batas keras, alat wajib mati',
  `fan_min_duty`       INT UNSIGNED NOT NULL DEFAULT 60,
  `base_rate`          DECIMAL(5,3) NOT NULL DEFAULT 0.85 COMMENT '%/jam pada kondisi ideal',
  `thickness_default`  DECIMAL(5,2) NOT NULL DEFAULT 1.50 COMMENT 'ketebalan lapisan (mm)',
  `is_default`         TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_profile_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(60)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `name`          VARCHAR(100) NOT NULL DEFAULT '',
  `role`          ENUM('admin','operator','viewer') NOT NULL DEFAULT 'operator',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login_at` DATETIME     NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `devices` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_code`   VARCHAR(60)  NOT NULL,
  `name`          VARCHAR(100) NOT NULL DEFAULT 'Pengering Padi',
  `location`      VARCHAR(120) NOT NULL DEFAULT '',
  `profile_code`  VARCHAR(40)  NOT NULL DEFAULT 'padi',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `last_seen_at`  DATETIME     NULL,
  `last_ip`       VARCHAR(45)  NULL,
  `firmware`      VARCHAR(40)  NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_device_code` (`device_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `api_keys` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_code`  VARCHAR(60)  NOT NULL,
  `label`        VARCHAR(100) NOT NULL,
  `key_hash`     CHAR(64)     NOT NULL COMMENT 'sha256 dari api key (plaintext hanya ditampilkan sekali)',
  `scopes`       VARCHAR(120) NOT NULL DEFAULT 'sensor:write,control:read',
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `last_used_at` DATETIME     NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_key_hash` (`key_hash`),
  KEY `idx_key_device` (`device_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `readings` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_code`   VARCHAR(60)  NOT NULL,
  `recorded_at`   DATETIME     NOT NULL,
  `temp_c`        DECIMAL(6,2) NOT NULL DEFAULT 0,
  `moisture_pct`  DECIMAL(6,2) NOT NULL DEFAULT 0,
  `ambient_temp_c`  DECIMAL(6,2) NULL,
  `ambient_rh_pct`  DECIMAL(6,2) NULL,
  `heater_on`     TINYINT(1)   NOT NULL DEFAULT 0,
  `heater_duty`   TINYINT(3)   NOT NULL DEFAULT 0,
  `fan_on`        TINYINT(1)   NOT NULL DEFAULT 0,
  `fan_duty`      TINYINT(3)   NOT NULL DEFAULT 0,
  `source`        VARCHAR(30)  NOT NULL DEFAULT 'device',
  `raw_payload`   TEXT         NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reading_device_time` (`device_code`, `recorded_at`),
  KEY `idx_reading_time` (`recorded_at`),
  KEY `idx_reading_device` (`device_code`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `actuator_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_code`   VARCHAR(60)  NOT NULL,
  `actuator`      ENUM('heater','fan') NOT NULL,
  `previous_state` TINYINT(1)  NOT NULL DEFAULT 0,
  `new_state`     TINYINT(1)   NOT NULL DEFAULT 0,
  `previous_duty` TINYINT(3)   NOT NULL DEFAULT 0,
  `new_duty`      TINYINT(3)   NOT NULL DEFAULT 0,
  `source`        VARCHAR(30)  NOT NULL DEFAULT 'web',
  `reason`        VARCHAR(190) NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_actuator_time` (`device_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alerts` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_code`   VARCHAR(60)  NOT NULL,
  `alert_key`     VARCHAR(60)  NOT NULL COMMENT 'kode unik jenis alert',
  `severity`      ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  `message`       VARCHAR(255) NOT NULL,
  `value`         DECIMAL(8,2) NULL,
  `threshold`     DECIMAL(8,2) NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `acknowledged`  TINYINT(1)   NOT NULL DEFAULT 0,
  `triggered_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_alert_active` (`device_code`, `is_active`),
  KEY `idx_alert_key` (`device_code`, `alert_key`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `drying_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_code`   VARCHAR(60)  NOT NULL,
  `status`        VARCHAR(30)  NOT NULL,
  `score`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `dry_rate`      DECIMAL(6,3) NOT NULL DEFAULT 0 COMMENT '%/jam',
  `est_hours`     DECIMAL(7,2) NULL,
  `eta_at`        DATETIME     NULL,
  `progress_pct`  DECIMAL(5,2) NOT NULL DEFAULT 0,
  `temp_c`        DECIMAL(6,2) NOT NULL DEFAULT 0,
  `moisture_pct`  DECIMAL(6,2) NOT NULL DEFAULT 0,
  `snapshot`      JSON         NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_drying_device_time` (`device_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
