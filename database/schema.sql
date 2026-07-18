-- Full MySQL schema for Al Najmo Thagheb API
-- Prefer SQLite locally (auto-migrate via Database.php).
-- If you use MySQL: import this file OR just boot the API once (migrate() also runs on MySQL).

CREATE DATABASE IF NOT EXISTS `alnajmo_thagheb`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `alnajmo_thagheb`;

CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(32) NOT NULL,
  `first_name` VARCHAR(120) NOT NULL DEFAULT '',
  `last_name` VARCHAR(120) NOT NULL DEFAULT '',
  `age` INT NOT NULL DEFAULT 0,
  `school_grade` VARCHAR(120) NOT NULL DEFAULT '',
  `province` VARCHAR(120) NOT NULL DEFAULT '',
  `city` VARCHAR(120) NOT NULL DEFAULT '',
  `quran_reading_level` VARCHAR(160) NOT NULL DEFAULT '',
  `has_previous_class_experience` TINYINT(1) NOT NULL DEFAULT 0,
  `previous_class_details` LONGTEXT NULL,
  `profile_complete` TINYINT(1) NOT NULL DEFAULT 0,
  `phone_verified_at` DATETIME NULL,
  `avatar_url` VARCHAR(512) NULL,
  `settings_json` LONGTEXT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `otp_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(32) NOT NULL,
  `code_hash` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `attempts` INT NOT NULL DEFAULT 0,
  `consumed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_otp_phone_created` (`phone`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `auth_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `last_used_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_auth_token_hash` (`token_hash`),
  KEY `idx_auth_tokens_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `registration_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `phone` VARCHAR(32) NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_reg_token_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_progress` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `revision` INT NOT NULL DEFAULT 0,
  `coins` INT NOT NULL DEFAULT 100,
  `hearts` INT NOT NULL DEFAULT 5,
  `compete_points` INT NOT NULL DEFAULT 0,
  `is_premium` TINYINT(1) NOT NULL DEFAULT 0,
  `premium_expires_at` DATETIME NULL,
  `last_heart_regen_at` BIGINT NULL,
  `current_lesson` INT NOT NULL DEFAULT 1,
  `pending_surah_after_lesson` INT NULL,
  `takeoff_platform_completed` TINYINT(1) NOT NULL DEFAULT 0,
  `matches_played` INT NOT NULL DEFAULT 0,
  `daily_challenge_locked_until` BIGINT NULL,
  `daily_challenge_won_day_key` VARCHAR(32) NULL,
  `daily_challenge_played_day_key` VARCHAR(32) NULL,
  `daily_challenge_played_question_ids` LONGTEXT NOT NULL,
  `word_play_level_index` INT NOT NULL DEFAULT 0,
  `word_play_content_version` INT NOT NULL DEFAULT 9,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `progress_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `action` VARCHAR(64) NOT NULL,
  `payload_json` LONGTEXT NOT NULL,
  `coins_delta` INT NOT NULL DEFAULT 0,
  `hearts_delta` INT NOT NULL DEFAULT 0,
  `points_delta` INT NOT NULL DEFAULT 0,
  `revision_after` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_progress_events_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `daily_challenge_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `day_key` VARCHAR(32) NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `attempt_token` VARCHAR(64) NOT NULL,
  `question_id` VARCHAR(64) NULL,
  `method_id` VARCHAR(64) NULL,
  `lesson_id` INT NULL,
  `correct_answer` LONGTEXT NULL,
  `correct_option_ids` LONGTEXT NULL,
  `is_voice` TINYINT(1) NOT NULL DEFAULT 0,
  `time_limit_seconds` INT NULL,
  `max_lesson_id` INT NOT NULL DEFAULT 1,
  `pick_seed` INT NOT NULL DEFAULT 0,
  `started_at_ms` BIGINT NOT NULL,
  `committed_at_ms` BIGINT NULL,
  `submitted_at_ms` BIGINT NULL,
  `answer_raw` LONGTEXT NULL,
  `is_correct` TINYINT(1) NULL,
  `coins_awarded` INT NOT NULL DEFAULT 0,
  `points_awarded` INT NOT NULL DEFAULT 0,
  `fail_reason` VARCHAR(64) NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_daily_attempt_token` (`attempt_token`),
  KEY `idx_daily_challenge_user_day` (`user_id`, `day_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `word_play_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `attempt_token` VARCHAR(64) NOT NULL,
  `stage_index` INT NOT NULL DEFAULT 0,
  `content_version` INT NOT NULL DEFAULT 9,
  `started_at_ms` BIGINT NOT NULL,
  `completed_at_ms` BIGINT NULL,
  `hints_used` INT NOT NULL DEFAULT 0,
  `target_count` INT NULL,
  `bonus_count` INT NULL,
  `coins_awarded` INT NOT NULL DEFAULT 0,
  `points_awarded` INT NOT NULL DEFAULT 0,
  `chapter_gift` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_word_play_attempt_token` (`attempt_token`),
  KEY `idx_word_play_user_stage` (`user_id`, `stage_index`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `compete_matches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `match_token` VARCHAR(64) NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `seed` INT NOT NULL,
  `max_lesson_id` INT NOT NULL DEFAULT 1,
  `round_count` INT NOT NULL DEFAULT 5,
  `round_seconds` INT NOT NULL DEFAULT 15,
  `entry_fee` INT NOT NULL DEFAULT 0,
  `opponent_json` LONGTEXT NOT NULL,
  `rounds_json` LONGTEXT NULL,
  `results_json` LONGTEXT NULL,
  `player_score` INT NOT NULL DEFAULT 0,
  `opponent_score` INT NOT NULL DEFAULT 0,
  `outcome` VARCHAR(32) NULL,
  `coins_spent` INT NOT NULL DEFAULT 0,
  `coins_awarded` INT NOT NULL DEFAULT 0,
  `points_awarded` INT NOT NULL DEFAULT 0,
  `started_at_ms` BIGINT NOT NULL,
  `committed_at_ms` BIGINT NULL,
  `finished_at_ms` BIGINT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_compete_match_token` (`match_token`),
  KEY `idx_compete_matches_user` (`user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shop_orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `order_token` VARCHAR(64) NOT NULL,
  `client_request_id` VARCHAR(128) NULL,
  `product_type` VARCHAR(32) NOT NULL,
  `product_id` VARCHAR(64) NOT NULL,
  `product_title` VARCHAR(255) NOT NULL,
  `amount_irr` INT NOT NULL,
  `currency` VARCHAR(8) NOT NULL DEFAULT 'IRR',
  `coins_grant` INT NOT NULL DEFAULT 0,
  `vip_days` INT NOT NULL DEFAULT 0,
  `status` VARCHAR(32) NOT NULL,
  `provider` VARCHAR(32) NOT NULL,
  `provider_authority` VARCHAR(128) NULL,
  `provider_ref_id` VARCHAR(128) NULL,
  `payment_url` LONGTEXT NULL,
  `meta_json` LONGTEXT NULL,
  `paid_at` DATETIME NULL,
  `fulfilled_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_shop_order_token` (`order_token`),
  UNIQUE KEY `idx_shop_orders_client_req` (`user_id`, `client_request_id`),
  KEY `idx_shop_orders_user` (`user_id`, `id`),
  KEY `idx_shop_orders_authority` (`provider_authority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_tickets` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `public_id` VARCHAR(64) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `category` VARCHAR(64) NOT NULL,
  `status` VARCHAR(32) NOT NULL,
  `priority` VARCHAR(32) NOT NULL DEFAULT 'normal',
  `user_seen_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_support_ticket_public` (`public_id`),
  KEY `idx_support_tickets_user` (`user_id`, `id`),
  KEY `idx_support_tickets_status` (`status`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `support_ticket_messages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id` BIGINT UNSIGNED NOT NULL,
  `public_id` VARCHAR(64) NOT NULL,
  `author` VARCHAR(32) NOT NULL,
  `body` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_support_msg_public` (`public_id`),
  KEY `idx_support_messages_ticket` (`ticket_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `public_id` VARCHAR(64) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `body` LONGTEXT NOT NULL,
  `kind` VARCHAR(64) NOT NULL,
  `source` VARCHAR(64) NOT NULL DEFAULT 'system',
  `deep_link` VARCHAR(255) NULL,
  `meta_json` LONGTEXT NULL,
  `read_at` DATETIME NULL,
  `dismissed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_notifications_public` (`user_id`, `public_id`),
  KEY `idx_user_notifications_user` (`user_id`, `id`),
  KEY `idx_user_notifications_unread` (`user_id`, `read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_push_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `token` VARCHAR(512) NOT NULL,
  `platform` VARCHAR(32) NULL,
  `device_id` VARCHAR(128) NULL,
  `app_version` VARCHAR(32) NULL,
  `last_seen_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_push_tokens_token` (`token`),
  KEY `idx_user_push_tokens_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
