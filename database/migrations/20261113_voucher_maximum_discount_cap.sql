ALTER TABLE `voucher_campaigns`
  ADD COLUMN `max_discount_cap` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `reward_amount`;

ALTER TABLE `discounts`
  ADD COLUMN `maximum_discount_cap` decimal(10,2) NOT NULL DEFAULT 0.00 AFTER `reward_type`;
