ALTER TABLE `site_customization`
  ADD COLUMN `navbar_hover_text_color` char(7) NOT NULL DEFAULT '#FFFFFF' AFTER `navbar_hover_color`,
  ADD COLUMN `navbar_hover_border_color` char(7) NOT NULL DEFAULT '#8A3F2B' AFTER `navbar_hover_text_color`;
