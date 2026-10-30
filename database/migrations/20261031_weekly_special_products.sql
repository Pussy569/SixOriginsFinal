CREATE TABLE `weekly_special_products` (
  `product_id` int NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`product_id`),
  CONSTRAINT `weekly_special_products_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `weekly_special_products` (`product_id`, `description`)
SELECT `product_id`, `description`
FROM `weekly_special_settings`
WHERE `product_id` IS NOT NULL;

DROP TABLE `weekly_special_settings`;
