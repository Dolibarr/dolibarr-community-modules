-- Copyright (C) 2026		Pierre Grasswill				<da.grumpf@gmail.com>
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

-- Two indexes cover every read of the table, and there is no third one because no query needs it.

-- The synchronization writes by flow_id, on every run that meets the flow again, and drops the row
-- by flow_id when it finally imports. The unique key serves both, and is what makes the write an
-- upsert: one row per flow, whatever the number of runs.
ALTER TABLE llx_einvoicing_postponed ADD UNIQUE INDEX uk_einvoicing_postponed_flow (flow_id, entity);

-- The panel reads the backlog of the entity, oldest first, and counts it. Ordering on an indexed
-- column is what keeps that read from sorting the table on every page view.
ALTER TABLE llx_einvoicing_postponed ADD INDEX idx_einvoicing_postponed_entity_date (entity, date_creation);
