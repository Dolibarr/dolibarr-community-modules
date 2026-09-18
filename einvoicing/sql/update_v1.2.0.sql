--
-- Script run when module is reloaded. Whatever is the Dolibarr version.
--

ALTER TABLE llx_einvoicing_extlinks ADD INDEX idx_einvoicing_extlinks_element (element_type, element_id);

ALTER TABLE llx_einvoicing_extlinks ADD INDEX idx_einvoicing_extlinks_syncref (element_type, syncref);

ALTER TABLE llx_einvoicing_extlinks ADD INDEX idx_einvoicing_extlinks_flowid (flow_id);

ALTER TABLE llx_einvoicing_lifecycle_msg ADD INDEX idx_einvoicing_lifecycle_msg_element (element_type, element_id, lc_validation_status);

ALTER TABLE llx_einvoicing_lifecycle_msg ADD INDEX idx_einvoicing_lifecycle_msg_flowid (flow_id);

ALTER TABLE llx_einvoicing_routing ADD INDEX idx_einvoicing_routing_soc (fk_soc, routing_type, active);

ALTER TABLE llx_einvoicing_document ADD COLUMN processing_rule varchar(50) AFTER flow_profile;

-- Backlog of the flows the access point still holds (see llx_einvoicing_postponed.sql).
CREATE TABLE llx_einvoicing_postponed (rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL, flow_id varchar(255) NOT NULL, provider varchar(50) NOT NULL, call_id varchar(50), entity integer DEFAULT 1, reason_code varchar(64), reason_message text, business_message text, action_html text, action_data text, fk_soc integer, document_ref varchar(255), nb_attempts integer NOT NULL DEFAULT 1, date_creation datetime NOT NULL, date_last_attempt datetime NOT NULL, tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, fk_user_creat integer NOT NULL, fk_user_modif integer) ENGINE = InnoDB;

ALTER TABLE llx_einvoicing_postponed ADD UNIQUE INDEX uk_einvoicing_postponed_flow (flow_id, entity);

ALTER TABLE llx_einvoicing_postponed ADD INDEX idx_einvoicing_postponed_entity_date (entity, date_creation);
