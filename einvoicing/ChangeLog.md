# CHANGELOG MODULE EINVOICING FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.0.4

FIX: The setup page no longer dies on a fatal error when the selected Access point cannot be
instantiated - the case of a provider disabled after being selected, "SuperPDP via partner only" being
the way it happens today, since that option disables every other entry including the one already
recorded in EINVOICING_PDP. The page read the configuration of a provider it did not have, so it
answered nothing at all and the setup could no longer be reached to select another one. It now says
which Access point is unavailable and offers the ones that remain. The actions of the provider block
(token, health check, sample invoice) are skipped in that state instead of being matched on an empty
prefix.

FIX: A synchronization no longer raises a PHP warning for every flow whose source invoice is not in
this database. Such a flow is the ordinary case on a platform account shared with another system -
the customer invoice behind it simply lives somewhere else - and syncFlow() notes it in a message
built from $document->flowId, while the document object carries flow_id. On a Dolibarr whose
CommonObject has no magic getter to absorb the miss (18) that logged "Undefined property:
Document::$flowId" once per flow; from 20 on the getter swallows it. The message itself, which the
caller discards today since the flow counts as synchronized, also lost the one identifier it exists
to carry.

NEW: A "Mapped vendor references" screen (Billing > E-invoice synchronization) lists every vendor
product reference recorded on the products, i.e. the mappings the import of a supplier invoice relies
on to find the product of a line. Until now they could only be read product by product, in the
"Supplier prices" tab of each one. A reference can be reassigned to another product or dropped
directly from the list.

FIX: The product picker of the screen mapping the products of an e-invoice no longer filters on the
sale status. A product bought from a vendor only needs to be flagged "to buy", and a product created by
a previous import never is on sale, so that screen offered an empty list of products.

FIX: The error raised when a "Standard rated" line has no seller VAT number no longer names the
customer. The test is on the seller (BR-S-02 requires the Seller VAT identifier, BT-31), but the
message read "The VAT number of the thirdparty <customer> is mandatory", so the operator went looking
for the missing number on the customer record while it was missing from their own company.
  
FIX: The documents generated on the MINIMUM and BASIC profiles now validate against their own Factur-X
schema, which neither did. BASIC was treated as a full EN 16931 document, so it carried the party
contacts (BG-6 / BG-9), the payment means label (BT-82), the account holder (BT-85) and the BIC (BT-86),
none of which its schema declares - it declares the invoice lines, which is what set it apart from
MINIMUM and BASIC WL, but not those. MINIMUM is not a reduced EN 16931 at all but a much smaller
document: no note, no identifier on the parties, no trading name, no electronic address, an address
reduced to its country, an empty delivery group, and a settlement holding only the currency and four
amounts. It is now emitted as its schema declares it. The other profiles are untouched.

FIX: A document generated as CII now carries the buyer reference (BT-10), the project reference (BT-11)
and the contract reference (BT-12), which only the Factur-X path was writing. The three were read from
the invoice, handed to the generator and silently dropped, so the same invoice produced a different
document depending on the protocol - and the CII one lost the "service exécutant" a public buyer needs
to route it. Each is emitted only on the profiles whose schema declares it: BT-10 everywhere, BT-12 from
BASIC WL up, BT-11 from EN16931 up.

FIX: A lifecycle message sent to a vendor with no routing recorded no longer falls straight back on its
SIREN, which the platform accepts only when the vendor happens to be registered under it. The status is a
reply, so it now looks for the address the vendor exchanges under, in order: the routing recorded in
Dolibarr, then the electronic address (BT-34) carried by the e-invoice it sent us, then the address the
platform directory declares for its SIREN, and only then the SIREN itself, saying in the log which one it
settled on.

FIX: Regenerating the document of an invoice already transmitted no longer sends it to the platform a
second time. Automatic transmission on generation stopped at the sync status, which generateInvoice()
resets to "generated" every time it runs, so from the second regeneration on the invoice looked as if it
had never been sent. It was, and the platform, which registers a flow under the invoice reference,
answered the duplicate with an HTTP 400 and no flow: an error raised to the operator on an invoice that
was in fact transmitted and, when the first submission was still awaiting its outcome, a second useless
call for every regeneration. The transmission now reads the flow identifier the platform assigned on the
first submission, which nothing clears, exactly like the manual send button already did - and it honours
the same EINVOICING_ALLOW_RESEND_TRANSMITTED opt-out.

