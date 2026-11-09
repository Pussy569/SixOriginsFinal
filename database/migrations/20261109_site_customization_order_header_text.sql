ALTER TABLE `site_customization`
  ADD COLUMN `order_header_text_color` char(7) NOT NULL DEFAULT '#FFFFFF' AFTER `navbar_text`;
