--
-- Migration script: rename module from pdpconnectfr to einvoicing.
-- Run this script AFTER disabling the pdpconnectfr module and BEFORE enabling einvoicing.
--

-- Rename tables
RENAME TABLE llx_pdpconnectfr_call TO llx_einvoicing_call;
RENAME TABLE llx_pdpconnectfr_document TO llx_einvoicing_document;
RENAME TABLE llx_pdpconnectfr_extlinks TO llx_einvoicing_extlinks;
RENAME TABLE llx_pdpconnectfr_lifecycle_msg TO llx_einvoicing_lifecycle_msg;
RENAME TABLE llx_pdpconnectfr_routing TO llx_einvoicing_routing;

-- Rename module activation constant
UPDATE llx_const SET name = 'MAIN_MODULE_EINVOICING' WHERE name = 'MAIN_MODULE_PDPCONNECTFR';

-- Rename all module config constants (PDPCONNECTFR_* -> EINVOICING_*)
UPDATE llx_const SET name = REPLACE(name, 'PDPCONNECTFR_', 'EINVOICING_') WHERE name LIKE 'PDPCONNECTFR_%';
UPDATE llx_const SET name = REPLACE(name, 'PDPCONNTECTFR_', 'EINVOICING_') WHERE name LIKE 'PDPCONNTECTFR_%';

-- Rename module-level admin constant (e.g. MODULE_PDPCONNECTFR_DISABLED -> MODULE_EINVOICING_DISABLED)
UPDATE llx_const SET name = REPLACE(name, 'MODULE_PDPCONNECTFR_', 'MODULE_EINVOICING_') WHERE name LIKE 'MODULE_PDPCONNECTFR_%';

-- Update rights definitions
UPDATE llx_rights_def SET module = 'einvoicing' WHERE module = 'pdpconnectfr';

-- Update menu entries
UPDATE llx_menu SET module = 'einvoicing' WHERE module = 'pdpconnectfr';
UPDATE llx_menu SET mainmenu = 'einvoicing' WHERE mainmenu = 'pdpconnectfr';
UPDATE llx_menu SET leftmenu = REPLACE(leftmenu, 'pdpconnectfr', 'einvoicing') WHERE leftmenu LIKE '%pdpconnectfr%';

UPDATE llx_const SET value = REPLACE(name, 'pdpconnectfr_', 'einvoicing_') WHERE name LIKE 'EINVOICING_SUPERPDP_VIAPARTNER_OAUTH_URL' and name like '%pdpconnectfr_%';
