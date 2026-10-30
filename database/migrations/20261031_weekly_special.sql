CREATE TABLE `weekly_special_settings` (
  `id` tinyint unsigned NOT NULL DEFAULT 1,
  `product_id` int DEFAULT NULL,
  `description` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  CONSTRAINT `weekly_special_settings_singleton` CHECK (`id` = 1),
  CONSTRAINT `weekly_special_settings_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
