ALTER TABLE `site_customization`
  ADD COLUMN `email_verification_background_image_path` varchar(255) DEFAULT NULL
  AFTER `admin_register_image_path`;