FIX: A line carrying recoverable non-collected VAT ("TVA non perçue récupérable", the overseas
departments scheme of article 295 of the CGI) no longer makes the document claim a VAT the invoice does
not charge. Dolibarr makes the total including tax of such a line equal to its net amount, where the
module added the VAT on top, so the document asked the buyer for the whole rate more than the invoice.
EN 16931 offers no way to declare a VAT that is not claimed - the total with VAT is the net total plus
the VAT total (BR-CO-15) - so the line is now issued exempt (category E, rate 0, no VAT amount) with the
reason code the standard reserves for that article, VATEX-FR-CGI295 (issue #508).

NEW: The option that requires the recipient to be reachable before transmission now offers a third
choice, which blocks the doubt as well: the directory (annuaire) answered for the recipient but did
not report the status of its reception address. That status cannot be asked for - the directory
search only accepts the address identifier, SIREN, SIRET and address suffix - so on a platform that
leaves it out, an address that is open and one that only takes effect later are indistinguishable,
and the invoice used to go out and possibly come back rejected with a routing error (fr:213).
EINVOICING_REQUIRE_ROUTABLE_RECIPIENT, formerly a yes/no, now takes those three values, the first two
keeping their meaning: an instance that already required reachability is unchanged.

FIX: The amounts of a line are now asked to the core function that computed the invoice
(calcul_price_total()) instead of being computed a second time in the module. The second
implementation rounded the unit price after discount in every case, where the core only rounds it when
the instance asks for it, so a discounted line could state a few cents less than the invoice it stands
for - and an instance running a Dolibarr that does not know that option diverged by more, the module
honouring a setting its core ignores. Nothing reported it: the document stayed internally consistent
and the platform accepted it (issue #505).


## 1.0.3

FIX: The totals of the generated document now follow the rounding convention of the instance. Dolibarr
sums the amounts already rounded on each line, unless MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND (or its
_SUPPLIER variant) asks it to round the sum instead, and the invoice recorded, printed, booked and paid
then carries that second convention. The module always applied the first one, so on such an instance the
document transmitted claimed a cent less (or more) than the invoice it stands for. Nothing reported it:
the document stayed internally consistent and the tolerance BR-CO-17 allows absorbs the gap, so the
platform accepted it (issue #378).

FIX: The reference a credit note makes to the invoice it corrects now carries that invoice issue date
(BT-26) whatever the profile, and no longer only in EXTENDED. The date is allowed by EN 16931
(CII-DT-027) and required by the French rule BR-FR-CO-05, which rejects a credit note whose reference
has none - so every credit note generated with the default profile was refused by the platform. The
document type code, which EN 16931 forbids there (CII-DT-018), stays reserved to the EXTENDED and
EXTENDED-CTC-FR profiles (issue #485).

FIX: The combo used to pick the default product of a vendor, on the thirdparty card, no longer depends
on a global left behind by the calling page. select_produits_fournisseurs() reads the purchase status
it filters on from the global $status, so any page or hook setting that variable for its own purpose
could make the combo come back empty, with nothing to explain why. The purchase status is now forced
before the call and the global given back untouched.

FIX: The "Cashed in" (212) status sent to the platform now carries the cashed amount broken down by
VAT rate (MDG-43 blocks with MDT-207 = MEN, MDT-215 amount and MDT-224 rate) and the status detail
sequence number, as required by the rules BR-FR-CDV-14 and BR-FR-CDV-16. Without them the platform
rejected the CDAR with a HTTP 400. It is also issued as the seller of the invoice and addressed to its
buyer, instead of reusing the supplier invoice mapping (us as the buyer, addressed to ourselves) which
made the platform answer "no matching invoices found".

NEW: The cash-in is now reported on every customer payment instead of once when the invoice becomes
fully paid, so partial payments are reported too (each one with its own amount), as the reform
expects. Its scope is the operations whose VAT is due on collection, which the module no longer asks
for: it reads the VAT mode already held by the Tax/VAT module setup (TAX_MODE_SELL_PRODUCT and
TAX_MODE_SELL_SERVICE). The einvoicing setup page reminds the current value and links to the page
that owns it. The same source now drives the VAT point date code (BT-8) and the "TVA d'après les
débits" legal mention, which used to depend on the module option EINVOICING_VAT_ON_DEBITS. That
option is gone: an instance that had set it must make sure the VAT mode of the Tax/VAT module says
the same thing.

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

FIX: The deliver-to party (ShipToTradeParty) of the generated XML no longer repeats the buyer
contact. That party is a stripped-down copy of the buyer and the CII syntax binding forbids a
contact on it (CII-SR-312), just like the legal organization that was already left out (issue #463).

FIX: The scheduled job "EInvoicingDocumentSync" died on a fatal error ("Class PDPProviderManager not
found") as soon as it was enabled, because the scheduler only loads the class file holding the job
method. Flow synchronization could therefore only be run by hand from the interface.

FIX: The reachability precheck of the recipient in the directory (annuaire) reported "routable" as
soon as the recipient had a directory line, whatever its state. A line that is only declared and not
open yet ("Upcoming"), or closed, is no longer counted as an active reception address. When
EINVOICING_REQUIRE_ROUTABLE_RECIPIENT is enabled, such a recipient is now blocked before
transmission instead of being rejected by the platform with a routing error (fr:213).

NEW: The profile used to build the XML can be set with EINVOICING_XML_PROFILE, which accepts
MINIMUM, BASICWL, BASIC, EN16931, EXTENDED and EXTENDEDFR. The default does not change (EN16931 for
CII, EXTENDED for Factur-X). Setting EXTENDEDFR switches the document to EXTENDED-CTC-FR, the
profile the French mandate expects, including on the Factur-X path whose PDF/A-3 attachment step
used to refuse that guideline.

FIX: The seller fax number is no longer written into the generated XML. EN16931 has no business
term for a fax and the CII syntax binding forbids the element (CII-SR-236), so any instance with a
fax filled in the company setup was emitting a non-conformant document (issue #462).

NEW: When the e-invoicing platform (PDP/PA) confirms the refusal of a received supplier invoice,
the corresponding Dolibarr supplier invoice is automatically validated then abandoned (with a
dedicated close code, keeping the refusal and its reason as trace) and is excluded from the
accountancy transfer screen (issue #286).

FIX: The document status code (MDT-88) of a lifecycle message now follows the lifecycle status it goes
with (deposited, received, made available, taken over, approved, paid), instead of always announcing
"in process", and the referenced invoice date is sent as a plain date, as in the XP Z12-012 examples.

FIX: A supplier invoice line that falls back on the default product of the vendor now keeps the label and
the description carried by the XML. That product is a catch-all shared by every unresolved line, so all of
them used to show the same wording ("Misc purchases") and the text sent by the vendor was lost. Such a line
no longer registers a vendor price on the catch-all either, which used to glue the vendor reference of the
line onto it and make every next invoice match it instead of the real product.

NEW: The "Payment transmitted" (211) status can now be sent automatically to tell the vendor that a
supplier invoice received through the platform has been paid, as soon as Dolibarr classifies it as paid.
It carries the amount paid and its date, and is sent once per invoice. Optional status of the reform, so
it is off by default and enabled with EINVOICING_SEND_PAYMENT_SENT_STATUS. It can also be sent by hand
from the supplier invoice card, where it was missing from the list of sendable statuses.

NEW: The local check run at generation time now also verifies that the generated document claims the
amount the invoice claims, and says so when it does not. No rule of the standard can catch that: the
rules relate the amounts of the document to each other, so a document wrong by a cent is as consistent
as a correct one and the platform accepts it without a word. The remaining known cause is a currency
accuracy for totals (MAIN_MAX_DECIMALS_TOT) other than 2, which puts a third decimal on the invoice
where EN 16931 allows two on every amount, the rounding amount (BT-114) included: no computation can
make the two equal, so the operator is told rather than left with a document quietly claiming something
else. It follows EINVOICING_BR_CHECK like the other checks, so it warns by default and aborts on an
instance set to "blocking" (issue #506).

## 1.0.0

Initial version
