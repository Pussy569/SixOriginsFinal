-- Match the cart size capacity to product_sizes so full option labels persist.
ALTER TABLE `cart`
  MODIFY COLUMN `size` varchar(50) NOT NULL;
