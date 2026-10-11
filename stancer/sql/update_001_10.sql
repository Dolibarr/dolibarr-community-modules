--
-- The foreign keys from the module tables to llx_user had no ON DELETE clause:
-- deleting a user who created Stancer rows failed. They are dropped here and
-- added again with ON DELETE SET NULL by update_001_11.sql (two files, each
-- statement stays independent of the others, see DOLIBARR_UPDATE_SQL.md).
-- A missing key is a tolerated error.
--

ALTER TABLE llx_stancer_stancer_payments DROP FOREIGN KEY llx_stancer_stancer_payments_fk_user_creat;
ALTER TABLE llx_stancer_stancer_refunds DROP FOREIGN KEY llx_stancer_stancer_refunds_fk_user_creat;
ALTER TABLE llx_stancer_stancer_disputes DROP FOREIGN KEY llx_stancer_stancer_disputes_fk_user_creat;
