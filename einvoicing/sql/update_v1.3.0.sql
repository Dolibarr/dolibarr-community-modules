--
-- Script run when module is reloaded. Whatever is the Dolibarr version.
--

-- RoleCodes of the CDAR ExchangedDocument/RecipientTradeParty the status was addressed to, comma
-- separated ("SE", "SE,BY"). Tells a rejection posted at emission from one posted at reception,
-- which no other field of a 213 carries (issue #973). Empty for a status this module sent.
ALTER TABLE llx_einvoicing_lifecycle_msg ADD COLUMN lc_recipient_roles varchar(50) NULL AFTER lc_reason_code;

CREATE TABLE llx_einvoicing_sync_pending (
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,				-- Multi-entity support
	provider varchar(50) NOT NULL,					-- Provider key ('superpdp', 'esalink', ...)
	flow_id varchar(255) NOT NULL,					-- PDP flow id (i_..., ie_...)
	flow_direction varchar(10),						-- In or Out
	flow_type varchar(64),							-- CustomerInvoice, SupplierInvoice, ...
	tracking_idref varchar(255),					-- Invoice reference carried by the flow (if any)
	fk_element_type varchar(100),					-- Linked Dolibarr element type if known ('facture', 'invoice_supplier', ...)
	fk_element_id integer,							-- Linked Dolibarr element id if known (NULL for an incoming flow not yet created)
	reason_code varchar(64),						-- Business reason ('PRODUCT_NOT_FOUND', 'THIRDPARTY_NOT_FOUND', ...)
	reason_message text,							-- Human readable message of the last attempt
	action_data mediumtext,							-- JSON list of the normalized manual actions {key,url} the pending list renders as icons
	action_html mediumtext,							-- Ready-to-display block of manual-action buttons built by the protocol (create / associate an existing product / set default), same as shown on the synchronization page
	match_data mediumtext,							-- JSON of the issuer identifiers of the flow (name, vatnumber, idprof1=SIREN, idprof2=SIRET, ...) used to link the flow to an existing thirdparty
	flow_updatedat datetime,						-- updatedAt of the flow at the PDP (to know its age versus the sync window)
	nb_attempts integer DEFAULT 0,					-- Number of synchronization attempts made on this flow
	date_lastattempt datetime,						-- Date of the last synchronization attempt
	status integer DEFAULT 0 NOT NULL,				-- 0=pending, 1=resolved, 2=ignored
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL,
	fk_user_modif integer
) ENGINE = innodb;

ALTER TABLE llx_einvoicing_sync_pending ADD UNIQUE INDEX uk_einvoicing_sync_pending_flow (entity, provider, flow_id);

-- The pending list is filtered on status (pending first) and ordered by the flow update date.
ALTER TABLE llx_einvoicing_sync_pending ADD INDEX idx_einvoicing_sync_pending_status (entity, status, flow_updatedat);

-- The API call trace stores the payloads it logs: a document is easily bigger than the 65,535 bytes
-- of a text column, and the INSERT is then refused whole, so the call leaves no trace at all
-- (issue #995). mediumtext is the type llx_einvoicing_document.xml_data already uses for the same
-- content.
ALTER TABLE llx_einvoicing_call MODIFY COLUMN request_body mediumtext;
ALTER TABLE llx_einvoicing_call MODIFY COLUMN response mediumtext;
ALTER TABLE llx_einvoicing_call MODIFY COLUMN processing_result mediumtext;
