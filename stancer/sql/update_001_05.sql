--
-- Add grouped_invoice_ids column to stancer_stancer_payments.
-- Used by the same-day SEPA grouping feature: when several invoices of the
-- same customer issued on the same day are bundled into a single SEPA debit,
-- this column stores the comma-separated list of Dolibarr invoice ids that
-- the single Stancer payment must be dispatched across at success time
-- (and reopened across at dispute/refund time).
-- For non-grouped (solo) payments the column stays NULL.
--

ALTER TABLE `llx_stancer_stancer_payments` ADD COLUMN `grouped_invoice_ids` TEXT DEFAULT NULL AFTER `unique_id`;
