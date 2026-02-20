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


CREATE TABLE llx_pdpconnectfr_extlinks(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	element_id int, 		    				-- ID of element.
	element_type varchar(50) NOT NULL, 		    -- Type of element (from property object->element)
    provider varchar(50) NOT NULL, 				-- Provider key ('esalink', ...)
	date_creation datetime NOT NULL, 
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, 
	fk_user_creat integer NOT NULL, 
	fk_user_modif integer,
	flow_id varchar(255),
	syncstatus integer,							-- If the object has a status into the einvoice external system
	syncref varchar(255),						-- If the object has a given reference into the einvoice external system
	synccomment varchar(255)					-- If we want to store a message for the last sync action try
) ENGINE=innodb;
