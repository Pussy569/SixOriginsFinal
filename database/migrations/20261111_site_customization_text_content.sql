ALTER TABLE `site_customization`
  ADD COLUMN `about_impact_1_image_path` varchar(255) NOT NULL DEFAULT 'images/ge.jpg' AFTER `about_product_image_path`,
  ADD COLUMN `about_impact_2_image_path` varchar(255) NOT NULL DEFAULT 'images/gee.jpg' AFTER `about_impact_1_image_path`,
  ADD COLUMN `about_impact_3_image_path` varchar(255) NOT NULL DEFAULT 'images/geee.jpg' AFTER `about_impact_2_image_path`,
  ADD COLUMN `about_why_image_path` varchar(255) NOT NULL DEFAULT 'images/ey.jpg' AFTER `about_impact_3_image_path`,
  ADD COLUMN `content_texts` longtext DEFAULT NULL AFTER `team_members`;
