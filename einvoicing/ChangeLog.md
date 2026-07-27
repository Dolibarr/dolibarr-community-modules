# CHANGELOG MODULE EINVOICING FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.0.3

FIX: The flowProfile declared to the platform is now read from the guideline URN of the document
being transmitted instead of being hardcoded, so the declaration and the file can no longer
contradict each other. A profile with no AFNOR flowProfile omits the field (issue #395).

FIX: A lifecycle message sent on a supplier invoice is now addressed (MDT-73) to the routing ID
recorded for its vendor - the same address the module already uses to reach that third party - instead
of its SIREN, which the platform only accepts when the vendor happens to be registered under it
("L'adresse électronique (MDT-73) est invalide" otherwise). Vendors with no routing recorded keep the
SIREN, as before (issue #410).

FIX: The triggers no longer fail with "Class EInvoicing / PDPProviderManager not found" when the action
comes from a context that never went through the module screens (cron, CLI, REST API, bank import).

FIX: A discount on an invoice line no longer emits a ram:CategoryTradeTax inside its
allowance block. That element belongs to a document-level allowance or charge and the CII syntax
binding forbids it on a line (CII-SR-191); the VAT of the discount is already carried by the line
itself (issue #471).

FIX: The local check of the SIREN format now covers every party of the generated document instead of
the seller alone. A customer whose professional id was not a 9 digit SIREN went through untouched and
the invoice was refused by the platform (BR-FR-32), without the operator being told which party was at
fault (issue #473).

FIX: A French thirdparty recorded with a SIRET but no SIREN was identified in the generated invoice by
the 5 digit establishment number instead of its SIREN, an identifier the platform refuses (BR-FR-32).

NEW: The generated invoices now tell when the VAT falls due, hence from when the buyer may deduct it:
BT-8 is set to 72 (payment date) as soon as the invoice carries a service, and to 5 (invoice date) for a
seller who opted for the "TVA d'après les débits" scheme (EINVOICING_VAT_ON_DEBITS), whose legal mention
is also added to the invoice as a TXD note. A goods-only invoice sends nothing: its VAT falls due on the
delivery it already dates.
FIX: The generated CII and Factur-X XML now carries the EXTENDED-CTC-FR guideline URN
(`urn:cen.eu:en16931:2017#conformant#urn.cpro.gouv.fr:1p0:extended-ctc-fr`) instead of the generic
EN16931 / Factur-X EXTENDED one, so it matches the `flowProfile` the module declares to the
platform. As a side effect a credit note now carries the type and the issue date of the invoice
it corrects (BT-3 / BT-26), which fixes a BR-FR-CO-05 validation failure (issue #395).

NEW: The profile used to build the XML can be changed with EINVOICING_XML_PROFILE, which accepts
MINIMUM, BASICWL, BASIC, EN16931, EXTENDED and EXTENDEDFR. The default does not change (EN16931 for
CII, EXTENDED for Factur-X); setting EXTENDEDFR switches the whole document to EXTENDED-CTC-FR, the
profile the French mandate expects, including on the Factur-X path whose PDF/A-3 attachment step
used to refuse that guideline (issue #395).

FIX: A credit note now carries the issue date of the invoice it corrects (BT-26), which was only
emitted on the EXTENDED profile and is required by BR-FR-CO-05 on every profile.

NEW: When the e-invoicing platform (PDP/PA) confirms the refusal of a received supplier invoice,
the corresponding Dolibarr supplier invoice is automatically validated then abandoned (with a
dedicated close code, keeping the refusal and its reason as trace) and is excluded from the
accountancy transfer screen (issue #286).

FIX: The document status code (MDT-88) of a lifecycle message now follows the lifecycle status it goes
with (deposited, received, made available, taken over, approved, paid), instead of always announcing
"in process", and the referenced invoice date is sent as a plain date, as in the XP Z12-012 examples.

## 1.0.0

Initial version
