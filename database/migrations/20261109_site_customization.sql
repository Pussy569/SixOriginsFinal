CREATE TABLE `site_customization` (
  `id` tinyint NOT NULL DEFAULT 1,
  `brand_name` varchar(60) NOT NULL DEFAULT 'Six Origins',
  `brand_tagline` varchar(80) NOT NULL DEFAULT 'Cafe',
  `logo_path` varchar(255) NOT NULL DEFAULT 'images/logos.png',
  `primary_color` char(7) NOT NULL DEFAULT '#C6453E',
  `secondary_color` char(7) NOT NULL DEFAULT '#5E1F13',
  `accent_color` char(7) NOT NULL DEFAULT '#664C47',
  `page_background` char(7) NOT NULL DEFAULT '#FFFAF5',
  `surface_color` char(7) NOT NULL DEFAULT '#FFFFFF',
  `text_color` char(7) NOT NULL DEFAULT '#3D2B27',
  `navbar_background` char(7) NOT NULL DEFAULT '#5E1F13',
  `navbar_text` char(7) NOT NULL DEFAULT '#FFFFFF',
  `hero_media_path` varchar(255) DEFAULT NULL,
  `hero_media_type` enum('video','image') NOT NULL DEFAULT 'video',
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
