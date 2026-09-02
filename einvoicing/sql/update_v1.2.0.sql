--
-- Script run when module is reloaded. Whatever is the Dolibarr version.
--

ALTER TABLE llx_einvoicing_extlinks ADD INDEX idx_einvoicing_extlinks_element (element_type, element_id);

ALTER TABLE llx_einvoicing_extlinks ADD INDEX idx_einvoicing_extlinks_syncref (element_type, syncref);

ALTER TABLE llx_einvoicing_extlinks ADD INDEX idx_einvoicing_extlinks_flowid (flow_id);

ALTER TABLE llx_einvoicing_lifecycle_msg ADD INDEX idx_einvoicing_lifecycle_msg_element (element_type, element_id, lc_validation_status);

ALTER TABLE llx_einvoicing_lifecycle_msg ADD INDEX idx_einvoicing_lifecycle_msg_flowid (flow_id);

ALTER TABLE llx_einvoicing_routing ADD INDEX idx_einvoicing_routing_soc (fk_soc, routing_type, active);
