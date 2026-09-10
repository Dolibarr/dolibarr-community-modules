-- Copyright (C) 2026		Pierre Grasswill				<da.grumpf@gmail.com>
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

-- Flows the access point still holds because this Dolibarr could not import them yet. A postponed
-- flow stores nothing else (that is what makes it retriable), so without this table there is no
-- record that it was ever seen: the synchronization panel could only count the ones met in its own
-- run, and never say what is waiting, why, or since when. One row per flow, dropped as soon as the
-- flow is imported.

CREATE TABLE llx_einvoicing_postponed (
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	flow_id varchar(255) NOT NULL,			-- Flow identifier at the access point
	provider varchar(50) NOT NULL,			-- Access point the flow comes from ('SUPERPDP', 'ESALINK', ...)
	call_id varchar(50),					-- Synchronization call that last met it: the batch response holds the flow
	entity integer DEFAULT 1,
	reason_code varchar(64),				-- Import 'actioncode' ('LINKED_INVOICE_NOT_FOUND', ...)
	reason_message text,					-- What the import answered, kept as is for diagnostics
	business_message text,					-- The same thing said to the user, when the import said it
	action_html text,						-- Ready made action offered with it (button and its link)
	action_data text,						-- JSON of what the action works on (supplierref, socid, ...)
	fk_soc integer,							-- Supplier, when the import got far enough to know it
	document_ref varchar(255),				-- Reference of the received document, when known
	nb_attempts integer NOT NULL DEFAULT 1,	-- Synchronizations that have met this flow
	date_creation datetime NOT NULL,		-- First one: what "waiting since" is read from
	date_last_attempt datetime NOT NULL,	-- Most recent one
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL,
	fk_user_modif integer
) ENGINE = InnoDB;
