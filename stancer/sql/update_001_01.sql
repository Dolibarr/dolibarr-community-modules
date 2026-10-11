--
-- Script run when an upgrade of Dolibarr is done. Whatever is the Dolibarr version.
--
-- No column placement clause: when its target column is missing, the whole ADD is dropped
-- silently (DOLIBARR_UPDATE_SQL.md). The former UPDATE of the SEPA type now
-- runs from init() of the module descriptor, update files only hold ALTER.
--

ALTER TABLE llx_societe_rib ADD stancer_object_ref VARCHAR(32) NULL DEFAULT NULL;
ALTER TABLE llx_societe_rib ADD stancer_account VARCHAR(32) NULL DEFAULT NULL;
