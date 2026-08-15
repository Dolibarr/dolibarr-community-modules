# Changelog

### Version v1.4.5
- NBP exchange-rate sync cron for Multicurrency module currencies (#34). Not enabled by default as invoices still collect current NBP
- Option to hide customer email/phone from KSeF invoices. Global default + per-customer override (#36)
- Per-customer toggle to hide buyer EORI (#36)
- More REST API endpoints for integrations
- Build release using github actinos

### Version v1.4.4
- Add GUS/REGON lookup to fill name and address for PL entity from NIP
- Prompts per-field before overwriting existing values and populates configured NIP, REGON, KRS, VAT etc fields
- Can choose to shorten the long organization names from GUS to acronyms with upper or mixed case (e.g. SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ to either SP Z O.O. or Sp z o.o)
- Use of the NIP lookup needs a GUS key (example request email on the How To Use and Authentication pages)
- Add "Check for updates" button on the About and Settings pages, with a download link to the latest GitHub release
- Show an "update available" banner across settings tabs if "Check for Updates" is clicked
- Remove unused configuration constants

### Version v1.4.3
- Fix NrEORI emitted incorrectly in XML (#32) and not properly displayed in PDF
- Add missing param key in podmiot3
  - Add a missing escaped % in translations 
- Visualization displays warunki transakcji properly
- Add boolean option to parse date prefix from NrUmowy extrafield to fill in Data Umowy
- Minor visualization improvements to PDF

### Version v1.4.2
- Add CRSF tokens to forms that were missing them

### Version v1.4.1
- Update default timeout to 7s instead of 5s and make it configurable in setup->other settings (Issue #27)
- Add support for third entity to invoice Podmiot3 (Issue #28) Currently, supports up to 20 per invoice, with IDWew for branches too. Payer entity-to-invoice payments must be manually reconciled.
- Per-invoice control of: MPP/split payment, FP, self-billing, cash accounting, reverse charge. Either sources from invoice/customer extrafield or always-on. Reverse charge supports per-customer default + per-invoice override. (Issue #29)
- Fixed issue with auto-generated products/third parties with quotes or other special characters flagging WAF and not importing (issue #30)
- More improvements to PDF visualization including to properly display above changes, fixes for multiple bank accounts and a couple header renderings
- Fix \n symbol being added by html stripping when newlines detected when importing

### Version v1.4.0
- Dolibarr replacement invoices repurposed as full correction invoices. Supports both differential and before/after line methods, and allows for corrections to corrections.
- Replacement invoices support correcting partially and fully paid invoices
- Entire correction chain is shown on each of the invoices within that chain
- Hooks for simple/double-entry accounting to manage correction deltas - may not fully support cash-basis tax mode.
- Add confirmation (can be disabled in outgoing invoice options) with PDF preview for upload and PDF preview after completion with save/print options
- Add Buyer ID, EORI fields
- Add GTU, procedura, and UUID support for line items
- FP and TP invoice flag support
- Reorganized outgoing invoice settings page (again)
- Fix VAT rate buckets for export/exempt/reverse charge
- KSeF-style PDF fixed formatting and add page numbering
- Update How-To-Use page with the above changes

### Version v1.3.7
- Fix blank icon in settings (Issue #21)
- Settings page split into 4 tabs: General, Authentication, Outgoing, Incoming
- Per-environment auth credentials - switch TEST/DEMO/PRODUCTION without re-entering tokens
- Setting to disable the "Validate and Upload" button for making validate/enter payment/then upload to KSeF a bit more obvious
- Add default invoice payment settings to set terms, method, and account during invoice creation automagically, can be overridden by thirdparty values
- VAT rate code helper in General settings to enable/disable ZW/RC/NP/NP2/WDT/EX codes
- Automatically adds KSeF VAT codes into dictionary if missing
- Notes default to Simple-Stopka on new installs
- Third Party and Product extrafields can be created/managed from the module setup page. Fields not made with KSeF module have an "ext" badge to differentiate pre-existing vs added within module
- Project extrafields support
- Third Party extrafields support in XML output
- Extrafields assigned as per-line extrafields result in these fields appearing on the invoice line-items, allowing for per-item additional notes that link back to invoice line number
- Extrafield notes now have target - each field routable to either DodatkowyOpis or StopkaFaktury
- Fix XML build error after extrafield deletion
- After payment is recorded, payment status sent in FA(3) XML (Issue #24)
- Multiple payments status and payment detail breakdown for incoming invoices
- P_10 line discount: include discount amount in FaWiersz when remise_percent > 0, calculated as unitPrice × qty − lineTotal
- NrZamowienia sourced from ref_client (default), linked sales order number, or custom extrafield. Multiple linked orders on a single invoice results in multiple Zamowienia numbers on the invoice (Issue #23)
- NrUmowy sourced from third party extrafield so contract number auto-populates from customer record (Issue #23)
- NrUmowy also supports invoice extrafield source
- Note_public now routable to StopkaFaktury instead (Issue #23). The options are now Simple-Stopka, Simple-DodatkowyOpis, and Key:Value-DodatkowyOpis
- Boilerplate invoice footer note on every invoice in module settings (such as for additional company information, legal disclaimers, etc)
- Explicit WDT and EX VAT source codes for intra-EU and export lines
- Fix line descriptions to use full line desc (with serial numbers, order refs, multiline text as configured on dolibarr page) instead of just product_label, truncated to 512 chars per schema
- Tax exemption support - disabled by default. When enabled and legal basis configured, if invoice has ZW lines puts P_19+P_19A/B/C into XML. Has global default plus per-product extrafield override. Settings hidden when disabled
- Fix VAT summary when invoice mixes 22%+23% or 7%+8% rate lines
- VAT summary now takes into account KR/ZW/etc
- KSeF-style PDF preview for draft and validated invoices before submission (Issue #22)
- Registries section on PDF only shows columns (KRS/REGON/BDO) that have data
- Upload to KSeF button available on paid/closed invoices that haven't been uploaded
- Updated How To Use section explaining setup for ZW/RC/NP/WDT codes, adding rates, and exemption setup guide
- Migrations to support new auth + notes settings changes

### Version v1.3.6
- Add check for whether module was enabled/disabled after updating, and a notification in settings menu to do so if it has not
- Notes support for outgoing invoices, sourced from public notes (simple or key:value modes) and/or selected invoice extrafields (Issue #19)
- Can preview the notes on the invoice card and in the notes editing page.
- Added info to the How To Use page

### Version v1.3.5
- KSeF system monitoring via Latarnia/Lighthouse API and visibility on dashboard/setup/submission/incoming pages
- If the KSeF Latarnia indicates an outage, the system shows a warning banner for maintenance/failures and updates the offline deadline calculations based on outage type
- New cron job to poll the KSeF Lighthouse API, default 15min
- Reworked the KSeF status dashboard
- Added documentation about making sure bank information is included to How to Use tab (Issue #19)
- Resolve PDF include warnings (Issue #18)
- Fix missing KSeF number on the Dolibarr invoice (Issue #15)

### Version v1.3.4
- Potential fix for KSeF-style PDF generation failure (issue #14)
- Better error messages when PDF generation fails

### Version v1.3.3
- Payment status, date, and method from KSeF XML displayed on incoming invoice card, import preview, and supplier invoice card
- Update phpseclib version
- Validate & Add Payment button on draft supplier invoices when full payment data (including payment method) available from KSeF xml invoice.
- Map KSeF FormaPlatnosci codes to Dolibarr payment types, pre-fill on import
- Date of Sale from KSeF XML written to supplier invoice extrafield during import
- Fix missing ksef.lib.php include in KsefClient
- Removed supplier not found translation extra "NIP" text in translation
- Added missing Polish translation for Date of Sale extrafield
- Fix cron job classesname not updated by 1.3.2 migration (issue #11) and re-runs xml parsing to grab the date of sale data for incoming invoices
- KSeF-style PDF visualization now works for offline invoices with dual QR codes (OFFLINE + CERTYFIKAT)
- parser now fully parses and handles advance payment invoices as deposit invoices
- Add offline invoice capability to the KSeF-style PDF visualization
- For outgoing invoices the KSeF-style PDF visualization auto-runs and stores the PDF after creating offline invoice or successful submission
- Handle improper incoming correction invoices when only quantity changes

### Version v1.3.2
- Fix issue #9. v1.3.0 improved invoices handling "0" values by checking for null, but fields were still initialized to 0. Now initialized null.
- Add migration to re-parse incoming xml for v1.3.2
- Configurable field mappings for NIP, KRS, REGON, BDO instead of hardcoded (issue #10)
- Can write translation overrides directly from settings page with configured mappings
- Warn when multiple identifiers are mapped to the same source field
- Auto-create suppliers with KRS/REGON/BDO from parsed incoming invoice XML
- Updated "How To Use" page with translation overrides
- Setup page is getting long so wrapped all the settings that are applied by save button together in an outline box
- Add inference for buyer country from VAT prefix when third party country isn't set
- Fix Cron jobs have old classname (issue #11)

### Version v1.3.1
- Reject malformed/incorrect invoices without blocking correct invoices
- Batch processing improved so one malformed invoice no longer breaks entire batch
- Fix bad KSeF number extraction fallback
- Handle out-of-range dates
- Incoming sync chunks in to 60-day windows to stay within KSeF API limits
- Display sync errors from last sync in a popup list
- Sync coverage timeline bar to incoming list and index pages
- Statistics slightly improved
- Reset sync state now resets to KSeF start date

### Version v1.3.0
- Importing of incoming invoices with individual and batch import from the incoming invoices list
- Supplier auto-matching by NIP and EU VAT ID
- Product auto-matching by product reference, supplier reference, and barcode
- Auto-creation of suppliers and products during batch import
- Batch import preview with validation warnings and blocking checks for correction invoices
- Updated import page with per-line product selection and supplier selection/creation
- Add import status tracking (New, Imported, Skipped, Error) with ability to reset or reopen
- Fixed VAT vs NIP for incoming invoices
- Incoming invoice sync now proper background processing to stop locking out the database when downloading thousands of invoices
- More info displayed during incoming invoice sync
- Block duplicate imports
- Link imported incoming invoices to the supplier invoices
- Fix KSeF verification URL on supplier invoices
- Values of 0 were incorrectly treated as empty when calculating fields
- Correction invoice type badges for KOR_ZAL and KOR_ROZ subtypes
- Updated "How To Use" page with importing documentation
- Fixed the mass actions for status and import tables
- Add import related settings
- Handle corrective invoices which increase total and mixed values (since Dolibarr doesn't really play nice with these)
- Incoming invoice handles multicurrency and takes exchange rate from XML
- PDF visualization auto-attached to imported supplier invoices
- Two modes for incoming invoices which increase the amount - 1. Zero out the original invoice and create a replacement invoice for finalized amount 2. create only replacement invoice with difference
- Warning when correction invoices have the same KSeF number as the corrected invoice

### Version v1.2.0
- Token/certificate choosing logic now chooses based on what is available
- Add setting to persist configuration through module disable/re-enable (defaults to persisting)
- Fix Issue #6 with date of sale by adding extrafield populated with sales order data. Date of sale is chosen in the following priority: 1. Linked shipment date 2. Linked sales order planned delivery date 3. Linked sales order date 4. Invoice date. It can be overridden per-invoice.
- Fix broken UPO download and handle grabbing at a later time
- Add setting to change default behavior of date of sale from the above to defaulting to invoice date (it's manually editable either way)
- Update scheduled jobs, removed the broken job and added jobs for checking status and re-attemping offline invoices, syncing incoming invoices, downloading outstanding confirmations, and warning of offline deadlines
- Add REST API endpoints with workaround for Dolibarr issue #32491
- Fix Issue #7 missing unit prices in HTML/PDF - they should be calculated if not included in the xml
- Add extrafields for supplier invoices so that importing has somewhere to write relevant fields
- Update actions_ksef.class.php to handle supplier invoices
- Add migration system to handle updates. Right now re-runs parsing when updating to new version since issue 7 requires this.
- Add handling for gross price only and net price only invoices, including without unit prices, since these are technically all valid ways to provide invoices
- Corrective invoices which correct multiple invoices now display correctly on the incoming invoices card and links now point to the corrected invoice

### Version v1.1.1
- Corrected payment mappings between Doliabrr/KSeF
- Fix PDF generation error for correction invoices
- QR code generation was using wrong translation method

### Version v1.1.0
- Reverted default to Demo environment instead of Production environment on enabling the module, just to ensure we don't accidentally submit official invoices when testing!)
- Add NBP exchange rate API for foreign currency invoices - fetches rate for last working day before invoice date from button on invoice page and configurable rate mode like the multicurrency module
- Block KSeF submission if NBP rate is missing for foreign currency invoices
- Add multicurrency fields to the fa3_builder
- Add some missing fa3 parser and fa3 builder fields that have support in Dolibarr
- Add exchange rate display and VAT-UE number support to PDF visualization
- Change payment due date box styling to match official KSeF visualization
- Consolidate duplicated XML parsing between fa3 parser and pdf builder
- Add Dolibarr MAIN_SECURITY_CSRF_WITH_TOKEN checks for CSRF protection
- Add NIP Checksum validation to kseflib
- Set max 30 retries before erroring out permanently and requiring new invoice
- Timeouts for incoming invoice syncing to prevent deadlocking
- Consolidate more logic into kseflib - the URLs in particular were getting out of hand
- Add check for CSRF tokens on about page
- Update the How To Use page with current instructions
- Update index page

### Version v1.0.0
- /!\ Update to use PRODUCTION environment as default now that system is live /!\
- Added warning in settings about production environment state
- Add configuration to settings for optional FA(3) fields (Client code, productID, GTIN/EAN, unit of measure, and bank description)
- Add timezone to DataWytworzeniaFa
- Seller NIP now grabs from KSeF configuration properly
- Add additional FA(3) fields (client code, product reference, GTIN, unit of measure, SWIFT code, bank name, bank description)
- Fix two payment method mappings
- Add FA(3) parser class to handle incoming invoices
- Fix retry attempt counting for failed submissions
- Improve capture of error details to help troubleshooting
- Add incoming invoices page - and all the requesting, downloading, decrypting, processing, parsing, and syncing state with HWM things it needs
- Remove thee unused methods from ksef.class.php
- Add code 429 handling when rate limited
- Add error handling for duplicated invoice number (happens in test/demo but unlikely to happen in production, yet still confusing)
- Update extrafields to use translation strings and properly update when module is updated
- Add PDF generation for incoming PDFs
- Add button to Generate KSeF-style visualizations of invoices for outgoing invoices (this uses the Company Logo from company/organization settings and the size is set by the standard Setup/PDF/Logo height setting)
- Add KRS, REGON, and BDO numbers to setup and FA3 Builder

### Version v0.3.0
- Add the exclusion options to third party page
- Update KSeF API addresses to the new ones
- Allow CURL to follow redirects if MF changes API again
- Fix typo in "How to Use" page link so it properly uses translation
- Fixed adding KSEF Number extrafield to PDF when it's included with QR code
- Added language strings for the above
- It should work on the day of system going live (1 Feb 2026). Just remember to change the environment to production at this time! (In v1.0 and beyond, after KSeF system is live, we will default to this environment)
- In case of any errors about XML validation, since KSeF doesn't provide specific feedback on XML validation errors, here's some troubleshooting to catch things (for instance, not including NIP for customer, etc)
  - Download the schemat_FA(3)_v1-0E.xsd from the KSEF-Docs github
  - Download the XML from the submission attempt either in the KSEF Submissions tab or on the invoice KSEF tab.
  - Use xmllint to verify the XML against the schema, for instance xmllint --schema schemat_FA\(3\)_v1-0E.xsd FA3_invoice_2(1).xml  FA3_invoice_1.xml
  - This should provide you specifically what validation check is being failed.


### Version v0.2.0
- Add warning when trying to modify KSeF-submitted invoice
- Validate and Upload appears only once a line-item is placed in, like rest of dolibarr actions
- Added Submit Online button for offline invoices
- Invoices with previous date display warning
- Added creation of offline invoices
- Fixed FA(3) builder using current time when re-hashing offline invoice for submission, breaking hash
- Added offline certificate handling for Offline invoices
- Added online certificates to use in place of tokens
- Can now switch between token and certificate authentication
- Added ability to handle technical corrections for XML errors
- Added certificate logic to kseflib
- Added QRII code to offline invoices
- Refactored the settings page to support certificates, selection between certs and tokens, etc

### Version v0.1.0
Initial Release
