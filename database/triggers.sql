-- MySQL 8 audit triggers for Six Origins; import after schema.sql.

DELIMITER $$

CREATE TRIGGER `after_user_delete_audit` AFTER DELETE ON `users` FOR EACH ROW BEGIN
    INSERT INTO audit_log (action_performed, target_user_id, details)
    VALUES ('DELETE_USER', OLD.id, CONCAT('User ', OLD.name, ' was permanently removed from Six Origins.'));
END
$$

CREATE TRIGGER `after_user_status_change` AFTER UPDATE ON `users` FOR EACH ROW BEGIN
    IF OLD.status <> NEW.status THEN
        INSERT INTO audit_log (action_performed, target_user_id, details)
        VALUES ('STATUS_CHANGE', NEW.id, CONCAT('Status changed from ', OLD.status, ' to ', NEW.status));
    END IF;
END
$$

CREATE TRIGGER `audit_wallet_changes` AFTER UPDATE ON `users` FOR EACH ROW BEGIN
    -- Only log if the wallet balance actually changed
    IF OLD.wallet_balance <> NEW.wallet_balance THEN
        INSERT INTO audit_log (action_performed, target_user_id, details)
        VALUES (
            'WALLET_UPDATE',
            NEW.id,
            CONCAT('Balance adjusted from ₱', FORMAT(OLD.wallet_balance, 2), ' to ₱', FORMAT(NEW.wallet_balance, 2))
        );
    END IF;
END
$$

DELIMITER ;
