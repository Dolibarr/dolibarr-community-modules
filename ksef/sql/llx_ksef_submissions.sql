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


CREATE TABLE llx_ksef_submissions
(
    rowid                  INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,
    fk_facture             INTEGER                        NOT NULL,

-- KSeF Identification
    ksef_reference         VARCHAR(100),
    ksef_number            VARCHAR(100),
    invoice_hash           VARCHAR(255) DEFAULT NULL,

-- Status & Environment
    status                 VARCHAR(20)  DEFAULT 'PENDING' NOT NULL,
    environment            VARCHAR(20)  DEFAULT 'TEST'    NOT NULL,

-- XML Data
    fa3_xml                MEDIUMTEXT,
    fa3_creation_date      INTEGER      DEFAULT NULL,
    upo_xml                MEDIUMTEXT,
    api_response           TEXT,

-- Dates (Integer Timestamps)
    date_submission        INTEGER      DEFAULT NULL,
    date_acceptance        INTEGER      DEFAULT NULL,
    date_last_check        INTEGER      DEFAULT NULL,

-- Error Handling
    error_message          TEXT,
    error_code             INTEGER      DEFAULT NULL,
    error_details          TEXT         DEFAULT NULL,
    retry_count            INTEGER      DEFAULT 0,

-- Auth & Certificates
    auth_method            VARCHAR(50)  DEFAULT NULL,
    certificate_serial     VARCHAR(100) DEFAULT NULL,
    certificate_valid_from DATETIME     DEFAULT NULL,
    certificate_valid_to   DATETIME     DEFAULT NULL,

-- Users & Tracking
    fk_user_submit         INTEGER,
    fk_user_last_update    INTEGER      DEFAULT NULL,
    tms                    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    import_key             VARCHAR(14),

-- Invoice Type
    invoice_type           VARCHAR(10)  DEFAULT NULL,

-- Offline Mode
    offline_mode           VARCHAR(20)  DEFAULT NULL,
    offline_deadline       INTEGER      DEFAULT NULL,
    offline_detected_reason VARCHAR(255) DEFAULT NULL,
    original_invoice_hash  VARCHAR(255) DEFAULT NULL

) ENGINE=InnoDB DEFAULT CHARSET=utf8;