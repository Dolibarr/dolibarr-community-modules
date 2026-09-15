# What this module does not cover

Every list on this page is **measured, not written by hand**. The conformance job runs the module's
builder and its parser against the documents the FNFE publishes as the answers to its own use cases
— `Z.example/TEST` of the [`France_RFE`](https://github.com/fnfempe/France_RFE) package, pinned to
`v1.4.0.04`: 28 CII documents and the 15 Factur-X PDF carrying the same invoices — and prints what
neither side touches into the job summary on every run. The harnesses are described in
[`../test/conformance/README.md`](../test/conformance/README.md).

The page exists so that a gap is **a decision on record** rather than a discovery made in front of a
customer.

## The rule

> As long as a field is not mandatory, we can avoid to include it (so we take no risk of error with
> that). When a lot of time has been past without error we can include them in future.

Maintainer's answer on [issue #959](https://github.com/Dolibarr/dolibarr-community-modules/issues/959),
2026-09-14.

A field written is a field that can be written wrong, and a document an access point refuses costs
more than an element nobody asks for. An optional element is therefore left out until there is a
reason to write it — not because it was overlooked.

## 1. Billing frameworks (BT-23)

`BR-FR-08` accepts twenty values. `getBillingProcessID()` produces nine of them:

| BT-23 | case | produced |
|---|---|---|
| B1 / S1 / M1 | initial invoice — goods, services, mixed | yes |
| B2 / S2 / M2 | invoice already paid in full | yes, and only when BT-113 really covers BT-112 (`BR-FR-CO-09`) |
| B4 / S4 / M4 | final invoice after a deposit | yes |
| S3 | subcontracting with direct payment (B2G, Chorus Pro) | no |
| S5 | invoice issued by a subcontractor | no |
| S6 | invoice issued by a co-contractor | no |
| B7 / S7 | VAT already collected (e-reporting) | no |
| B8 / S8 / M8 | **multi-vendor invoice** | no — out of reach, see below |
| B9 / S9 / M9 | **bidirectional self-billing** | no — out of reach, see below |

The prefix is the nature of the lines: `B` goods, `S` services, `M` mixed.

### Out of scope: multi-vendor (8) and bidirectional self-billing (9)

These two are not "not implemented yet". They are **structurally out of reach**: the elements their
rules make mandatory are elements the builder cannot construct, and every one of those rules is
fatal.

| rule | what it demands |
|---|---|
| `BR-FR-MV-01` | when BT-23 is S8, B8, M8, S9, B9 or M9, **every** line shall carry a line subtype `ram:LineStatusReasonCode` |
| `BR-FR-MV-02` | under S8/B8/M8 the invoice shall hold at least one `GROUP` line with no `ram:ParentLineID` — the parent/child tree itself |
| `BR-FR-BD-02` | under S9/B9/M9, exactly two such `GROUP` lines |
| `BR-FR-MV-03` | under the same frames, each line shall carry `ram:ItemSellerTradeParty` with a name, a legal organisation identifier and a country |

A Dolibarr invoice has one supplier and no parent/child line structure, so there is nothing to build
that tree from: emitting one of these frameworks would mean inventing the data. **Neither framework
is a target.** An invoice belonging to one of these cases has to be produced outside the module.

### Out of scope: a seller belonging to a VAT group (*assujetti unique*)

`BR-FR-CO-15/BT-29-1`, fatal: if the seller is a member of a VAT group — its `ram:GlobalID` carries
`schemeID` `0231` — then the tax representative block **BG-11** shall be present and carry the VAT
number of the group (BT-63).

The module writes the seller identifier under `0225` only (`0231` is deliberately left out of
`_mapGlobalIdSchemeToIdprof()`, it is not the registration identifier of the party the document
names), and it never constructs `ram:SellerTaxRepresentativeTradeParty`. **A seller belonging to a
VAT group cannot emit a conformant invoice through this module.**

## 2. Syntaxes

| syntax | emission | reception |
|---|---|---|
| Factur-X (CII inside a PDF/A-3) | yes | yes |
| CII, standalone XML | yes | yes |
| CDAR (lifecycle statuses) | yes | yes |
| **UBL** | **no** | **no** |

UBL is declared in `ProtocolManager` and disabled there (`$ublIsOk = 0`); the protocol class does not
exist. A UBL invoice received is not read, whatever its profile. The reference package ships UBL
documents; they are not part of what is measured.

## 3. Elements the reference documents carry and the builder never writes

Measured by `test/conformance/emitted-terms.php`, which reads what the builder is **able** to
construct anywhere in its source — not what one specimen invoice happens to contain. Sixteen distinct
names, across the three profile families (22 entries counting a name once per family).

None of them is made mandatory by the CTC-FR schematron or by the CEN EN 16931 rules, apart from the
four of section 1. They are not written, by the rule above.

| element | term | profile families | why not |
|---|---|---|---|
| `ram:PaymentReference` | BT-83 remittance information | BASICWL, EN16931, EXTENDED | no core field before 24.0, and `FactureFournisseur` never touches `payment_reference` |
| `ram:ReceivableSpecifiedTradeAccountingAccount` | BT-19 buyer accounting reference | BASICWL, EN16931, EXTENDED | no per-invoice field in the core |
| `ram:SellerOrderReferencedDocument` | BT-14 seller order reference | EN16931, EXTENDED | no field: it is a lookup on `llx_commande_fournisseur.ref_supplier` |
| `ram:DespatchAdviceReferencedDocument` | BT-16 despatch advice reference | BASICWL | the parser reads it (`despatchAdviceRef`); the builder never writes it |
| `ram:PayeeTradeParty` | BG-10 payee | BASICWL | a Dolibarr invoice has no payee party |
| `ram:DirectDebitMandateID` | BT-89 mandate reference | BASICWL | `llx_societe_rib.rum` exists, nothing maps it |
| `ram:CreditorReferenceID` | BT-90 creditor identifier | BASICWL | the core holds the ICS globally (`PRELEVEMENT_ICS`), not per thirdparty |
| `ram:PayerPartyDebtorFinancialAccount` | BT-91 debited account | BASICWL | not carried on the invoice |
| `ram:ApplicableTradeSettlementFinancialCard`, `ram:CardholderName` | BG-18 payment card information | EXTENDED | no card data on a Dolibarr invoice, and none should be |
| `ram:SellerTaxRepresentativeTradeParty` | BG-11 seller tax representative | BASICWL | **conditionally fatal**, see section 1 |
| `ram:ItemSellerTradeParty` | line-level seller | EXTENDED | **conditionally fatal**, multi-vendor only |
| `ram:LineStatusReasonCode` | line subtype | EXTENDED | **conditionally fatal**, multi-vendor and self-billing only |
| `ram:ParentLineID` | parent line | EXTENDED | **conditionally fatal**, multi-vendor and self-billing only |
| `ram:BasisQuantity` | BT-149 / BT-150 price base quantity | EN16931, EXTENDED | not a gap, see section 5 |
| `ram:AppliedTradeAllowanceCharge` under `GrossPriceProductTradePrice` | BT-147 item price discount | EXTENDED | not a gap, see section 5 |

## 4. Elements the reference documents carry and the import never reads

The other direction, measured by `test/conformance/import-corpus.php`. "docs" is how many of the 28
reference documents carry the term; the parser brings it back from none of them. No rule can report
this: a reader that drops a term still produces a valid document, so the schematrons stay green while
the information never reaches Dolibarr.

| docs | term | a home in the core? |
|---|---|---|
| 28 | BT-83 remittance information | `llx_facture_fourn.payment_reference`, 24.0 and up only, never read or written by `FactureFournisseur`, and `varchar(25)` is short |
| 27 | BG-13 / BT-70 deliver-to party | the `invoice_supplier / external / SHIPPING` contact — the one the **emission already builds BG-15 from** |
| 26 | BT-33 seller additional legal information | partly: `fk_forme_juridique` and `capital` hold no sentence |
| 24 | BT-8 VAT point date code | no — `date_pointoftax` holds BT-7, the date; emission derives the code itself |
| 23 | BT-75 / BT-76 deliver-to address lines | same as BG-13 |
| 18 | BT-56 buyer contact department | partly: `llx_socpeople.poste` is a job title |
| 18 | BT-14 seller order reference | a lookup key rather than a field |
| 18 | BT-18 / BT-18-1 invoiced object identifier, document level | no; the line-level one is read |
| 16 | BT-19 buyer accounting reference | no per-invoice field |
| 4 | line-level item seller | no — one supplier per Dolibarr invoice |
| 2 | BG-10 payee | no payee party |
| 2 | BT-49 buyer electronic address | yes, the module already keeps one |
| 2 | BT-89 mandate reference | `llx_societe_rib.rum` |
| 2 | BT-90 creditor identifier | global `PRELEVEMENT_ICS`, not per thirdparty |

Settled on [issue #958](https://github.com/Dolibarr/dolibarr-community-modules/issues/958),
2026-09-15:

- **BG-13 / BT-70 / BT-75 / BT-76 is the one worth reading**, and where it goes is not open to
  interpretation. The maintainer's answer: *"The delivery address was, is and will be the contact
  with type SHIPPING. No other method to store it exists. The fk_address_delivery was never released
  and will never be as it is a duplicate method of SHIPPING address."* So the import stores it in the
  `invoice_supplier / external / SHIPPING` contact, the one the emission already builds BG-15 from.
  It is the asymmetry named in section 5.
- The terms with **no home in the core** (BT-33, BT-8, BT-56, BT-19, document-level BT-18) and the
  two that are a **design question** (BG-10, the line-level item seller) follow the rule at the top
  of this page: not mandatory, not read — and Dolibarr has no multi-vendor. They stay listed here
  rather than implemented.
- **BT-83** is the one term left to arbitrate on its own, because the trade-off is not the same in
  this direction: an element left unwritten risks a refused document, a term left unread refuses
  nothing but silently drops information the supplier did send.

## 5. Measured out, not missing

Two entries of section 3 are artefacts of a mechanical measurement, not information lost:

- **`ram:BasisQuantity` (BT-149/150).** `resolveLineUnitPrice()` divides a received price by its base
  quantity, so Dolibarr stores a true unit price — and a document with no base quantity is read by
  the standard as 1, which is exactly what the generated document means.
- **`ram:AppliedTradeAllowanceCharge` under `GrossPriceProductTradePrice` (BT-147).** The module
  writes a line discount as `ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeAllowanceCharge`
  (BG-27 / BT-136), which is the EN 16931 form; the reference documents happen to use the gross-price
  form. Same discount, different encoding, both conformant.

One entry of section 4 is the mirror case and **is** a real asymmetry: **BG-13 / BT-70**, the
deliver-to party, is written by the emission and never read back on import.

## 6. Re-measuring

Nothing here has to be trusted. From the root of the module repository, with the reference package
unpacked (see [`../test/conformance/README.md`](../test/conformance/README.md) for the download):

```sh
# what the import never reads, over the whole corpus - needs a Dolibarr instance
DOLI_ROOT=/var/www/dolibarr/htdocs \
  php einvoicing/test/conformance/import-corpus.php "$FNFE_ROOT/Z.example/TEST"

# what the builder never writes - needs nothing but the sources
php einvoicing/test/conformance/emitted-terms.php "$FNFE_ROOT/Z.example/TEST"

# what the builder writes today, and fails on anything it stops writing
DOLI_ROOT=/var/www/dolibarr/htdocs php einvoicing/test/conformance/emitted-paths.php
```

The first two report and never fail: they are an inventory, not a debt. The third is the fatal one,
and it is fatal on one thing only — an element the builder used to write and writes no more.
