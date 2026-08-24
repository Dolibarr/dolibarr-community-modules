# CHANGELOG MODULE EINVOICING FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 1.0.4

FIX: The module can obtain an access token from the Esalink access point again. The token request had
lost its grant_type parameter, which RFC 6749 requires whatever the client authentication method is,
so the access point answered 400 Bad Request - "must not be blank" - before it ever looked at the
credentials, and the setup screen could only report that no token could be obtained. No token means no
exchange at all: no invoice sent and none received, on an installation whose credentials are perfectly
valid. The parameter was dropped when the token request moved to an Authorization: Basic header for
issue #586, and the option added afterwards, ESALINK_AUTHENT_USING_CLIENT_CREDENTIAL, restored the
working form only for the installations that knew to set it - the default stayed without grant_type.
The full form-urlencoded body - grant_type, client_id and client_secret, with no Basic header - is the
default again: it is the form scripts/testconnect.php has always sent, which is why the diagnostic
script kept working while the setup screen failed on the same credentials. HTTP Basic remains
available behind ESALINK_AUTHENT_USING_BASIC_AUTH for an access point that requires it, with
grant_type in the body there too and the credentials in the header only, as a client must not use more
than one authentication method in the same request. An installation that set
ESALINK_AUTHENT_USING_CLIENT_CREDENTIAL as a workaround keeps working: the constant is now without
effect, and the request it used to select is the default.

CHANGE: GETPOSTFLOAT(), a function the core gained in Dolibarr 20 and that the module backports for the
versions below, moves from the library of the module to compat/functions.lib.php, where the module keeps
what it copies from the core. Pure move, guard included: the library requires that file, so the two call
sites of admin/setup.php keep finding the function where they used to.

CHANGE: dolPrintHTMLForAttribute(), a function the core only gained in Dolibarr 19, was backported in
the library of the module, among its own functions. It now sits in compat/functions.lib.php, next to the
other core helpers the module ships for the versions that do not have them. Nothing else changes: the
library loads that file, so every caller finds the function where it used to. Worth knowing for the
versions below 19: the backport calls dol_escape_htmltag() with six arguments and that function only
takes five on Dolibarr 17, so the sixth one is not read there - which changes nothing for this use, the
fifth argument being 0 already escapes the whole string. Checked on 17 and 18 against the core of 19,
same output for plain text, accents, html tags, quotes, a javascript: link and an onerror attribute.

