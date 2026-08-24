--
-- Script run when an upgrade of Dolibarr is done. Whatever is the Dolibarr version.
--

-- remove ON UPDATE CURRENT_TIMESTAMP() due to old versions ob mysql < 5.6 (backups and so on)
ALTER TABLE `llx_stancer_stancer_payments` CHANGE `date_bank` `date_bank` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP; 

