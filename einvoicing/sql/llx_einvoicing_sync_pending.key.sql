-- Copyright (C) 2026		Jose Martinez					<jose.martinez@pichinov.com>
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

-- One row per flow and per provider: the queue is upserted by (entity, provider, flow_id).
ALTER TABLE llx_einvoicing_sync_pending ADD UNIQUE INDEX uk_einvoicing_sync_pending_flow (entity, provider, flow_id);

-- The pending list is filtered on status (pending first) and ordered by the flow update date.
ALTER TABLE llx_einvoicing_sync_pending ADD INDEX idx_einvoicing_sync_pending_status (entity, status, flow_updatedat);
