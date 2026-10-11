--
-- Foreign keys to llx_user added again with ON DELETE SET NULL (dropped by
-- update_001_10.sql). Same definition as the .key.sql files of new installations.
--

ALTER TABLE llx_stancer_stancer_payments ADD CONSTRAINT llx_stancer_stancer_payments_fk_user_creat FOREIGN KEY (fk_user_creat) REFERENCES llx_user(rowid) ON DELETE SET NULL;
ALTER TABLE llx_stancer_stancer_refunds ADD CONSTRAINT llx_stancer_stancer_refunds_fk_user_creat FOREIGN KEY (fk_user_creat) REFERENCES llx_user(rowid) ON DELETE SET NULL;
ALTER TABLE llx_stancer_stancer_disputes ADD CONSTRAINT llx_stancer_stancer_disputes_fk_user_creat FOREIGN KEY (fk_user_creat) REFERENCES llx_user(rowid) ON DELETE SET NULL;
