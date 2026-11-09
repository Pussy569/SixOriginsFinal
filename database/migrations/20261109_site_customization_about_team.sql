ALTER TABLE `site_customization`
  ADD COLUMN `about_hero_image_path` varchar(255) NOT NULL DEFAULT 'images/aboback.png' AFTER `logo_path`,
  ADD COLUMN `about_cafe_image_path` varchar(255) NOT NULL DEFAULT 'images/promo-cafe.png' AFTER `about_hero_image_path`,
  ADD COLUMN `about_product_image_path` varchar(255) NOT NULL DEFAULT 'images/promo-product.png' AFTER `about_cafe_image_path`,
  ADD COLUMN `team_members` longtext DEFAULT NULL AFTER `about_product_image_path`;
