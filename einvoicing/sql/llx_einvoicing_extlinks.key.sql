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

-- The table is read by (element_type, element_id) on every card and, above all, it is joined to the
-- invoice, supplier invoice and thirdparty lists of Dolibarr: without this index the whole table is
-- re-scanned for each block of rows of the list, including for the record count of the page.
ALTER TABLE llx_einvoicing_extlinks ADD INDEX idx_einvoicing_extlinks_element (element_type, element_id);

-- Lookup by reference, used when the id of the element is not known (EInvoicing::fetchLastknownInvoiceStatus)
ALTER TABLE llx_einvoicing_extlinks ADD INDEX idx_einvoicing_extlinks_syncref (element_type, syncref);

-- Lookup by flow, used when a message of the Access Point carries only the flow identifier
ALTER TABLE llx_einvoicing_extlinks ADD INDEX idx_einvoicing_extlinks_flowid (flow_id);
