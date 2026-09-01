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

-- Every read of the table selects the routing of one thirdparty, of one type, active or not
-- (EInvoicing::fetchDefaultRouting, EInvoicing::fetchAllRoutings), and the thirdparty list of
-- Dolibarr runs that read as a correlated sub query, once per row displayed.
ALTER TABLE llx_einvoicing_routing ADD INDEX idx_einvoicing_routing_soc (fk_soc, routing_type, active);
