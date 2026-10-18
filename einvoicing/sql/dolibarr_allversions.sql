--
-- Script run when an upgrade of Dolibarr is done. Whatever is the Dolibarr version.
--

DELETE FROM llx_rights_def WHERE perms = 'document' AND module = 'einvoicing' AND id >= 9502004;

UPDATE llx_rights_def SET perms = 'document' WHERE perms = 'call' AND module = 'einvoicing';

ALTER TABLE llx_einvoicing_call ADD COLUMN batchlimit integer NOT NULL DEFAULT 1;

UPDATE llx_einvoicing_document SET flow_type = 'sync' WHERE flow_type IS NULL;

ALTER TABLE llx_einvoicing_document MODIFY COLUMN flow_type varchar(64);

ALTER TABLE llx_einvoicing_document ADD COLUMN response_for_debug text;

ALTER TABLE llx_einvoicing_call MODIFY COLUMN totalflow integer NULL DEFAULT NULL;

ALTER TABLE llx_einvoicing_routing ADD COLUMN routing_type varchar(12) NOT NULL DEFAULT 'thirdparty';

ALTER TABLE llx_einvoicing_extlinks ADD COLUMN override_routing_id varchar(255) NULL DEFAULT NULL;

ALTER TABLE llx_einvoicing_document MODIFY COLUMN tracking_idref varchar(255);

ALTER TABLE llx_einvoicing_call ALTER COLUMN entity SET DEFAULT 1;

ALTER TABLE llx_einvoicing_extlinks ADD COLUMN ap_precheck_status varchar(50) DEFAULT NULL;

ALTER TABLE llx_einvoicing_extlinks ADD COLUMN ap_precheck_result text DEFAULT NULL;

CREATE TABLE llx_einvoicing_extrafields (
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	element_id integer NOT NULL,					-- ID of element or element line
	element_type varchar(50) NOT NULL,				-- Type of element (from property object->element, for example 'facture', 'invoice_supplier', 'societe', ...)
	name varchar(64) NOT NULL,						-- Name of the property ('buyer_order_reference', ...)
	value text,										-- Value of the property
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL,
	fk_user_modif integer
) ENGINE = innodb;

ALTER TABLE llx_einvoicing_extrafields ADD UNIQUE INDEX uk_einvoicing_extrafields (element_type, element_id, name);

UPDATE llx_extrafields SET printable = 2 WHERE printable = 1 AND elementtype IN ('facture', 'commande') AND name IN ('d4d_service_code', 'd4d_contract_number', 'd4d_promise_code');

ALTER TABLE llx_einvoicing_call ADD COLUMN call_id_num integer AFTER call_id;

ALTER TABLE llx_einvoicing_call ADD COLUMN call_id_num integer AFTER call_id;

ALTER TABLE llx_einvoicing_call ADD COLUMN request_id varchar(36) AFTER endpoint;

ALTER TABLE llx_einvoicing_extlinks ADD INDEX idx_einvoicing_extlinks_element (element_type, element_id);

ALTER TABLE llx_einvoicing_extlinks ADD INDEX idx_einvoicing_extlinks_syncref (element_type, syncref);

ALTER TABLE llx_einvoicing_extlinks ADD INDEX idx_einvoicing_extlinks_flowid (flow_id);

ALTER TABLE llx_einvoicing_lifecycle_msg ADD INDEX idx_einvoicing_lifecycle_msg_element (element_type, element_id, lc_validation_status);

ALTER TABLE llx_einvoicing_lifecycle_msg ADD INDEX idx_einvoicing_lifecycle_msg_flowid (flow_id);

ALTER TABLE llx_einvoicing_routing ADD INDEX idx_einvoicing_routing_soc (fk_soc, routing_type, active);
