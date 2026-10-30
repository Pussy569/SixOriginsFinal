ALTER TABLE `users`
  ADD COLUMN `active_session_last_activity` datetime DEFAULT NULL AFTER `active_session_token`;

UPDATE `users`
SET `active_session_last_activity` = NOW()
WHERE `active_session_token` IS NOT NULL;
