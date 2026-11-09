ALTER TABLE `site_customization`
  ADD COLUMN `showcase_image_path` varchar(255) NOT NULL DEFAULT 'images/coffee-splash.png' AFTER `loading_style`,
  ADD COLUMN `showcase_background` char(7) NOT NULL DEFAULT '#5E1F13' AFTER `showcase_image_path`,
  ADD COLUMN `showcase_heading_color` char(7) NOT NULL DEFAULT '#FFFFFF' AFTER `showcase_background`,
  ADD COLUMN `showcase_text_color` char(7) NOT NULL DEFAULT '#F2E9E4' AFTER `showcase_heading_color`,
  ADD COLUMN `chatbot_image_path` varchar(255) NOT NULL DEFAULT 'images/logos.png' AFTER `showcase_text_color`,
  ADD COLUMN `chatbot_primary_color` char(7) NOT NULL DEFAULT '#C6453E' AFTER `chatbot_image_path`,
  ADD COLUMN `chatbot_panel_color` char(7) NOT NULL DEFAULT '#FFFBF7' AFTER `chatbot_primary_color`,
  ADD COLUMN `chatbot_text_color` char(7) NOT NULL DEFAULT '#5E1F13' AFTER `chatbot_panel_color`,
  ADD COLUMN `chatbot_header_text_color` char(7) NOT NULL DEFAULT '#FFFFFF' AFTER `chatbot_text_color`;
