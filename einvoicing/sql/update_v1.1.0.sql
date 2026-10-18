--
-- Script run when module is reloaded. Whatever is the Dolibarr version.
--

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

ALTER TABLE llx_einvoicing_call ADD COLUMN request_id varchar(36) AFTER endpoint;
