ALTER TABLE `login_session_requests`
  ADD COLUMN `previous_session_token` char(64) DEFAULT NULL AFTER `new_session_token`,
  ADD COLUMN `email_code_hash` varchar(255) DEFAULT NULL AFTER `expires_at`,
  ADD COLUMN `email_code_expires_at` datetime DEFAULT NULL AFTER `email_code_hash`,
  ADD COLUMN `email_code_attempts` tinyint unsigned NOT NULL DEFAULT 0 AFTER `email_code_expires_at`,
  ADD COLUMN `email_code_sent_at` datetime DEFAULT NULL AFTER `email_code_attempts`,
  ADD COLUMN `email_code_send_count` tinyint unsigned NOT NULL DEFAULT 0 AFTER `email_code_sent_at`;
