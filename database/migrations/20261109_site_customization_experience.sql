ALTER TABLE `site_customization`
  ADD COLUMN `login_image_path` varchar(255) NOT NULL DEFAULT 'images/logback.jpeg' AFTER `logo_path`,
  ADD COLUMN `register_image_path` varchar(255) NOT NULL DEFAULT 'images/logback.jpeg' AFTER `login_image_path`,
  ADD COLUMN `admin_register_image_path` varchar(255) NOT NULL DEFAULT 'images/logback.jpeg' AFTER `register_image_path`,
  ADD COLUMN `loading_style` enum('rings','spinner','dots','progress','coffee','none') NOT NULL DEFAULT 'rings' AFTER `admin_register_image_path`;
