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


CREATE TABLE llx_pdpconnectfr_call(
	-- BEGIN MODULEBUILDER FIELDS
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL, 
	call_id varchar(50) NOT NULL, 
	totalflow integer NOT NULL DEFAULT 1, 
	batchlimit integer NOT NULL DEFAULT 1, 
	skippedflow integer NOT NULL DEFAULT 1, 
	successflow integer NOT NULL DEFAULT 1, 
	date_creation datetime NOT NULL, 
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, 
	fk_user_creat integer NOT NULL, 
	fk_user_modif integer, 
	status integer NOT NULL, 
	call_type varchar(50) NOT NULL, 
	method varchar(10), 
	endpoint varchar(255) NOT NULL, 
	request_body text, 
	response text, 
	processing_result text, 
	provider varchar(50) NOT NULL, 
	entity varchar(50) DEFAULT 1
	-- END MODULEBUILDER FIELDS
) ENGINE=innodb;
