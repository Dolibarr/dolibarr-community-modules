-- Copyright (C) 2026 Pierre Grasswill <da.grumpf@gmail.com>
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

-- One row per backup, restore or maintenance operation of the CloudBackup module
CREATE TABLE llx_cloudbackup_run(
	rowid			integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity			integer DEFAULT 1 NOT NULL,
	action			varchar(16) NOT NULL,
	origin			varchar(16),
	format			varchar(16),
	storage			varchar(16),
	status			smallint DEFAULT 0 NOT NULL,
	date_start		datetime NOT NULL,
	date_end		datetime,
	date_alive		datetime,
	snapshot		varchar(128),
	nb_files		integer,
	bytes_total		bigint,
	bytes_added		bigint,
	message			text,
	fk_user			integer
) ENGINE=innodb;
