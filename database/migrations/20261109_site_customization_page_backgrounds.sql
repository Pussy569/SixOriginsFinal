ALTER TABLE `site_customization`
  ADD COLUMN `navbar_hover_color` char(7) NOT NULL DEFAULT '#8A3F2B' AFTER `navbar_text`,
  ADD COLUMN `shop_background_image_path` varchar(255) DEFAULT NULL AFTER `order_header_text_color`,
  ADD COLUMN `contact_background_image_path` varchar(255) DEFAULT NULL AFTER `shop_background_image_path`,
  ADD COLUMN `orders_background_image_path` varchar(255) DEFAULT NULL AFTER `contact_background_image_path`,
  ADD COLUMN `search_background_image_path` varchar(255) DEFAULT NULL AFTER `orders_background_image_path`,
  ADD COLUMN `cart_background_image_path` varchar(255) DEFAULT NULL AFTER `search_background_image_path`,
  ADD COLUMN `profile_background_image_path` varchar(255) DEFAULT NULL AFTER `cart_background_image_path`,
  ADD COLUMN `topup_background_image_path` varchar(255) DEFAULT NULL AFTER `profile_background_image_path`;
