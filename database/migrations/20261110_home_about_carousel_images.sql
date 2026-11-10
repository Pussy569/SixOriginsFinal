ALTER TABLE `site_customization`
  ADD COLUMN `home_about_slide_1_image_path` varchar(255) NOT NULL DEFAULT 'images/typica bg.png' AFTER `about_product_image_path`,
  ADD COLUMN `home_about_slide_2_image_path` varchar(255) NOT NULL DEFAULT 'images/typica bg.png' AFTER `home_about_slide_1_image_path`,
  ADD COLUMN `home_about_slide_3_image_path` varchar(255) NOT NULL DEFAULT 'images/typica bg.png' AFTER `home_about_slide_2_image_path`,
  ADD COLUMN `home_about_slide_4_image_path` varchar(255) NOT NULL DEFAULT 'images/typica bg.png' AFTER `home_about_slide_3_image_path`;
