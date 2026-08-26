# Invoice lifecycle statuses (French reform) — what the norm asks, what the module does

Memo. Left half: the 14 lifecycle codes of the French e-invoicing reform, their meaning and whether
the norm makes them **mandatory** or leaves them as a **courtesy**. Right half: what the `einvoicing`
module actually does with each one — automatic, behind an option, manual button, or nothing.

Reference: DGFiP *Dossier de spécifications externes B2B* v3.x (AFNOR XP Z12-012 for the semantic
model). Only **four** statuses are mandatory: **200, 210, 212, 213**. Everything else is optional —
"strongly recommended" for transparency, but a platform is free not to implement it, and a missing
optional status proves nothing. Written 2026-08-26 against `main` of the module.

Wording note: the codes are named here with their official French labels, since that is what the
platforms return; the module's own English labels (`langs/en_US/einvoicing.lang`) are in the third
column.

---

## 1. The 14 codes at a glance

| Code | Official label (fr) | Module label | Emitted by | Norm | What the Dolibarr module does |
|---|---|---|---|---|---|
| 200 | Déposée | Deposited | seller's PA | **Mandatory** | **Inbound only.** Received on a customer invoice and displayed. Never sent. |
| 201 | Émise par la plateforme | Issued by seller AP | seller's PA → buyer's PA | Optional | **Inbound only.** Displayed. |
| 202 | Reçue par la plateforme | Received by customer AP | buyer's PA | Optional | **Inbound only.** Displayed. |
| 203 | Mise à disposition | Available to customer | buyer's PA → buyer | Optional | **Inbound only.** Displayed. |
| 204 | Prise en charge | Taken over | buyer | Optional | **Nothing.** Displayed inbound; explicitly removed from the sendable list ("not supported for now"). |
| 205 | Approuvée | Approved by customer | buyer | Optional | **Option** `EINVOICING_SEND_APPROVED_ON_VALIDATION` (off by default) → sent automatically when the supplier invoice is validated. Otherwise **manual button** on the invoice card. |
| 206 | Approuvée partiellement | Partially approved | buyer | Optional | **Nothing.** Reason codes are declared but the status is not offered for sending. Displayed inbound. |
| 207 | En litige | Disputed | buyer | Optional | **Nothing.** Same as 206: reason codes declared, sending not offered. Displayed inbound. |
| 208 | Suspendue | Suspended | buyer | Optional | **Nothing.** Same as 206. Displayed inbound. |
| 209 | Complétée | Completed | seller | Optional | **Nothing.** The answer to a 208 (the seller supplies the missing attachment); not implemented. Displayed inbound. |
| 210 | Refusée | Refused by customer | buyer | **Mandatory** | **Manual button only**, never automatic. Asks for a reason code. Terminal: closes the exchange. |
| 211 | Paiement transmis | Payment transmitted | buyer | Optional | **Option** `EINVOICING_SEND_PAYMENT_SENT_STATUS` (off by default) → sent automatically when the supplier invoice is classified paid. Also available as a **manual button**. |
| 212 | Encaissée | Payment received | seller | **Mandatory** (when VAT is due on collection) | **Automatic, no option**: sent on every customer payment recorded, per cash-in. Not manually sendable. |
| 213 | Rejetée | Rejected by seller AP | seller's PA | **Mandatory** | **Inbound only.** Technical rejection; the only reliable negative signal. Displayed. |

Reading the "Emitted by" column: on a **customer** invoice Dolibarr is the seller (it receives
200→213 and sends 212); on a **supplier** invoice Dolibarr is the buyer (it may send 205, 210, 211).

---

## 2. The lifecycle, end to end

```
  SELLER SIDE  -  Dolibarr issues a customer invoice
                |
                |  deposit of the invoice
                v
      +--------------------------------------+
      |   PA-E  -  the seller's platform     |
      |   format and rule checks             |
      +--------------------------------------+
                |                       |
            checks OK               checks KO
                |                       |
                v                       v
          [200] Deposee           [213] Rejetee           .....  TERMINAL
         MANDATORY   in          MANDATORY   in                  fix, then redeposit
                |
                v
          [201] Emise par la plateforme                   optional   in    PA-E -> PA-R
                |
                v
          [202] Recue par la plateforme                   optional   in    PA-R acknowledges
                |
                v
          [203] Mise a disposition                        optional   in    PA-R -> buyer
                |
                v
          [204] Prise en charge                           optional   --    buyer picked it up
                |
  BUYER SIDE   -  the same document is a supplier invoice in Dolibarr
                |
       +========+=============  BUYER'S DECISION  ==============================+
       |                 |                 |                 |                 |
       v                 v                 v                 v                 v
     [205]             [206]             [207]             [208]             [210]
   Approuvee      Approuvee part.      En litige         Suspendue          Refusee
   optional          optional          optional          optional          MANDATORY
 OPTION+MANUAL          --                --                --              MANUAL
       |                 |                 |                 |                 |
       |                 |                 |                 v                 v
       |                 |                 |          [209] Completee      TERMINAL
       |                 |                 |          seller adds the    nothing more
       |                 |                 |         missing document       is due
       |                 |                 |                 |
       |                 |                 +-----------------+
       |                 |                          |
       |                 |                          +-->  back to the buyer's decision
       |                 |
       +--------+--------+
                |
                v
          [211] Paiement transmis                         optional   OPTION + MANUAL
          the buyer tells the seller it has paid
                |
                v
          [212] Encaissee                                 MANDATORY when VAT is due on collection
          the seller reports every cash-in                AUTOMATIC  (no option)

  Legend of the right-hand markers, module side:
    in         the module only receives this status and displays it
    --         nothing implemented, neither sending nor a button
    MANUAL     button on the invoice card, a human decides
    OPTION     sent automatically, but only when the setup option is on (off by default)
    AUTOMATIC  sent by the module, with no option to turn it off
```

