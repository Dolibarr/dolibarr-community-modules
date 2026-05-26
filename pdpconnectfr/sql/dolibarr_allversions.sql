--
-- Script run when an upgrade of Dolibarr is done. Whatever is the Dolibarr version.
--

DELETE FROM llx_rights_def WHERE perms = 'document' AND module = 'pdpconnectfr' AND id >= 9502004;

UPDATE llx_rights_def SET perms = 'document' WHERE perms = 'call' AND module = 'pdpconnectfr';

ALTER TABLE llx_pdpconnectfr_call ADD COLUMN batchlimit integer NOT NULL DEFAULT 1;

UPDATE llx_pdpconnectfr_document SET flow_type = 'sync' WHERE flow_type IS NULL;

ALTER TABLE llx_pdpconnectfr_document MODIFY COLUMN flow_type varchar(64);

ALTER TABLE llx_pdpconnectfr_document ADD COLUMN response_for_debug text;

ALTER TABLE llx_pdpconnectfr_call MODIFY COLUMN totalflow integer NULL DEFAULT NULL;

ALTER TABLE llx_pdpconnectfr_routing ADD COLUMN routing_type varchar(12) NOT NULL DEFAULT 'thirdparty';

ALTER TABLE llx_pdpconnectfr_extlinks ADD COLUMN override_routing_id varchar(255) NULL DEFAULT NULL;

ALTER TABLE llx_pdpconnectfr_document MODIFY COLUMN tracking_idref varchar(255);
