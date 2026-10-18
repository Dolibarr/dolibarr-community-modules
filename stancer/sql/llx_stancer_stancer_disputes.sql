-- Copyright (C) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
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


CREATE TABLE llx_stancer_stancer_disputes(
	-- BEGIN MODULEBUILDER FIELDS
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	dispute_id varchar(30) NOT NULL,
	payment_id varchar(30) NOT NULL,
	amount integer DEFAULT NULL,
	fee integer DEFAULT NULL,
	currency varchar(4),
	dispute_type varchar(30),
	status varchar(30) NOT NULL,
	response varchar(100),
	order_id varchar(100),
	created datetime,
	date_bank timestamp NULL DEFAULT NULL,
	live_mode boolean,
	fk_soc integer,
	date_creation datetime NOT NULL,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer,
	fk_user_modif integer,
	entity INTEGER DEFAULT 1 NOT NULL
	-- END MODULEBUILDER FIELDS
) ENGINE=innodb;