---

## 3. Code by code, in the module

### 200 / 201 / 202 / 203 / 213 — the platform's own statuses
Purely inbound. The synchronization cron stores whatever the platform reports and the card shows the
label from `EInvoicing::STATUS_LABEL_KEYS` (`class/einvoicing.class.php`). They are removed from the
sendable list on purpose (`getEinvoiceStatusOptions()`, `$onlySendable`): a plain user has no
business claiming its own invoice was deposited.

⚠ **201/202/203 are optional**, so their absence does not prove that the delivery failed. The only
reliable negative signal is **213**. "My invoice left" is proven by 200; "it arrived at the other
platform" is, strictly speaking, only provable by the recipient itself.

### 204 Prise en charge, 206 Approuvée partiellement, 207 En litige, 208 Suspendue, 209 Complétée
Not implemented for sending. They are unset in `getEinvoiceStatusOptions()` with the comment
"Remove statuses that are not supported for now". The reason codes 206/207/208 would need are already
declared (`REASONS_CODE_FOR_STATUS`, `STATUS_REQUIRING_REASONS`), so the missing part is the UI and
the provider call, not the data model.

### 205 Approuvée — the only optional status the module can automate
- Manual: button on the supplier invoice card, from
  `getSendableStatusesForReceivedInvoice()` (`class/einvoicing.class.php`), rendered in
  `class/actions_einvoicing.class.php`.
- Automatic: option **`EINVOICING_SEND_APPROVED_ON_VALIDATION`**, *off by default*
  (`admin/setup_options.php`). When on, `BILL_SUPPLIER_VALIDATE` sends it
  (`core/triggers/interface_98_modEInvoicing_EInvoicingTriggers.class.php`), the rationale being that
  validating a supplier invoice in Dolibarr *is* the act of accepting it.
- Guards (`SupplierInvoiceHelper::shouldSendApprovedOnValidation()`): once per invoice, never on an
  invoice that already got a 205 or a 210, never on the credit note of a refused invoice.
- A send failure never rolls back the validation; it is logged and shown, and the button remains.

⚠ Automating 205 **closes the lifecycle for a refusal**: once approved, 210 is no longer offered.
That is exactly why the option is off by default — nothing in the norm asks for an automatic 205.

### 210 Refusée — mandatory, but manual by design
The norm requires that a buyer be *able* to refuse; it never asks for an automatic refusal, and no
automatism could decide it. Manual button only, with a reason code picked from
`REASONS_CODE_FOR_STATUS[STATUS_REFUSED]`. Terminal: once sent, the whole button group disappears
(`getSendableStatusesForReceivedInvoice()` returns an empty array).

### 211 Paiement transmis — courtesy to the vendor
Option **`EINVOICING_SEND_PAYMENT_SENT_STATUS`**, *off by default*: it costs one platform flow per
invoice and the reform does not require it. When on, `BILL_SUPPLIER_PAYED` sends it once per invoice
(a payment deleted and re-recorded does not send it twice), only if the paid amount is > 0 and the
invoice really came from the platform. Also reachable by hand, and it stays offered after a 205 —
approve then pay is the normal order (issue #548).

### 212 Encaissée — the only outbound status with no off switch
Sent on `PAYMENT_CUSTOMER_CREATE`, **once per payment and not once per invoice**: the reform expects
the date and amount of *every* cash-in, so a two-instalment invoice owes two statuses. Hooking the
payment creation also covers invoices that stay partially paid forever, and skips write-offs.

It is only owed when VAT is due on collection: `needCashedInStatus()` answers from the VAT scheme of
the company (`einvoicingVatDueOnCollection()`), with down payments always reported (CGI art. 269-2).
Skipped when the invoice never reached the platform, or when its deposit was refused
(`STATUS_ERROR`) — there would be nothing to attach the cash-in to.

---

## 4. Cross-cutting rules

- **Kill switches**: `EINVOICING_DISABLE_SYNC_DOLI_TO_AP` suppresses every outbound status;
  `EINVOICING_DISABLE_SYNC_AP_TO_DOLI` suppresses the inbound side and the buttons with it.
- **Never undo a business act**: a failed status send is logged (`dol_syslog`) and shown, but never
  escalated to a trigger error — it must not roll back a validation or a recorded payment.
- **France only**: the setup screen marks 200/210/212/213 as *Mandatory* only when
  `$mysoc->country_code == 'FR'`.
- **The status list is narrowed by history**, not by a state machine: a status already accepted by the
  platform is not offered again; 210 closes everything; 205 closes only the refusal.

## 5. Sources

- DGFiP / AIFE, external specifications B2B: <https://www.impots.gouv.fr/specifications-externes-b2b>
- Lifecycle statuses, mandatory vs optional: <https://frenchinvoice.fr/reforme-2026/cycle-de-vie-facture>
- The four mandatory statuses: <https://libeo.io/fiches-pratiques/statuts-cycle-vie-facture-electronique>
