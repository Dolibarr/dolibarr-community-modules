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


CREATE TABLE llx_ksef_incoming
(
    rowid                       INTEGER AUTO_INCREMENT PRIMARY KEY NOT NULL,

-- KSeF Identification
    ksef_number                 VARCHAR(50) NOT NULL,

-- Seller (Podmiot1)
    seller_nip                  VARCHAR(20),
    seller_vat_id               VARCHAR(50),
    seller_name                 VARCHAR(255),
    seller_country              VARCHAR(2) DEFAULT 'PL',
    seller_address              TEXT,

-- Buyer (Podmiot2)
    buyer_nip                   VARCHAR(20),
    buyer_name                  VARCHAR(255),

-- Invoice Header
    invoice_number              VARCHAR(100),
    invoice_type                VARCHAR(10),
    invoice_date                INTEGER,
    sale_date                   INTEGER,
    currency                    VARCHAR(3) DEFAULT 'PLN',

-- Totals
    total_net                   DOUBLE(24,8),
    total_vat                   DOUBLE(24,8),
    total_gross                 DOUBLE(24,8),

-- VAT Breakdown (JSON)
    vat_summary                 MEDIUMTEXT,

-- Line Items (JSON)
    line_items                  MEDIUMTEXT,

-- Payment Info
    payment_due_date            INTEGER,
    payment_method              VARCHAR(10),
    bank_account                VARCHAR(50),
    payment_status              VARCHAR(20),
    payment_date                INTEGER,

-- Correction Reference
    corrected_ksef_number       VARCHAR(50),
    corrected_invoice_number    VARCHAR(100),
    corrected_invoice_date      INTEGER,
    correction_data             MEDIUMTEXT,

-- XML Data
    fa3_xml                     MEDIUMTEXT,

-- Metadata
    fa3_creation_date           INTEGER,
    fa3_system_info             VARCHAR(255),

-- Fetch Metadata
    fetch_date                  INTEGER NOT NULL,
    environment                 VARCHAR(20) DEFAULT 'TEST',

-- Dolibarr
    fk_facture_fourn            INTEGER DEFAULT NULL,
    fk_credit_note              INTEGER DEFAULT NULL,
    import_status               VARCHAR(20) DEFAULT 'NEW',
    import_date                 INTEGER,
    import_error                TEXT,
    entity                      INTEGER DEFAULT 1

) ENGINE=InnoDB DEFAULT CHARSET=utf8;