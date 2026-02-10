--
-- Script run when an upgrade of Dolibarr is done. Whatever is the Dolibarr version.
--

ALTER TABLE llx_pdpconnectfr_call ADD COLUMN batchlimit integer NOT NULL DEFAULT 1;

UPDATE llx_pdpconnectfr_document SET flow_type = 'sync' WHERE flow_type IS NULL;

ALTER TABLE llx_pdpconnectfr_document MODIFY COLUMN flow_type varchar(64);

ALTER TABLE llx_pdpconnectfr_document ADD COLUMN response_for_debug text;

ALTER TABLE llx_oauth_token ADD COLUMN tokenstring_refresh text NULL;

ALTER TABLE llx_oauth_token ADD COLUMN lastaccess datetime NULL; 

ALTER TABLE llx_oauth_token ADD COLUMN apicount_previous_month BIGINT UNSIGNED DEFAULT 0;

ALTER TABLE llx_oauth_token ADD COLUMN apicount_month BIGINT UNSIGNED DEFAULT 0; 

ALTER TABLE llx_oauth_token ADD COLUMN apicount_total BIGINT UNSIGNED DEFAULT 0; 

ALTER TABLE llx_oauth_token ADD COLUMN expire_at datetime NULL;

