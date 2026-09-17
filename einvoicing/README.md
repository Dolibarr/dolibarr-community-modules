# EINVOICING FOR [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## Features

Dolibarr module for secure API connection and data synchronization with EInvoice Access Point.

EInvoicing enables Dolibarr to communicate securely with certified Access Point portals (French "Plateforme Agréée").
It manages authentication, token renewal, and data exchange through standardized APIs, ensuring full traceability and compliance with upcoming regulations.

Other external modules are available on [Dolistore.com](https://www.dolistore.com).

## Translations

Translations can be completed manually by editing files in the module directories under `langs`.


## Installation

Prerequisites: You must have Dolibarr ERP & CRM software installed. You can download it from [Dolistore.org](https://www.dolibarr.org).
You can also get a ready-to-use instance in the cloud from https://saas.dolibarr.org


### From the ZIP file and GUI interface

If the module is a ready-to-deploy zip file, so with a name `module_xxx-version.zip` (e.g., when downloading it from a marketplace like [Dolistore](https://www.dolistore.com)),
go to menu `Home> Setup> Modules> Deploy external module` and upload the zip file.

### Final steps

Using your browser:

  - Log into Dolibarr as a super-administrator
  - Go to "Setup - Modules"
  - You should now be able to find and enable the module

### VAT exemption and operations outside the scope of VAT

When a line carries a 0 % VAT rate, the generated document has to say **why**: EN 16931 asks for a VAT
exemption reason code (BT-121), and the module refuses to generate rather than emit a document whose
reason is unknown.

Where that code is read from depends on the Dolibarr version:

| Dolibarr | Where the code is read |
|---|---|
| 18 to 23 | The hidden constant `MAIN_VAT_EXEMPTION_CODE_FOR_<rate>[_<VAT code>]`, entered in Home - Setup - Other |
| **24 and above** | The **VAT exemption code** column of the VAT dictionary (Home - Setup - Dictionaries - VAT rates) |

Pick any valid value of the [VATEX code list](https://docs.peppol.eu/poacc/billing/3.0/codelist/vatex/).

**From Dolibarr 24 on**, the code also decides the VAT category the document declares. Entering
`VATEX-EU-O` makes the module issue the line as **"Not subject to VAT" (category O)** instead of
"Exempt" (category E) - the qualification for an operation that is not taxable at all rather than one
a rule exempts, such as a supply of goods located outside France (CGI art. 258).

Such an invoice is constrained by EN 16931: it carries **no VAT identifier** (BT-31, BT-48, BT-63) and
**no other VAT category**. The module drops the VAT numbers by itself, keeps the seller SIREN (BT-32)
so the party stays identified, and refuses an invoice that mixes an operation outside the scope of VAT
with taxed or exempt ones - those have to be issued on their own invoice.

On Dolibarr 23 and below the category is unchanged, since the dictionary has no column to state it.

### Experimental features

Developers can enable experimental featues with constant:

EINVOICING_ALLOW_DEVTOOLS: Add a button to display the raw data of the invoice in the invoice card.

EINVOICING_ALLOW_MULTICOMPANY_INVOICE_MOVE: Set this option to the list of all entities ID. It will add a button to move an invoice from an entity to another one (if using multicompany module with all entities having the same SIREN, you can receive all your invoices in the master entity and dispatch invoices in the correct entity after retreival).


## Documentation

The `doc/` directory holds what does not fit in this page:

  - [`COVERAGE-AND-LIMITS.md`](doc/COVERAGE-AND-LIMITS.md) — **what the module does not cover, and why**:
    the billing frameworks (BT-23) it cannot produce, starting with the multi-vendor and self-billing
    ones and a seller belonging to a VAT group (*assujetti unique*), the syntaxes it does not read, and
    the elements of the FNFE reference documents it never writes or never reads. Every list on that
    page is measured by the conformance job, not compiled by hand.
  - [`LIFECYCLE-STATUSES.md`](doc/LIFECYCLE-STATUSES.md) — the lifecycle statuses and what triggers them.
  - [`ADD-A-PDP-PROVIDER.md`](doc/ADD-A-PDP-PROVIDER.md) — adding support for another access point.
  - [`SUPERPDP-AUTHORIZATION-CODE.md`](doc/SUPERPDP-AUTHORIZATION-CODE.md) — the OAuth enrolment flow.
  - [`FUNCTION-MAP.md`](doc/FUNCTION-MAP.md) — the generated map of the module's functions.

## Licenses

### Main code

GPLv3 or (at your option) any later version. See file COPYING for more information.

### Documentation

All texts and readme's are licensed under [GFDL](https://www.gnu.org/licenses/fdl-1.3.en.html).
