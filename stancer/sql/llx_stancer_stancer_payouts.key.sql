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


-- BEGIN MODULEBUILDER INDEXES
ALTER TABLE llx_stancer_stancer_payouts ADD INDEX idx_stancer_stancer_payouts_rowid (rowid);
ALTER TABLE llx_stancer_stancer_payouts ADD INDEX idx_stancer_stancer_payouts_payout_id (payout_id);
ALTER TABLE llx_stancer_stancer_payouts ADD INDEX idx_stancer_stancer_payouts_status (status);
-- END MODULEBUILDER INDEXES

--ALTER TABLE llx_stancer_stancer_payouts ADD UNIQUE INDEX uk_stancer_stancer_payouts_fieldxy(fieldx, fieldy);

--ALTER TABLE llx_stancer_stancer_payouts ADD CONSTRAINT llx_stancer_stancer_payouts_fk_field FOREIGN KEY (fk_field) REFERENCES llx_stancer_myotherobject(rowid);

