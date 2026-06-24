--
-- Script run when module is reloaded. Whatever is the Dolibarr version.
--

ALTER TABLE llx_einvoicing_document ADD COLUMN xml_data MEDIUMTEXT DEFAULT null COMMENT 'Full XML invoice data';
