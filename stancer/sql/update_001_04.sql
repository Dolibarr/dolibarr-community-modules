--
-- Add fees_vat column to stancer_stancer_payouts.
-- Stancer API v2 returns fees (HT) and fees_vat (VAT on fees) separately.
-- Both are needed to reconcile the monthly supplier invoice (TTC) issued by Stancer.
--

ALTER TABLE `llx_stancer_stancer_payouts` ADD COLUMN `fees_vat` integer DEFAULT NULL AFTER `fees`;
