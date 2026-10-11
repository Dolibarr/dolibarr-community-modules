--
-- Script run when an upgrade of Dolibarr is done. Whatever is the Dolibarr version.
--

-- date_bank is the first TIMESTAMP column of the table: declared NULL DEFAULT NULL,
-- MySQL < 5.6 does not add ON UPDATE CURRENT_TIMESTAMP to it, so the bank date is
-- never rewritten on update. Same definition as sql/llx_stancer_stancer_payments.sql
-- and as the class field (notnull 0); every update file is replayed on activation,
-- so existing installations converge on it.
ALTER TABLE `llx_stancer_stancer_payments` CHANGE `date_bank` `date_bank` TIMESTAMP NULL DEFAULT NULL;
