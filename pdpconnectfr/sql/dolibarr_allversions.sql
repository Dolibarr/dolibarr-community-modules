--
-- Script run when an upgrade of Dolibarr is done. Whatever is the Dolibarr version.
--

ALTER TABLE llx_pdpconnectfr_call ADD COLUMN batchlimit integer NOT NULL DEFAULT 1;

UPDATE llx_pdpconnectfr_document SET flow_type = 'sync' WHERE flow_type IS NULL;

ALTER TABLE llx_pdpconnectfr_document MODIFY COLUMN flow_type varchar(64);

ALTER TABLE llx_pdpconnectfr_document ADD COLUMN response_for_debug text;

