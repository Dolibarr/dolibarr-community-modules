--
-- Script run when module is reloaded. Whatever is the Dolibarr version.
--

UPDATE llx_einvoicing_document SET fk_element_type = 'facture' WHERE fk_element_type = 'Facture';
UPDATE llx_einvoicing_document SET fk_element_type = 'invoice_supplier' WHERE fk_element_type = 'FactureFournisseur';

UPDATE llx_einvoicing_extlinks SET element_type = 'facture' WHERE element_type = 'Facture';
UPDATE llx_einvoicing_extlinks SET element_type = 'invoice_supplier' WHERE element_type = 'FactureFournisseur';