--
-- Script run when module is reloaded. Whatever is the Dolibarr version.
--
-- Keep the correlation id sent to the Access Point (Request-Id header) next to the call it belongs
-- to, so a support request can be matched with the platform logs afterwards.
--

ALTER TABLE llx_einvoicing_call ADD COLUMN request_id varchar(36) AFTER endpoint;
