-- Copyright (C) 2026 InPoint Automation Sp z o.o.
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU Affero General Public License as
-- published by the Free Software Foundation, either version 3 of the
-- License, or (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU Affero General Public License for more details.
--
-- You should have received a copy of the GNU Affero General Public License
-- along with this program.  If not, see <https://www.gnu.org/licenses/>.


-- Unique Constraints
ALTER TABLE llx_ksef_incoming ADD UNIQUE INDEX uk_ksef_incoming_ksef_number (ksef_number);

-- Indexes
CREATE INDEX idx_ksef_incoming_seller_nip ON llx_ksef_incoming (seller_nip);
CREATE INDEX idx_ksef_incoming_seller_invoice ON llx_ksef_incoming (seller_nip, invoice_number);
CREATE INDEX idx_ksef_incoming_invoice_date ON llx_ksef_incoming (invoice_date);
CREATE INDEX idx_ksef_incoming_fetch_date ON llx_ksef_incoming (fetch_date);
CREATE INDEX idx_ksef_incoming_import_status ON llx_ksef_incoming (import_status);
CREATE INDEX idx_ksef_incoming_environment ON llx_ksef_incoming (environment);
CREATE INDEX idx_ksef_incoming_entity ON llx_ksef_incoming (entity);