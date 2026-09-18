--
-- Script run when module is reloaded. Whatever is the Dolibarr version.
--

-- RoleCodes of the CDAR ExchangedDocument/RecipientTradeParty the status was addressed to, comma
-- separated ("SE", "SE,BY"). Tells a rejection posted at emission from one posted at reception,
-- which no other field of a 213 carries (issue #973). Empty for a status this module sent.
ALTER TABLE llx_einvoicing_lifecycle_msg ADD COLUMN lc_recipient_roles varchar(50) NULL AFTER lc_reason_code;
