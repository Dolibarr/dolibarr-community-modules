-- Copyright (C) 2025		SuperAdmin					<daoud.mouhamed@gmail.com>
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
ALTER TABLE llx_einvoicing_call ADD UNIQUE INDEX uk_einvoicing_call_callid (call_id, entity);
ALTER TABLE llx_einvoicing_call ADD INDEX idx_einvoicing_call_date_creation (date_creation);
ALTER TABLE llx_einvoicing_call ADD INDEX idx_einvoicing_call_status (status);
-- END MODULEBUILDER INDEXES
