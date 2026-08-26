--
-- Index the order_id column of llx_stancer_stancer_payments.
--
-- order_id holds the ref of the source document (invoice, order...). It is a
-- lookup key of the module: Stancer_payments::fetch() can search on it, the
-- payment list offers a search_order_id filter, and the "a payment is already
-- running" guard of the invoice card (Stancer_payments::fetchAllRunningForInvoice())
-- matches it on every display of a validated invoice.
--
-- The index is NOT unique on purpose: payment retries and same-day grouped SEPA
-- debits legitimately share one order_id value.
--
-- Mirror of the same statement added to sql/llx_stancer_stancer_payments.key.sql
-- for new installations.
--

ALTER TABLE `llx_stancer_stancer_payments` ADD INDEX idx_stancer_stancer_payments_order_id (order_id);
