--
-- Script run when an upgrade of Dolibarr is done. Whatever is the Dolibarr version.
--

ALTER TABLE llx_stancer_stancer_payouts ADD amount_net INT NULL DEFAULT NULL AFTER fees;
