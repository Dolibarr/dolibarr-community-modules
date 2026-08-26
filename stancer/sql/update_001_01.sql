--
-- Script run when an upgrade of Dolibarr is done. Whatever is the Dolibarr version.
--

UPDATE llx_societe_rib SET type='ban' WHERE type='sepa';

ALTER TABLE llx_societe_rib ADD stancer_object_ref VARCHAR(32) NULL DEFAULT NULL AFTER stripe_account;
ALTER TABLE llx_societe_rib ADD stancer_account VARCHAR(32) NULL DEFAULT NULL AFTER stancer_object_ref; 
