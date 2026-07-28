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
	element_id int, 		    					-- ID of element or element line .
	element_type varchar(50) NOT NULL, 		    	-- Type of element (from property object->element, for example 'facture', 'supplier_invoice', 'product', ...)
	provider varchar(50) NOT NULL, 					-- Provider key ('esalink', ...)
	provider_sender_routing_id varchar(50) NULL, 	-- Id of routing of the source (if applicable)
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL,
	fk_user_modif integer,
	flow_id varchar(255),							-- Sync flow id (if applicable)
	syncstatus integer,								-- If the object has a status into the einvoice external system
	syncref varchar(255),							-- If the object has a given reference into the einvoice external system
	synccomment text,								-- If we want to store a message for the last sync action try
	ap_precheck_status varchar(50),					-- For invoice link, status of the AP pre-check (passed or failed)
	ap_precheck_result text,						-- For invoice link, result/details of the AP pre-check
	override_routing_id varchar(255)				-- For invoice, optional routing ID override for this specific invoice (overrides thirdparty default routing)
) ENGINE = innodb;
