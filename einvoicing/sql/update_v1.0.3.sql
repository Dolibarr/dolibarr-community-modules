--
-- Script run when module is reloaded. Whatever is the Dolibarr version.
--

UPDATE llx_einvoicing_document SET fk_element_type = 'facture' WHERE fk_element_type = 'Facture';
UPDATE llx_einvoicing_document SET fk_element_type = 'invoice_supplier' WHERE fk_element_type = 'FactureFournisseur';

UPDATE llx_einvoicing_extlinks SET element_type = 'facture' WHERE element_type = 'Facture';
UPDATE llx_einvoicing_extlinks SET element_type = 'invoice_supplier' WHERE element_type = 'FactureFournisseur';

-- Issue #259: llx_einvoicing_call.entity was created as varchar(50) but is queried as an integer
-- (WHERE entity IN (n)), which breaks on PostgreSQL. Normalize it to integer like
-- llx_einvoicing_document.entity. Done in place to preserve existing data and indexes.
-- One of the two statements below applies per database driver, the other is a harmless no-op:
--   - MySQL/MariaDB accept the plain MODIFY and cast varchar -> int implicitly.
--   - PostgreSQL (via Dolibarr's SQL converter) needs the explicit USING cast.
-- VMYSQL4.3 ALTER TABLE llx_einvoicing_call MODIFY COLUMN entity integer DEFAULT 1;
-- VPGSQL8.2 ALTER TABLE llx_einvoicing_call MODIFY COLUMN entity integer USING (entity::integer);
ALTER TABLE llx_einvoicing_call ALTER COLUMN entity SET DEFAULT 1;
ALTER TABLE llx_einvoicing_extlinks ADD COLUMN ap_precheck_status varchar(50) DEFAULT NULL;
ALTER TABLE llx_einvoicing_extlinks ADD COLUMN ap_precheck_result text DEFAULT NULL;
