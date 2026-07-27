# CHANGELOG MODULE EINVOICING FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.0.3

FIX: The triggers no longer fail with "Class EInvoicing / PDPProviderManager not found" when the action
comes from a context that never went through the module screens (cron, CLI, REST API, bank import).

NEW: The "Payment transmitted" (211) status can now be sent automatically to tell the vendor that a
supplier invoice received through the platform has been paid, as soon as Dolibarr classifies it as paid.
It carries the amount paid and its date, and is sent once per invoice. Optional status of the reform, so
it is off by default and enabled with EINVOICING_SEND_PAYMENT_SENT_STATUS. It can also be sent by hand
from the supplier invoice card, where it was missing from the list of sendable statuses.

FIX: A lifecycle message sent on a supplier invoice is now addressed (MDT-73) to where its vendor
exchanges from, instead of its SIREN which the platform only accepts when the vendor happens to be
registered under it ("L'adresse électronique (MDT-73) est invalide" otherwise). In order: the routing
recorded for the vendor in Dolibarr, then the electronic address (BT-34) carried by the e-invoice it
sent us, then the address it declares in the platform directory, and only then the SIREN, saying so in
the log.

FIX: The document status code (MDT-88) of a lifecycle message now follows the lifecycle status it goes
with (deposited, received, made available, taken over, approved, paid), instead of always announcing
"in process", and the referenced invoice date is sent as a plain date, as in the XP Z12-012 examples.

FIX: The "Cashed in" (212) status is now issued as the seller of the invoice and addressed to its
buyer, instead of reusing the supplier invoice mapping (us as the buyer, addressed to ourselves) which
made the platform answer "no matching invoices found". Its referenced document status code (47, Paid)
and issue date format follow the XP Z12-012 reference example as well.

FIX: The "Cashed in" (212) status sent to the platform now carries the cashed amount broken down by
VAT rate (MDG-43 blocks with MDT-207 = MEN, MDT-215 amount and MDT-224 rate) and the status detail
sequence number, as required by the rules BR-FR-CDV-14 and BR-FR-CDV-16. Without them the platform
rejected the CDAR with a HTTP 400.

NEW: The cash-in is now reported on every customer payment instead of once when the invoice becomes
fully paid, so partial payments are reported too (each one with its own amount), as the reform
expects. Two new options frame the scope: EINVOICING_VAT_ON_DEBITS (seller who opted for the "TVA
d'après les débits" scheme: no payment data to report at all) and EINVOICING_PAID_STATUS_SERVICES_ONLY
(restrict the status to the operations whose VAT is due on collection: services and down payments).

NEW: When the e-invoicing platform (PDP/PA) confirms the refusal of a received supplier invoice,
the corresponding Dolibarr supplier invoice is automatically validated then abandoned (with a
dedicated close code, keeping the refusal and its reason as trace) and is excluded from the
accountancy transfer screen (issue #286).

## 1.0.0

Initial version
