-- Copyright (C) 2026		Mohamed DAOUD					<mdaoud@nltechno.com>
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


CREATE TABLE llx_pdpconnectfr_routing (
    rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
    fk_soc integer NOT NULL,			-- ID of thirdparty
    routing_id varchar(255) NOT NULL,	-- Electronic invoicing routing identifier (In most cases it will be SIREN OR SIREN_XXX for multi-routing cases, but it can be any identifier depending on the provider and third party)
	source varchar(20) NOT NULL,		-- Source of routing ID: 'manual', 'automatic', 'synchronisation'
    info varchar(255),					-- Optional complementary information or comment
    syncflowid varchar(255),			-- Optional Flow ID when source = 'synchronisation'
    active tinyint DEFAULT 1,			-- 1 = enabled, 0 = disabled
    is_default tinyint DEFAULT 0,		-- 1 = default routing ID for this thirdparty
    date_creation datetime NOT NULL,
    tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat integer NOT NULL,
    fk_user_modif integer
) ENGINE=InnoDB;

