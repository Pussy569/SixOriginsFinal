CREATE TABLE `checkout_payment_settings` (
  `id` tinyint NOT NULL DEFAULT 1,
  `cash_on_delivery` tinyint NOT NULL DEFAULT 1,
  `wallet_delivery` tinyint NOT NULL DEFAULT 1,
  `store_pickup_wallet` tinyint NOT NULL DEFAULT 1,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `checkout_payment_settings` (`id`) VALUES (1);
