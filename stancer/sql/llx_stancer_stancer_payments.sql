-- Copyright (C) 2023 Eric Seigne <eric.seigne@cap-rel.fr>
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


CREATE TABLE llx_stancer_stancer_payments(
	-- BEGIN MODULEBUILDER FIELDS
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL, 
	stancer_id varchar(30) NOT NULL, 
	amount integer DEFAULT NULL, 
	fee integer DEFAULT NULL, 
	currency varchar(4), 
	description varchar(64), 
	order_id varchar(36), 
	unique_id varchar(36) UNIQUE,
	grouped_invoice_ids text DEFAULT NULL,
	partial_payment integer DEFAULT 0,
	method varchar(4),
	card varchar(30), 
	sepa varchar(30), 
	customer varchar(30), 
	refunds text, 
	response varchar(4), 
	capture boolean, 
	created datetime, 
	date_bank timestamp, 
	live_mode boolean, 
	fk_soc integer, 
	date_creation datetime NOT NULL, 
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, 
	fk_user_creat integer, 
	fk_user_modif integer, 
	status integer NOT NULL,
	entity INTEGER DEFAULT 1 NOT NULL
	-- END MODULEBUILDER FIELDS
) ENGINE=innodb;
