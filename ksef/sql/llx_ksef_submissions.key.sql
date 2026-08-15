-- Copyright (C) 2025 InPoint Automation Sp z o.o.
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


-- Indexes
CREATE INDEX idx_ksef_submissions_fk_facture ON llx_ksef_submissions (fk_facture);
CREATE INDEX idx_ksef_submissions_status ON llx_ksef_submissions (status);
CREATE INDEX idx_ksef_submissions_environment ON llx_ksef_submissions (environment);
CREATE INDEX idx_ksef_submissions_ksef_number ON llx_ksef_submissions (ksef_number);
CREATE INDEX idx_ksef_submissions_ksef_reference ON llx_ksef_submissions (ksef_reference);
CREATE INDEX idx_ksef_submissions_date_submission ON llx_ksef_submissions (date_submission);
CREATE INDEX idx_ksef_submissions_auth_method ON llx_ksef_submissions (auth_method);
CREATE INDEX idx_ksef_submissions_certificate_serial ON llx_ksef_submissions (certificate_serial);
CREATE INDEX idx_ksef_submissions_fk_user_submit ON llx_ksef_submissions (fk_user_submit);
CREATE INDEX idx_ksef_submissions_fk_user_last_update ON llx_ksef_submissions (fk_user_last_update);
CREATE INDEX idx_ksef_submissions_error_code ON llx_ksef_submissions (error_code);
CREATE INDEX idx_ksef_submissions_status_date ON llx_ksef_submissions (status, date_submission);
CREATE INDEX idx_ksef_submissions_invoice_type ON llx_ksef_submissions (invoice_type);
CREATE INDEX idx_ksef_submissions_offline_mode ON llx_ksef_submissions (offline_mode);
CREATE INDEX idx_ksef_submissions_offline_deadline ON llx_ksef_submissions (offline_deadline);
CREATE INDEX idx_ksef_submissions_fa3_creation_date ON llx_ksef_submissions (fa3_creation_date);

-- Foreign Keys
ALTER TABLE llx_ksef_submissions
    ADD CONSTRAINT fk_ksef_submissions_fk_facture FOREIGN KEY (fk_facture) REFERENCES llx_facture (rowid) ON DELETE CASCADE;
ALTER TABLE llx_ksef_submissions
    ADD CONSTRAINT fk_ksef_submissions_fk_user_submit FOREIGN KEY (fk_user_submit) REFERENCES llx_user (rowid) ON DELETE SET NULL;
ALTER TABLE llx_ksef_submissions
    ADD CONSTRAINT fk_ksef_submissions_fk_user_last_update FOREIGN KEY (fk_user_last_update) REFERENCES llx_user (rowid) ON DELETE SET NULL;