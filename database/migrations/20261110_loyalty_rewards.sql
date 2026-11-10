ALTER TABLE `users`
  ADD COLUMN `email_verified` tinyint NOT NULL DEFAULT 1 AFTER `status`,
  ADD COLUMN `points_balance` int unsigned NOT NULL DEFAULT 0 AFTER `wallet_balance`;

ALTER TABLE `orders`
  ADD COLUMN `subtotal_amount` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `total_price`,
  ADD COLUMN `item_count` int unsigned NOT NULL DEFAULT 0 AFTER `subtotal_amount`;

ALTER TABLE `discounts`
  ADD COLUMN `name` varchar(100) NOT NULL DEFAULT '' AFTER `id`,
  ADD COLUMN `reward_type` enum('discount','free_shipping','free_product') NOT NULL DEFAULT 'discount' AFTER `type`,
  ADD COLUMN `minimum_spend` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `reward_type`,
  ADD COLUMN `free_product_id` int DEFAULT NULL AFTER `minimum_spend`;

CREATE TABLE `voucher_campaigns` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `trigger_type` enum('signup','order') NOT NULL,
  `condition_type` enum('none','spend','products') NOT NULL DEFAULT 'none',
  `threshold_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `reward_type` enum('fixed','percentage','free_shipping','free_product') NOT NULL,
  `reward_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `minimum_spend` decimal(10,2) NOT NULL DEFAULT 0.00,
  `free_product_id` int DEFAULT NULL,
  `validity_days` int unsigned NOT NULL DEFAULT 14,
  `active` tinyint NOT NULL DEFAULT 1,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_voucher_campaign_trigger` (`trigger_type`,`active`,`condition_type`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `voucher_claims` (
  `id` int NOT NULL AUTO_INCREMENT,
  `campaign_id` int NOT NULL,
  `user_id` int NOT NULL,
  `discount_id` int NOT NULL,
  `source_order_id` int DEFAULT NULL,
  `status` enum('assigned','redeemed','revoked') NOT NULL DEFAULT 'assigned',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_voucher_claim_campaign_user` (`campaign_id`,`user_id`),
  KEY `idx_voucher_claim_order` (`source_order_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `points_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `points_delta` int NOT NULL,
  `reason` varchar(40) NOT NULL,
  `reference_key` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_points_transaction_reference` (`reference_key`),
  KEY `idx_points_transaction_user` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `loyalty_settings` (
  `id` tinyint NOT NULL DEFAULT 1,
  `points_per_wallet_peso` int unsigned NOT NULL DEFAULT 5,
  `points_voucher_cost` int unsigned NOT NULL DEFAULT 100,
  `points_voucher_percent` int unsigned NOT NULL DEFAULT 20,
  `points_voucher_minimum_spend` decimal(10,2) NOT NULL DEFAULT 0.00,
  `points_voucher_validity_days` int unsigned NOT NULL DEFAULT 14,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `loyalty_reward_cycles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `starts_on` date NOT NULL,
  `published` tinyint NOT NULL DEFAULT 0,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_loyalty_cycle_dates` (`published`,`starts_on`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `loyalty_reward_days` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cycle_id` int NOT NULL,
  `day_number` tinyint unsigned NOT NULL,
  `title` varchar(100) NOT NULL,
  `reward_type` enum('points','voucher','free_shipping','mystery','free_product') NOT NULL,
  `points_amount` int unsigned NOT NULL DEFAULT 0,
  `voucher_type` enum('fixed','percentage') NOT NULL DEFAULT 'fixed',
  `voucher_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `minimum_spend` decimal(10,2) NOT NULL DEFAULT 0.00,
  `free_product_id` int DEFAULT NULL,
  `validity_days` int unsigned NOT NULL DEFAULT 14,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_loyalty_reward_cycle_day` (`cycle_id`,`day_number`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `loyalty_reward_prizes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cycle_id` int NOT NULL,
  `title` varchar(100) NOT NULL,
  `reward_type` enum('points','voucher','free_shipping','free_product') NOT NULL,
  `voucher_type` enum('fixed','percentage') NOT NULL DEFAULT 'fixed',
  `reward_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `minimum_spend` decimal(10,2) NOT NULL DEFAULT 0.00,
  `free_product_id` int DEFAULT NULL,
  `validity_days` int unsigned NOT NULL DEFAULT 14,
  `weight` int unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_loyalty_prize_cycle` (`cycle_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `daily_reward_claims` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `cycle_id` int NOT NULL,
  `day_number` tinyint unsigned NOT NULL,
  `claim_date` date NOT NULL,
  `game_type` enum('spin','scratch') DEFAULT NULL,
  `reward_type` varchar(20) NOT NULL,
  `points_awarded` int unsigned NOT NULL DEFAULT 0,
  `discount_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_daily_reward_user_date` (`user_id`,`claim_date`),
  UNIQUE KEY `uq_daily_reward_cycle_day` (`user_id`,`cycle_id`,`day_number`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `signup_email_verifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  `used` tinyint NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_signup_verification_user` (`user_id`,`used`,`expires_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `loyalty_settings` (`id`) VALUES (1);

INSERT INTO `voucher_campaigns`
  (`name`, `trigger_type`, `condition_type`, `threshold_value`, `reward_type`, `reward_amount`, `minimum_spend`, `validity_days`, `active`)
VALUES
  ('Welcome voucher', 'signup', 'none', 0, 'fixed', 100, 500, 14, 1);

INSERT INTO `loyalty_reward_cycles` (`name`, `starts_on`, `published`)
VALUES ('Welcome rewards - 7 day cycle', CURDATE(), 1);
SET @initial_loyalty_cycle_id = LAST_INSERT_ID();

INSERT INTO `loyalty_reward_days`
  (`cycle_id`, `day_number`, `title`, `reward_type`, `points_amount`, `voucher_type`, `voucher_amount`, `minimum_spend`, `validity_days`)
VALUES
  (@initial_loyalty_cycle_id, 1, 'A little welcome', 'points', 10, 'fixed', 0, 0, 14),
  (@initial_loyalty_cycle_id, 2, 'Keep the streak', 'points', 15, 'fixed', 0, 0, 14),
  (@initial_loyalty_cycle_id, 3, 'A treat for you', 'voucher', 0, 'fixed', 20, 300, 14),
  (@initial_loyalty_cycle_id, 4, 'More points', 'points', 25, 'fixed', 0, 0, 14),
  (@initial_loyalty_cycle_id, 5, 'Delivery is on us', 'free_shipping', 0, 'fixed', 0, 0, 14),
  (@initial_loyalty_cycle_id, 6, 'Mystery day', 'mystery', 0, 'fixed', 0, 0, 14),
  (@initial_loyalty_cycle_id, 7, 'The grand finale', 'voucher', 0, 'fixed', 100, 800, 14);

INSERT INTO `loyalty_reward_prizes`
  (`cycle_id`, `title`, `reward_type`, `voucher_type`, `reward_amount`, `minimum_spend`, `validity_days`, `weight`)
VALUES
  (@initial_loyalty_cycle_id, 'Bonus points', 'points', 'fixed', 30, 0, 14, 6),
  (@initial_loyalty_cycle_id, 'Mystery voucher', 'voucher', 'fixed', 50, 300, 14, 3),
  (@initial_loyalty_cycle_id, 'Free product jackpot', 'free_product', 'fixed', 0, 0, 14, 0);
