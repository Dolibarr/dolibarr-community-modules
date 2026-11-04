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
CREATE INDEX idx_stancer_stancer_payments_rowid ON llx_stancer_stancer_payments (rowid);
CREATE INDEX idx_stancer_stancer_payments_stancer_id ON llx_stancer_stancer_payments (stancer_id);
CREATE INDEX idx_stancer_stancer_payments_fk_soc ON llx_stancer_stancer_payments (fk_soc);
ALTER TABLE llx_stancer_stancer_payments ADD CONSTRAINT llx_stancer_stancer_payments_fk_user_creat FOREIGN KEY (fk_user_creat) REFERENCES llx_user(rowid) ON DELETE SET NULL;
CREATE INDEX idx_stancer_stancer_payments_status ON llx_stancer_stancer_payments (status);
-- END MODULEBUILDER INDEXES

-- E3: enforce payment identity uniqueness (per entity) so two concurrent
-- refresh/paymentback runs cannot both insert the same Stancer payment.
CREATE UNIQUE INDEX uk_stancer_stancer_payments_stancer_id ON llx_stancer_stancer_payments (entity, stancer_id);

-- order_id holds the ref of the source document. It is a lookup key of the
-- module: Stancer_payments::fetch() can search on it, the payment list offers a
-- search_order_id filter, and the "a payment is already running" guard of the
-- invoice card (Stancer_payments::fetchAllRunningForInvoice()) matches it on
-- every display of a validated invoice. It had no index. Not unique: retries and
-- same-day grouped SEPA debits legitimately share one value.
CREATE INDEX idx_stancer_stancer_payments_order_id ON llx_stancer_stancer_payments (order_id);

--ALTER TABLE llx_stancer_stancer_payments ADD CONSTRAINT llx_stancer_stancer_payments_fk_field FOREIGN KEY (fk_field) REFERENCES llx_stancer_myotherobject(rowid);

