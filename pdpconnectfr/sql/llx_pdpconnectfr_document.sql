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


CREATE TABLE llx_pdpconnectfr_document(
	-- BEGIN MODULEBUILDER FIELDS
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL, 
	date_creation datetime NOT NULL, 
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, 
	fk_user_creat integer NOT NULL, 
	fk_user_modif integer, 
	status integer NOT NULL DEFAULT 0, 
	call_id varchar(50), 
	flow_id varchar(255), 
	tracking_idref varchar(50), 
	flow_type varchar(64), 
	flow_direction varchar(10), 
	flow_syntax varchar(50), 
	flow_profile varchar(50), 
	ack_status varchar(50), 
	ack_reason_code varchar(255), 
	ack_info text, 
	document_body text, 
	fk_element_id integer, 
	fk_element_type varchar(100), 
	submittedat datetime NOT NULL, 
	updatedat datetime, 
	provider varchar(50) NOT NULL, 
	entity integer DEFAULT 1, 
	flow_uiid varchar(255), 
	cdar_lifecycle_code varchar(50), 
	cdar_lifecycle_label varchar(255), 
	cdar_reason_code varchar(50), 
	cdar_reason_desc varchar(255), 
	cdar_reason_detail varchar(255),
	response_for_debug text					-- To store response if debug is on
	-- END MODULEBUILDER FIELDS
) ENGINE=innodb;
