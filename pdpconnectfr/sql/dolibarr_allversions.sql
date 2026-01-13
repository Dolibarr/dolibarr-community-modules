--
-- Script run when an upgrade of Dolibarr is done. Whatever is the Dolibarr version.
--

ALTER TABLE llx_pdpconnectfr_call ADD COLUMN batchlimit integer NOT NULL DEFAULT 1;

UPDATE llx_pdpconnectfr_document SET flow_type = 'sync' WHERE flow_type IS NULL;
