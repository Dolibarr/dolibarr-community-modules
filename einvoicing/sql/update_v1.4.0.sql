--
-- Script run when module is reloaded. Whatever is the Dolibarr version.
--

-- A received document attached to a supplier invoice keyed in by hand left it without the link its card
-- reads to offer Approved / Refused. One link per invoice and provider, from its oldest flow; idempotent.
INSERT INTO llx_einvoicing_extlinks (element_id, element_type, provider, date_creation, fk_user_creat, syncstatus, flow_id)
SELECT d.fk_element_id, 'invoice_supplier', REPLACE(d.provider, 'ViaPartner', ''), d.date_creation, d.fk_user_creat, 0, d.flow_id
FROM llx_einvoicing_document as d
INNER JOIN (SELECT MIN(rowid) as rowid FROM llx_einvoicing_document WHERE fk_element_type = 'invoice_supplier' AND flow_type = 'SupplierInvoice' AND flow_direction = 'In' GROUP BY fk_element_id, REPLACE(provider, 'ViaPartner', '')) as oldest ON oldest.rowid = d.rowid
INNER JOIN llx_facture_fourn as f ON f.rowid = d.fk_element_id
LEFT JOIN llx_einvoicing_extlinks as e ON e.element_type = 'invoice_supplier' AND e.element_id = d.fk_element_id AND e.provider = REPLACE(d.provider, 'ViaPartner', '')
WHERE e.rowid IS NULL;
