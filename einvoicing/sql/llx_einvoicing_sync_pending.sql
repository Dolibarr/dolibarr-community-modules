-- Copyright (C) 2026		Jose Martinez					<jose.martinez@pichinov.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

-- Queue of flows that could not be synchronized because they need a manual action
-- (missing product, missing thirdparty, supplier invoice with a different amount, ...).
-- A blocking flow used to abort the whole synchronization run; it is now recorded here so the
-- run can carry on with the other flows, and the queued flow is retried on demand once the
-- manual action is done - it is not lost when it drifts out of the rolling synchronization window.
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
