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


CREATE TABLE llx_stancer_stancer_payouts(
	-- BEGIN MODULEBUILDER FIELDS
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL, 
	payout_id varchar(30) NOT NULL, 
	amount integer DEFAULT NULL, 
	currency varchar(4), 
	date_bank datetime DEFAULT NULL,
	date_paym datetime DEFAULT NULL, 
	details text, 
	fees integer DEFAULT NULL,
	fees_vat integer DEFAULT NULL,
	amount_net integer DEFAULT NULL,
	payments text, 
	refunds text, 
	disputes text, 
	statement_description text, 
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, 
	status integer NOT NULL,
	entity INTEGER DEFAULT 1 NOT NULL
	-- END MODULEBUILDER FIELDS
) ENGINE=innodb;
