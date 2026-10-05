-- Replace this placeholder account with your real admin details before deployment.
-- The random password hash below has no retained plaintext password.
INSERT INTO `users` (`name`, `email`, `password`, `user_type`, `wallet_balance`, `profile_image`, `status`)
VALUES ('Deployment Admin Placeholder', 'admin@example.invalid', '$2y$10$A0ogo9oNVgjqOpnoMZB5OO1sjd6BICoUZQItLkuT/6XZpQI3WTl5O', 'admin', 0.00, '', 'approved');