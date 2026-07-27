# CHANGELOG MODULE EINVOICING FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.0.3

FIX: The triggers no longer fail with "Class EInvoicing / PDPProviderManager not found" when the action
comes from a context that never went through the module screens (cron, CLI, REST API, bank import).

NEW: When the e-invoicing platform (PDP/PA) confirms the refusal of a received supplier invoice,
the corresponding Dolibarr supplier invoice is automatically validated then abandoned (with a
dedicated close code, keeping the refusal and its reason as trace) and is excluded from the
accountancy transfer screen (issue #286).

FIX: The document status code (MDT-88) of a lifecycle message now follows the lifecycle status it goes
with (deposited, received, made available, taken over, approved, paid), instead of always announcing
"in process", and the referenced invoice date is sent as a plain date, as in the XP Z12-012 examples.

## 1.0.0

Initial version
