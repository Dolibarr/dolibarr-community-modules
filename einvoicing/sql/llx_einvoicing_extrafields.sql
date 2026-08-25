-- Copyright (C) 2026		Pierre Grasswill			<da.grumpf@gmail.com>
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

-- Properties the module has to keep on a Dolibarr object without using the extrafields of the core,
-- which an admin or a user could rename, empty or delete while the data they hold is critical.
-- One row = one named property of one object, so a new property needs no schema change.

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
