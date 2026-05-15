--
-- Migration de schéma pour installations existantes du module pdpconnectfr.
-- Ce fichier est chargé automatiquement par _load_tables() lors de l'activation du module.
-- Les erreurs "colonne déjà existante" sont silencieusement ignorées par run_sql().
--

ALTER TABLE llx_pdpconnectfr_call ADD COLUMN batchlimit integer NOT NULL DEFAULT 1;
ALTER TABLE llx_pdpconnectfr_call MODIFY COLUMN totalflow integer NULL DEFAULT NULL;

ALTER TABLE llx_pdpconnectfr_document ADD COLUMN response_for_debug text;
ALTER TABLE llx_pdpconnectfr_document MODIFY COLUMN flow_type varchar(64);
UPDATE llx_pdpconnectfr_document SET flow_type = 'sync' WHERE flow_type IS NULL;

ALTER TABLE llx_pdpconnectfr_routing ADD COLUMN routing_type varchar(12) NOT NULL DEFAULT 'thirdparty';

ALTER TABLE llx_pdpconnectfr_extlinks ADD COLUMN override_routing_id varchar(255) NULL DEFAULT NULL;