FIX: The list of e-invoicing flows works again on Dolibarr 17, the version the descriptor of the module
declares as the minimum it supports; that page had never been able to render there. It asks
CommonObject::getFieldList() to leave out of the SELECT the columns it does not read, among them 'recap',
a virtual column of the list which has no column in the table. That second argument only exists since
Dolibarr 18: on 17 the method takes the alias alone and ignores the rest without a word, so 'recap' went
into the query and the page answered a technical error, "Unknown column 't.recap' in 'SELECT'". Below 18
the page now builds the same list the core builds since 18, and nothing changes on 18 and above, where
getFieldList() is still the one doing it. Past that, the page died on "Call to undefined function
GETPOSTDATE()", a helper the core gained in 18 as well: it is backported in compat/functions.lib.php,
next to the other core helpers the module already ships for 17, and the page loads it. The page also
loads the library of the module, for dolPrintHTMLForAttribute() - a function the core only gained in
Dolibarr 19, already polyfilled there, that the same page calls to render its text columns and that
would fatal on 17 and 18 for the same reason. Finally, the page read
$conf->main_checkbox_left_column directly, a property the core only carries since Dolibarr 21, so every
load below that wrote six PHP warnings in the log for a value that is empty there anyway: it is read
through empty() now, the way one of those six places already read it.
FIX: The CHORUS extrafields no longer print on the PDF of an invoice or an order when the CHORUS option
is off (issue #614). They were declared printable = 1, "always print it", while their visibility depends
on a condition, getDolGlobalInt("EINVOICING_USE_CHORUS"), that the card of the object honours but the PDF
does not: CommonDocGenerator::getExtrafieldsInHtml() reads that condition on Dolibarr 18, 22, 23 and 24,
and not on 17, 19, 20 and 21, where three empty labels of a feature nobody turned on reached every
document. They are declared printable = 2 now, "print it only when it holds something", so an empty field
stays out of the document on every version. An installation that already carries the extrafields keeps
its own definition - addExtraField() does not touch an existing one - so the module updates them the way
it already updates their condition, the next time it is activated.
FIX: The e-invoice status combo no longer renders an invalid <option> on Dolibarr 17. The code of an
Access Point status is shown next to its label through a <span> put in the 'data-html' of the option,
and Form::selectarray() prints the data-* values of an option as they are on 17, where 18 and later
escape them: the attribute closed on the first quote of that span, and the markup of the whole option
was broken. The status list escapes the value itself on those cores now, which produces exactly what
the newer ones produce, and leaves the newer ones untouched rather than escaping twice.
NEW: The order reference the supplier declared on a received e-invoice (BT-13) is now kept on the
supplier invoice the import creates, and shown on its card, whether or not it matched a purchase order
of Dolibarr (issue #603). That reference was only used to auto-link the invoice to an order; the
no-match case - the ordinary one - dropped it, so the accountant could not see what the supplier had
declared, nor reconcile the invoice by hand. It is stored as sent, trimmed, in every case, and the
auto-link keeps behaving exactly as before.

The value does not go into an extrafield: the module deliberately stopped using the extrafields of the
core, since an admin or a user can rename, empty or delete one while the module is accountable for the
data it holds. It goes into a new table of the module, llx_einvoicing_extrafields, built on the model of
llx_einvoicing_extlinks - element_id and element_type identify the object - plus a name and a value, so
that the next property to keep on an object needs no schema change. EInvoicing::insertOrUpdateExtraField()
and EInvoicing::getExtraFieldValue() are the way in and out.
NEW: The reference a received e-invoice carries can now be matched against a ref_supplier that was
typed with extra text around it. The five lookups the import runs on ref_supplier - the duplicate
check, the referenced documents at document and at line level, and the source invoice of a credit
note - were as many copies of the same exact-match query, in the CII path and again in the Factur-X
one. A supplier invoice entered by hand as "PAY123 - FA202610 - dinner" was therefore never
recognised as the one the XML calls "FA202610", and the import stopped on a document it could not
find. Those call sites now share SupplierInvoiceHelper::findIdByRef(), which is still exact by
default: the hidden option EINVOICING_TOLERANT_SUPPLIER_REF_MATCH adds a fallback, tried only when
the exact match found nothing, and narrow enough that it cannot answer for the wrong invoice. A
reference shorter than EINVOICING_TOLERANT_SUPPLIER_REF_MIN_LENGTH (8) or purely numeric is never
searched for as a substring; the substring must be delimited by a non alphanumeric character or by
an edge of the ref_supplier, so "FA202610" matches "PAY123 - FA202610 - dinner" but not "FA2026100";
and the whitespace manual entry adds - "FA 2026 10", tabs and non-breaking spaces included - is
tolerated without losing the boundary it forms. Several candidates are reported as an ambiguity
instead of being guessed, and a database failure is no longer reported to the user as a missing
document.

FIX: A third party recognised as a private individual is no longer reported as misconfigured when
EINVOICING_SKIP_B2C is on (issue #600). The option already kept B2C invoices out of the e-invoicing
scope - needEInvoiceManagement() answers "do not manage" on them, since B2C is reported by e-reporting
and not transmitted as an e-invoice - but the pre-check of the third party knew nothing about it and
still demanded a professional id, an identifier a private individual has no reason to own. Generating
or sending such an invoice failed on "The customer has no professional id (SIREN)", and the invoice
could be refused altogether where EINVOICING_EINVOICE_CANCEL_IF_EINVOICE_FAILS is set. The pre-check
now reads the same Societe::isACompany() as the decision does, so both ends of the chain agree on who
is B2C, and neither the professional id nor the routing id is required of a private individual. A
company without a professional id is still blocked, option or not.
The same invoices could also reach that pre-check through the other end: needEInvoiceManagement()
answers with a status code, and the two codes meaning "out of scope" (98 and 99) are truthy, so the
callers that only tested its answer for truth treated an ignored invoice as one to e-invoice. They
now ask the boolean question instead - mustManageEInvoice() for an invoice, isIgnoredStatus() for a
stored status - both reading a single list of the codes that keep an invoice out of the scope. This
also removes the generation button from an invoice explicitly excluded from e-invoicing.

FIX: The supplier invoice list no longer fails on a MySQL server that keeps its default sql_mode. The
columns the module adds to the SELECT of that list were never added to its GROUP BY, so MySQL refused the
whole query with error 1055 (only_full_group_by) and the page reported a technical error instead of showing
the list. MariaDB, whose default sql_mode does not include ONLY_FULL_GROUP_BY, accepted the same query,
which is why the fault went unnoticed. The printFieldListGroupBy hook the core calls right after building
its own GROUP BY - where it adds its extrafields for that very reason - is now implemented. Dolibarr 17 to
21 are concerned; from Dolibarr 22 on the core builds no GROUP BY on that list at all.

FIX: The Factur-X files the module produces are now valid PDF/A-3, which they had never been - and a
Factur-X file that is not a PDF/A-3 file is not a conformant Factur-X, whatever its XML says (veraPDF
1.30.2 rejected every one of them, on Dolibarr 17 to 24 alike). The cause that belongs to the module is
only below Dolibarr 24, where the merger built on TCPDF is used: the Factur-X XMP extension schema was
added as a description of its own next to the one TCPDF had already written, repeating one property of
one subject, which the XMP specification forbids. The packet stopped parsing there, and a reader that
gives up sees no PDF/A identification either, so the file was not even recognised as PDF/A. The Factur-X
schema is now declared inside the bag the core writer produces.

The other cause is a setting of Dolibarr, not of the module, so the module only reports it: the invoice
PDF the merger carries over is drawn with the Standard 14 fonts unless the PDF format of Dolibarr is
PDF/A, and ISO 19005-3 requires every font used for rendering to be embedded. Generating an e-invoice
in Factur-X while "PDF documents format" is not PDF/A-3b now warns, on the setup page of the module and
on the invoice, that the recommended format is CII - which needs no PDF at all - and that keeping
Factur-X means setting PDF/A-3b for the whole Dolibarr, in Home - Setup - PDF.
NEW: A credit note received for an invoice that was refused can no longer be accepted. Refusing a
received invoice cancels it - it owes nothing any more - and the vendor answers by issuing the credit
note that closes the matter on its side. Accepting that credit note would acknowledge the reversal of a
debt that never entered the accounts, and would leave the exchange saying two contradictory things about
the same operation. So "Approved" and "Partially approved" are no longer offered on it, validating it no
longer answers its vendor with "Approved" either, and the card says which refused invoice it credits;
refusing it stays available, as do the statuses that settle nothing, like "Disputed" or "Suspended". A
replacement invoice is deliberately left alone: it is the corrected invoice a vendor sends after a
refusal, which is precisely what one has to be able to accept. A refusal the platform has not confirmed
yet changes nothing either, since it can still be rejected (issue #594).

NEW: Validating a supplier invoice received through the platform now answers its vendor with the
"Approved" (205) status, instead of waiting for someone to remember the button on the invoice card.
Validating a received invoice is the act of accepting it - it leaves the draft state to enter the accounts
and become payable - and 205 is the answer the buyer owes on an invoice it accepts; a vendor left without
one cannot tell an accepted invoice from a forgotten one. It is sent once per invoice, never on an invoice
already answered with "Approved" or "Refused", and never on an invoice that did not come from the platform.
A failure to send is reported and logged but never undoes the validation, and the status can still be sent
by hand afterwards. Set EINVOICING_DISABLE_SEND_APPROVED_ON_VALIDATION, in the module setup, on an instance
where validating an invoice does not mean approving it: nothing is sent automatically then and the manual
button is unchanged.

FIX: The generated documents now declare the period the invoice covers (BG-14, BT-73 and BT-74), which
neither the CII nor the Factur-X path ever wrote. Dolibarr holds a period on the line and not on the
invoice, so the header period is the one covering every line: the earliest start date and the latest end
date of the lines that carry one. The line periods (BT-134/BT-135) are unchanged and still emitted; this
adds the header the receiving software mostly reads, since a majority of tools only handle a period at
invoice level. An invoice whose lines carry no period declares none, one side alone is enough
(BR-CO-19 asks for the start date or the end date), and a set of lines that would derive a period
starting after it ends - one line open from March next to another closed in January - declares no header
period at all rather than a pair BR-29 refuses, which would have the whole document rejected for a field
nobody filled in (issue #572).

FIX: Importing a received e-invoice now keeps the billing period of its lines (BT-134 / BT-135), where a
service line billed over a period used to arrive with no period at all. The two dates were read from the
document and then went nowhere: the line built for the supplier invoice never carried them, although the
call that saves it has always passed its date_start and date_end on to the core. A period with only one
of the two dates keeps that one, as the norm allows (BR-CO-20). A document declaring a period that ends
before it starts - which BR-30 refuses, and which the core refuses too - is imported without that period
rather than failing the whole invoice over it (issue #576).

FIX: The module works on Dolibarr 17 again, the version its own descriptor declares as the minimum it
supports. Two things stood in the way and neither of them failed quietly. Installing it died on a PHP
TypeError: init() passed an empty array() where ExtraFields::update() expects a parameter string, and
the branch that tolerates an empty array was only added to the core in 18, so activateModule() never
completed. Generating a document died on "Call to undefined function dolChmod()", that helper having
arrived in the core in 18 as well, while both writers call it on the XML they have just produced. The
first is passed as '' now, which stores the same thing everywhere, and the second is supplied by a
compat file alongside the two the module already ships for that version.

FIX: On Dolibarr 23, a failed e-invoice generation was reported as an error where the core was able to
carry it as a warning, so validating an invoice showed a red message for something that does not stop
the validation. The module kept everything as an error below 24, but the chain a hook needs to report
a warning - HookManager collecting the warnings of the hook instance, the document generator copying
them, and commonGenerateDocument() copying them onto the object - is whole in 23 and absent in 22. The
bound is 23 now. Nothing changes on 22 and below, where the warning would be reported nowhere, nor on
24 and above.

FIX: The compatibility files the module ships for the older cores are loaded from a path relative to
the file that needs them, instead of being looked up through dol_buildpath(). A lookup that does not
resolve - a deployment that does not sit where the module expects, which is what a container install
can produce - only wrote a line in the log and returned false, so the polyfill was silently absent and
the next call to it was a fatal "Call to undefined function isValidSiren" (issue #565).

FIX: A seller that charges no VAT now identifies itself on the documents it generates. A company set
as "Non assujetti a la TVA" has no VAT number, and the writer only ever emitted one, so the seller
carried no tax registration at all: every exempt line then broke BR-E-02, which wants the seller VAT
identifier (BT-31), the seller tax registration identifier (BT-32) or the tax representative one, and
the platform refused the invoice. Recording an exemption reason code did not help, that one answers
BR-E-10. The seller now declares whichever identifier its VAT regime calls for - its VAT number under
the scheme VA, or its SIREN under the scheme FC (BT-32) when it charges no VAT - and the regime
follows the sales tax type of the company setup, the same setting the VAT category of each line is
already derived from. No option of this module states it: a second place to declare the regime is a
second place for it to disagree with Dolibarr, and a document carrying exempt lines while claiming a VAT
registration is what that disagreement produces. A seller subject to VAT that simply left the field
empty keeps getting the message naming what to fill in, rather than a silent fallback on its SIREN, and
an exportation or an intracommunity supply now stops with an explicit message when no VAT number is
recorded: BR-G-02 and BR-IC-02 accept the VAT identifier alone, so nothing can stand in for it there
(issue #560).

FIX: An exempt invoice line now carries the exemption reason it is counted against, and not only the
VAT breakdown does. BR-FXEXT-E-08 reconciles the taxable amount of an exempt breakdown with the sum of
the net amounts of the lines it covers, and only counts a line whose own reason code and reason text
equal those of the breakdown - so with the reason on the breakdown alone the rule counted zero lines,
reported the invoice as unbalanced, and the reference validator returned it as invalid whatever else
the document got right. Both the CII and the Factur-X writers now repeat them on the line; a line with
nothing to declare is unchanged.
FIX: Below Dolibarr 24, a Factur-X invoice was produced as a plain PDF with the XML attached to it,
without the document level /AF entry nor the PDF/A-3 output intent a Factur-X reader looks for, and
with the XML embedded twice. Nothing said so, and the file was refused further down the line: the
public validator answers "the file does not contain one and only one factur-x.xml". The cause was a
class name collision - the core declares its own FPDF below v24, which the writer of
horstoeko/zugferd cannot then inherit from - and the fallback silently degraded the output instead.
Such a file is now built with TCPDF, which the collision does not concern and which supports PDF/A-3
natively, so every supported Dolibarr version produces the same structure. The produced file is also
checked before being handed over, so an incomplete one is reported instead of being sent, a carrier
PDF that cannot be read aborts the generation with an explicit message rather than dying on a parser
error, and Factur-X no longer announces itself as needing Dolibarr 24. The sample invoice of the
setup page was hit by the same collision, from a page that had already rendered a PDF: it died on a
PHP fatal error, which no error handling can catch, and the setup page came back blank (issue #554).

FIX: A line billed over a period that has only a start date, or only an end date, now carries that
period in the Factur-X document as it already did in the CII one. The Factur-X path asked for both
dates before writing anything, so a service line left open on one side lost its BT-134/BT-135
entirely - and the same invoice produced two different documents depending on the protocol selected.
One date alone is a period the norm accepts: BR-CO-20 asks for the start date or the end date, "or
both".

FIX: The Access point setup page carries the title of each of its sections again on Dolibarr 18 to 22.
FormSetup::generateOutput() did not grow its arguments all at once - $editMode alone up to 19, plus
$hideTitle from 20, and only from 23 the $title and $cssfirstcolumn the page passes - and PHP discards
the surplus arguments of a user function without a word, so from 20 on the page asked for a title the
core never read and printed the default "Parameter / Value" header instead. The rewrite that supplies
the title below that version is used up to 22 now, and it matches the header row on its shape rather
than on the "Value" label, which 22 leaves empty.

FIX: Generating two Factur-X sample invoices in the same request no longer ends on a PHP fatal error.
The generator loaded its helper file with require rather than require_once, so the second call
redeclared its functions - and a fatal error is not something the calling code can catch and report.

FIX: Validating a replacement supplier invoice now closes the invoice it replaces, with the close
code the core reserves for that, instead of leaving it validated and open for payment with nothing
saying it had been superseded. Dolibarr does it on the customer side and not on the supplier one, so
a replaced supplier e-invoice could still be paid a second time. Only the invoices exchanged through
the platform are concerned; one that is already paid, or still a draft, is left untouched (issue
#549).

FIX: A down payment invoice now declares in BT-8 that its VAT falls due on collection, whatever the
VAT mode of the instance, which is already why its cash-in is reported to the platform with the status
212. Dolibarr builds every down payment line as a goods line, so the document used to say nothing at
all while its lifecycle said otherwise. The VAT regime every other document declares in BT-8 keeps
coming from the VAT mode of the Tax/VAT module and from nothing else: no option of this module states
it, that mode being also what the VAT report of Dolibarr is built on, so a second place to declare the
regime would let an invoice tell the buyer the debits option while the seller declares its VAT on
collection. The exigibility at the delivery (29) is therefore never emitted, which changes nothing for
the public portal - it only expects 5, a 29 having to be reported to it as 5 (BR-FR-MAP-29) - and
Dolibarr has nothing to derive it from anyway, its own setup reading a goods delivery as the invoice
date (issue #419).

FIX: The setup page no longer dies on a fatal error when the selected Access point cannot be
instantiated - the case of a provider disabled after being selected, "SuperPDP via partner only" being
the way it happens today, since that option disables every other entry including the one already
recorded in EINVOICING_PDP. The page read the configuration of a provider it did not have, so it
answered nothing at all and the setup could no longer be reached to select another one. It now says
which Access point is unavailable and offers the ones that remain. The actions of the provider block
(token, health check, sample invoice) are skipped in that state instead of being matched on an empty
prefix.

FIX: Two fields declared in the ->fields of an object had no property on the class, which the core
notices for us: it reads $this->{$field} to build the INSERT, and the comment next to that line says
a miss means "a bug into definition of ->fields or a missing declaration of property". Document
declared response_for_debug in its fields only, so every document recorded logged "Undefined property:
Document::$response_for_debug" on 18, where CommonObject has no magic getter (from 20 on the getter
swallows it). Call is worse: its fields declare provider, its properties declared fk_provider - a
column the table never had - so the provider name of every logged API call was written through a
property PHP creates on the fly, which is deprecated since 8.2 and warns on all four versions.
Both properties are now declared, and the stale fk_provider is gone.

NEW: Another module can add its own PDP / Access Point provider, without patching this one. It
declares the hook context 'einvoicingproviders' and returns its entries from an addPDPProviders()
method; the entry names the class and the directory it lives in ('classpath'), which until now was
hardcoded to einvoicing/class/providers/, so a provider class could only exist inside this module. A
hook rather than a scan of the module directories: it only exposes the providers of the modules that
are enabled, and it costs nothing when no module implements it. An entry whose code is already taken
by a provider of the module is ignored, and a class that does not extend AbstractPDPProvider is
refused when it is loaded rather than failing later on a missing method. The TESTPDP entry, which
pointed to a class that did not exist, is now the documented reference implementation: it calls
nothing over the network and is offered only when the developer tools are enabled. The contract to
implement is written down in einvoicing/doc/ADD-A-PDP-PROVIDER.md.

FIX: A synchronization no longer raises a PHP warning for every flow whose source invoice is not in
this database. Such a flow is the ordinary case on a platform account shared with another system -
the customer invoice behind it simply lives somewhere else - and syncFlow() notes it in a message
built from $document->flowId, while the document object carries flow_id. On a Dolibarr whose
CommonObject has no magic getter to absorb the miss (18) that logged "Undefined property:
Document::$flowId" once per flow; from 20 on the getter swallows it. The message itself, which the
caller discards today since the flow counts as synchronized, also lost the one identifier it exists
to carry.

FIX: The sample invoice no longer pins a discount rounding that no two Dolibarr versions agree on.
It forced MAIN_APPLY_DISCOUNT_ON_UNIT_PRICE_THEN_ROUND_BEFORE_MULTIPLICATION_BY_QTY to 2 - round the
discounted unit price, then multiply - but 18 and 20 do not implement that option and round the line
total instead, 23 rounds the unit price up and 24 rounds it down, so its single line (5 x 100.05 less
10%) came out at 450.23, 450.25 or 450.20 depending on the core. The specimen is now computed with the
default convention, the one all four return, and the setting of the instance is restored afterwards
instead of being left changed for the rest of the request. The reference fixtures are updated
accordingly, and EInvoicingSamplesTest, which passed on only one of the four versions tested, now
passes on all of them, 18.0.10 to 24.0.0.

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

FIX: The recipient reachability pre-check no longer answers "undetermined" on SuperPDP when the
platform can tell. Some lines of its standardized directory answer come back without their status,
and that status cannot be requested, so the check stayed non-conclusive; its own directory endpoint,
already used when the standardized lookup is unavailable, does report it for the very same lines, and
it now settles those answers. Only those: a status the standardized answer did give is never
overridden, and an endpoint that fails or knows nothing of the recipient settles nothing. The two
recipients seen with that gap - both declared and not open yet - are now reported as not reachable
instead of undetermined, and the option that requires a reachable recipient blocks them at its first
value. Where the verdict was read is displayed next to it, since the directory consulted by hand
shows no status for that line.

FIX: Synchronizing incoming documents no longer stops on a vendor whose thirdparty code is missing.
On an instance where the code is mandatory - MAIN_COMPANY_CODE_ALWAYS_REQUIRED, or a numbering module
that refuses an empty code - a thirdparty saved without one, as an import or a provisioning script
leaves it, is refused by the core on every update: Societe::verify() answers ErrorSupplierCodeRequired
and the synchronization aborts there, leaving the remaining flows untouched. The module now asks for a
generated code, the way the thirdparty card does when it saves that same thirdparty, and only where
the numbering module allows the code to be set. The customer code is treated the same, because the core
checks both on any update and reports only the last of the two, which made the customer half invisible
behind the vendor error. Marking a thirdparty as a vendor also stores its new code now, which passing
the code alone never did: update() writes the code columns only when it is allowed to modify them.

FIX: The same invoice no longer produces two different documents depending on the button that generated
it. The comment opening the XML names the instance it was produced on, and that name comes from
getHashUniqueIdOfRegistration(), a function of the blockedlog library that only the paths going through
the PDF builder happen to load - so generating from the attached files carried the hash, while
regenerating from the e-invoicing menu, which calls the writer directly, silently dropped it. The
library is now loaded where the comment is written. Nothing changes below Dolibarr 23, where that
function does not exist yet, and nothing changes on the PDF path (issue #581).
  
FIX: Approving a received invoice no longer takes away the statuses that come after it. The einvoice
button group of the supplier invoice card disappeared as soon as an "Approved" (205) or a "Refused"
(210) status had been accepted by the platform, on the assumption that either of them closes the
lifecycle. Only the refusal does - an invoice sent back to its vendor is not going to be paid - while
an approved one is, and "Payment transmitted" (211) is what reports it. Since the normal order of
things is to approve an invoice and then pay it, the manual 211 was already unreachable by the time
anyone would want it, and re-opening the invoice did not bring it back: the condition never looked at
the Dolibarr status of the invoice, only at what had been sent. The card now offers what the exchange
still allows - a status the platform accepted is not proposed a second time, a refusal leaves nothing,
and an approval only takes the refusal away with it. The query it replaces also compared the direction
and the validation status against their stored case, which matches nothing on PostgreSQL (issue #548).

FIX: A synchronization interrupted on a Factur-X invoice lets you download the document that stopped it
again. The document list offers the last invoice that could not be processed under a fixed name, and it
kept asking for facturx.pdf, a name nobody has written since the Factur-X reception was merged into the
CII protocol: the received document is promoted to a slot named after the protocol file extension, so it
lands in einvoice.pdf and the page pointed at nothing. The name is now asked of the protocols themselves,
both by the page and by the clean-up that empties the slots at the start of a run - which was leaving the
Factur-X one behind, too - so a protocol added later gets its slot without a page to update. The readable
view that comes with some flows follows the same rule: it is offered when it exists, where it used to be
offered on a Factur-X failure although its file is never written under that name, and never on a CII one
although it is written there (issue #588).

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
