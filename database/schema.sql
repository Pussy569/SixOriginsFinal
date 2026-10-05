-- MySQL 8 schema for Six Origins; structure only, no application data.
SET NAMES utf8mb4;
CREATE TABLE `admin_activity` (`id` int NOT NULL,
  `admin_id` int NOT NULL,
  `action` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `timestamp` datetime DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (`id` int NOT NULL,
  `action_performed` varchar(100) DEFAULT NULL,
  `target_user_id` int DEFAULT NULL,
  `performed_by_admin_id` int DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cart` (`id` int NOT NULL,
  `user_id` varchar(255) NOT NULL,
  `product_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` int NOT NULL,
  `quantity` int NOT NULL,
  `image` varchar(100) NOT NULL,
  `size` varchar(10) NOT NULL,
  `preferences` text DEFAULT NULL,
  `extras` text DEFAULT NULL,
  `selections_hash` varchar(64) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `guest_email` varchar(100) DEFAULT NULL,
  `guest_session_id` varchar(255) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chatbot_knowledge` (`id` int NOT NULL,
  `keywords` varchar(500) NOT NULL,
  `response` longtext NOT NULL,
  `response_type` enum('text','suggestion') DEFAULT 'text',
  `priority` int DEFAULT 1,
  `is_active` tinyint DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `chat_history` (`id` int NOT NULL,
  `user_id` int NOT NULL,
  `message` text NOT NULL,
  `sender` enum('user','bot') NOT NULL,
  `type` varchar(50) DEFAULT 'text',
  `payload` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `discounts` (`id` int NOT NULL,
  `code` varchar(50) NOT NULL,
  `amount` int NOT NULL,
  `type` enum('fixed','percentage') NOT NULL DEFAULT 'fixed',
  `status` enum('active','expired') NOT NULL DEFAULT 'active',
  `valid_until` date NOT NULL,
  `user_id` int NOT NULL) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `email_verifications` (`id` int NOT NULL,
  `user_id` int NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory` (`id` int NOT NULL,
  `ingredient_name` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(50) NOT NULL,
  `min_stock_level` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `inventory_usage_log` (`id` int NOT NULL,
  `order_id` int NOT NULL,
  `ingredient_id` int NOT NULL,
  `quantity_deducted` decimal(10,2) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `usage_type` varchar(50) DEFAULT 'order' COMMENT 'order, adjustment, waste, etc',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message` (`id` int NOT NULL,
  `user_id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `number` varchar(12) NOT NULL,
  `message` varchar(500) NOT NULL) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `message_replies` (`id` int NOT NULL,
  `message_id` int NOT NULL,
  `admin_id` int DEFAULT NULL,
  `admin_name` varchar(255) DEFAULT NULL,
  `reply` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int DEFAULT NULL,
  `seen_by_user` tinyint DEFAULT 0) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `orders` (`id` int NOT NULL,
  `user_id` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `number` varchar(12) NOT NULL,
  `email` varchar(100) NOT NULL,
  `method` varchar(50) NOT NULL,
  `address` varchar(500) NOT NULL,
  `total_products` text NOT NULL,
  `special_instructions` text DEFAULT NULL,
  `total_price` int NOT NULL,
  `discount_used` decimal(10,2) DEFAULT 0.00,
  `placed_on` varchar(50) NOT NULL,
  `payment_status` varchar(20) NOT NULL DEFAULT 'pending',
  `cancel_reason` varchar(255) DEFAULT NULL,
  `prepared_at` datetime DEFAULT NULL,
  `done_preparing_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `rider_id` int DEFAULT NULL,
  `out_for_delivery_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_items` (`id` int NOT NULL,
  `order_id` int NOT NULL,
  `product_id` int NOT NULL,
  `size_id` int DEFAULT NULL COMMENT 'Product size variant if applicable',
  `quantity` int NOT NULL DEFAULT 1 COMMENT 'Number of units ordered',
  `unit_price` decimal(10,2) NOT NULL COMMENT 'Price per unit at time of order',
  `subtotal` decimal(10,2) NOT NULL COMMENT 'quantity * unit_price',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `products` (`id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` int NOT NULL,
  `image` varchar(100) NOT NULL,
  `size_type` enum('cup','slice') NOT NULL DEFAULT 'cup',
  `allow_special_instructions` tinyint NOT NULL DEFAULT 1,
  `details` text DEFAULT NULL,
  `admin_id` int NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_extra_groups` (`id` int NOT NULL,
  `product_id` int NOT NULL,
  `title` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_extra_options` (`id` int NOT NULL,
  `group_id` int NOT NULL,
  `option_name` varchar(150) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_ingredients` (`id` int NOT NULL,
  `product_id` int NOT NULL,
  `ingredient_id` int NOT NULL,
  `quantity_used` decimal(10,2) NOT NULL COMMENT 'Amount used per product unit',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_preference_groups` (`id` int NOT NULL,
  `product_id` int NOT NULL,
  `title` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_preference_options` (`id` int NOT NULL,
  `group_id` int NOT NULL,
  `option_name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `product_sizes` (`id` int NOT NULL,
  `product_id` int NOT NULL,
  `size` varchar(50) NOT NULL,
  `price` int NOT NULL,
  `stock` int NOT NULL) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `topup_requests` (`id` int NOT NULL,
  `user_id` int NOT NULL,
  `amount` int NOT NULL,
  `screenshot` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected','') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (`id` int NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(100) NOT NULL,
  `user_type` varchar(20) NOT NULL DEFAULT 'user',
  `wallet_balance` decimal(10,2) NOT NULL DEFAULT 0.00,
  `profile_image` varchar(250) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'approved',
  `verification_image` varchar(255) DEFAULT NULL,
  `reset_token` varchar(255) DEFAULT NULL,
  `reset_token_expiry` datetime DEFAULT NULL,
  `discount_status` varchar(10) DEFAULT NULL,
  `twofa_email_enabled` tinyint NOT NULL DEFAULT 0,
  `failed_attempts` int DEFAULT 0,
  `lockout_until` datetime DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_discounts` (`id` int NOT NULL,
  `user_id` int NOT NULL,
  `discount_id` int NOT NULL,
  `used` tinyint NOT NULL DEFAULT 0,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `waste_management` (`id` int NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `category` enum('cake','drink','coffee') NOT NULL,
  `quantity` decimal(10,2) NOT NULL,
  `unit` varchar(50) NOT NULL DEFAULT 'qty',
  `reason` enum('expired','cancelled_order','unsold') NOT NULL,
  `related_order_id` int DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `recorded_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Primary keys, indexes, AUTO_INCREMENT attributes, and foreign keys.
ALTER TABLE `admin_activity` ADD PRIMARY KEY (`id`),
  ADD KEY `admin_id` (`admin_id`),
  ADD KEY `timestamp` (`timestamp`);
ALTER TABLE `audit_log` ADD PRIMARY KEY (`id`);
ALTER TABLE `cart` ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `guest_session_id` (`guest_session_id`),
  ADD KEY `idx_cart_user` (`user_id`),
  ADD KEY `idx_cart_dedup` (`user_id`,`product_id`,`size`,`selections_hash`);
ALTER TABLE `chatbot_knowledge` ADD PRIMARY KEY (`id`),
  ADD KEY `is_active` (`is_active`),
  ADD KEY `priority` (`priority`);
ALTER TABLE `chat_history` ADD PRIMARY KEY (`id`);
ALTER TABLE `discounts` ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);
ALTER TABLE `email_verifications` ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `expires_at` (`expires_at`);
ALTER TABLE `inventory` ADD PRIMARY KEY (`id`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_ingredient_name` (`ingredient_name`),
  ADD KEY `idx_created_at` (`created_at`);
ALTER TABLE `inventory_usage_log` ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_ingredient_id` (`ingredient_id`),
  ADD KEY `idx_created_at` (`created_at`);
ALTER TABLE `message` ADD PRIMARY KEY (`id`);
ALTER TABLE `message_replies` ADD PRIMARY KEY (`id`),
  ADD KEY `message_id` (`message_id`);
ALTER TABLE `orders` ADD PRIMARY KEY (`id`),
  ADD KEY `idx_orders_user` (`user_id`),
  ADD KEY `idx_orders_rider` (`rider_id`);
ALTER TABLE `order_items` ADD PRIMARY KEY (`id`),
  ADD KEY `size_id` (`size_id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_product_id` (`product_id`);
ALTER TABLE `products` ADD PRIMARY KEY (`id`);
ALTER TABLE `product_extra_groups` ADD PRIMARY KEY (`id`),
  ADD KEY `idx_product_id` (`product_id`);
ALTER TABLE `product_extra_options` ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group_id` (`group_id`);
ALTER TABLE `product_ingredients` ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_product_ingredient` (`product_id`,`ingredient_id`),
  ADD KEY `idx_product_id` (`product_id`),
  ADD KEY `idx_ingredient_id` (`ingredient_id`);
ALTER TABLE `product_preference_groups` ADD PRIMARY KEY (`id`),
  ADD KEY `idx_product_id` (`product_id`);
ALTER TABLE `product_preference_options` ADD PRIMARY KEY (`id`),
  ADD KEY `idx_group_id` (`group_id`);
ALTER TABLE `product_sizes` ADD PRIMARY KEY (`id`);
ALTER TABLE `topup_requests` ADD PRIMARY KEY (`id`);
ALTER TABLE `users` ADD PRIMARY KEY (`id`);
ALTER TABLE `user_discounts` ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_discount_unique` (`user_id`,`discount_id`),
  ADD KEY `discount_id` (`discount_id`);
ALTER TABLE `waste_management` ADD PRIMARY KEY (`id`),
  ADD KEY `recorded_by` (`recorded_by`),
  ADD KEY `related_order_id` (`related_order_id`),
  ADD KEY `idx_reason` (`reason`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_created_at` (`created_at`);
ALTER TABLE `admin_activity` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `audit_log` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `cart` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `chatbot_knowledge` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `chat_history` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `discounts` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `email_verifications` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `inventory` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `inventory_usage_log` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `message` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `message_replies` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `orders` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `order_items` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `products` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `product_extra_groups` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `product_extra_options` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `product_ingredients` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `product_preference_groups` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `product_preference_options` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `product_sizes` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `topup_requests` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `users` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `user_discounts` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `waste_management` MODIFY `id` int NOT NULL AUTO_INCREMENT;
ALTER TABLE `email_verifications` ADD CONSTRAINT `email_verifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `inventory_usage_log` ADD CONSTRAINT `inventory_usage_log_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `inventory_usage_log_ibfk_2` FOREIGN KEY (`ingredient_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE;
ALTER TABLE `message_replies` ADD CONSTRAINT `message_replies_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `message` (`id`) ON DELETE CASCADE;
ALTER TABLE `orders` ADD CONSTRAINT `orders_rider_fk` FOREIGN KEY (`rider_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `order_items` ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_3` FOREIGN KEY (`size_id`) REFERENCES `product_sizes` (`id`) ON DELETE SET NULL;
ALTER TABLE `product_extra_groups` ADD CONSTRAINT `product_extra_groups_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;
ALTER TABLE `product_extra_options` ADD CONSTRAINT `product_extra_options_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `product_extra_groups` (`id`) ON DELETE CASCADE;
ALTER TABLE `product_ingredients` ADD CONSTRAINT `product_ingredients_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `product_ingredients_ibfk_2` FOREIGN KEY (`ingredient_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE;
ALTER TABLE `product_preference_groups` ADD CONSTRAINT `product_preference_groups_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;
ALTER TABLE `product_preference_options` ADD CONSTRAINT `product_preference_options_ibfk_1` FOREIGN KEY (`group_id`) REFERENCES `product_preference_groups` (`id`) ON DELETE CASCADE;
ALTER TABLE `user_discounts` ADD CONSTRAINT `user_discounts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_discounts_ibfk_2` FOREIGN KEY (`discount_id`) REFERENCES `discounts` (`id`) ON DELETE CASCADE;
ALTER TABLE `waste_management` ADD CONSTRAINT `waste_management_ibfk_1` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `waste_management_ibfk_2` FOREIGN KEY (`related_order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL;
