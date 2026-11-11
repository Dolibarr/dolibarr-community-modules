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


-- BEGIN MODULEBUILDER INDEXES
CREATE INDEX idx_stancer_stancer_disputes_rowid ON llx_stancer_stancer_disputes (rowid);
CREATE INDEX idx_stancer_stancer_disputes_dispute_id ON llx_stancer_stancer_disputes (dispute_id);
CREATE INDEX idx_stancer_stancer_disputes_payment_id ON llx_stancer_stancer_disputes (payment_id);
CREATE INDEX idx_stancer_stancer_disputes_status ON llx_stancer_stancer_disputes (status);
CREATE INDEX idx_stancer_stancer_disputes_fk_soc ON llx_stancer_stancer_disputes (fk_soc);
ALTER TABLE llx_stancer_stancer_disputes ADD CONSTRAINT llx_stancer_stancer_disputes_fk_user_creat FOREIGN KEY (fk_user_creat) REFERENCES llx_user(rowid) ON DELETE SET NULL;
-- END MODULEBUILDER INDEXES
CREATE UNIQUE INDEX uk_stancer_stancer_disputes_dispute_id ON llx_stancer_stancer_disputes (entity, dispute_id);
