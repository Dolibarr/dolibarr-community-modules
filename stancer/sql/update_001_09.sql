--
-- One row per Stancer object and entity, as for the payments (update_001_06):
-- without the unique index, two concurrent refreshes could both pass the
-- "already known" check and insert the same payout, refund or dispute twice.
--
-- Mirror of the indexes added to the .key.sql files for new installations.
-- An installation already holding duplicates refuses the index (tolerated
-- error, logged by run_sql): the duplicates have to be removed by hand first.
--

ALTER TABLE llx_stancer_stancer_payouts ADD UNIQUE INDEX uk_stancer_stancer_payouts_payout_id (entity, payout_id);
ALTER TABLE llx_stancer_stancer_refunds ADD UNIQUE INDEX uk_stancer_stancer_refunds_refund_id (entity, refund_id);
ALTER TABLE llx_stancer_stancer_disputes ADD UNIQUE INDEX uk_stancer_stancer_disputes_dispute_id (entity, dispute_id);
