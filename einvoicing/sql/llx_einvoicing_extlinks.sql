-- Copyright (C) 2025		SuperAdmin					<daoud.mouhamed@gmail.com>
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


CREATE TABLE llx_einvoicing_extlinks (
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	element_id int, 		    				-- ID of element.
	element_type varchar(50) NOT NULL, 		    -- Type of element (from property object->element)
	provider varchar(50) NOT NULL, 				-- Provider key ('esalink', ...)
	provider_sender_routing_id varchar(50) NULL, -- Provider key ('esalink', ...)
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL,
	fk_user_modif integer,
	flow_id varchar(255),
	syncstatus integer,							-- If the object has a status into the einvoice external system
	syncref varchar(255),						-- If the object has a given reference into the einvoice external system
	synccomment text,						-- If we want to store a message for the last sync action try
	ap_precheck_status varchar(50),			-- Status of the PDP pre-check (passed or failed)
	ap_precheck_result text,				-- Result/details of the PDP pre-check
	override_routing_id varchar(255)		-- Optional routing ID override for this specific invoice (overrides thirdparty default routing)
) ENGINE = innodb;

-- Migrations for installations created before these columns existed.
-- The CREATE TABLE above is a no-op on an existing table, and dolibarr_allversions.sql is played
-- only when the Dolibarr core itself is upgraded, never on a module-only upgrade. Without the
-- statements below, such an installation keeps a table missing these columns while the code already
-- selects and inserts them, which breaks any invoice creation with
-- "Unknown column 'ap_precheck_status' in 'field list'" (see issue #354).
-- These are replayed at each module activation: _load_tables() runs every llx_*.sql file, and
-- run_sql() accepts DB_ERROR_COLUMN_ALREADY_EXISTS as a non blocking error, so they are idempotent.
-- Keep them in sync with dolibarr_allversions.sql.
ALTER TABLE llx_einvoicing_extlinks ADD COLUMN provider_sender_routing_id varchar(50) NULL;
ALTER TABLE llx_einvoicing_extlinks ADD COLUMN ap_precheck_status varchar(50) DEFAULT NULL;
ALTER TABLE llx_einvoicing_extlinks ADD COLUMN ap_precheck_result text DEFAULT NULL;
ALTER TABLE llx_einvoicing_extlinks ADD COLUMN override_routing_id varchar(255) NULL DEFAULT NULL;
