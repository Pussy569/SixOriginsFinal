-- Preserve existing inventory and product mappings while adding size-scoped
-- packaging usage and an archive flag for product size variants.
ALTER TABLE `product_sizes`
  ADD COLUMN `is_active` tinyint NOT NULL DEFAULT 1;

CREATE TABLE `product_size_ingredients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_size_id` int NOT NULL,
  `ingredient_id` int NOT NULL,
  `quantity_used` decimal(10,2) NOT NULL COMMENT 'Amount of this inventory item consumed per selected size',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_product_size_ingredient` (`product_size_id`,`ingredient_id`),
  KEY `idx_product_size_ingredient` (`ingredient_id`),
  CONSTRAINT `product_size_ingredients_ibfk_1` FOREIGN KEY (`product_size_id`) REFERENCES `product_sizes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_size_ingredients_ibfk_2` FOREIGN KEY (`ingredient_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

UPDATE `inventory`
SET `category` = 'consumable'
WHERE LOWER(`category`) = 'drinks';
