# Postponed flows — what a stock installation actually does

Memo. What "postponing a flow" means in this module, every case that asks for it, and what happens
to those cases **out of the box** — a module freshly activated, every delivery default left alone.
Written 2026-09-22 against `main` of the module.

---

## 1. The short answer

**Out of the box, no flow is ever postponed.**

The code has ten places that ask for it, but the single switch that acts on the request,
`EINVOICING_ENABLE_POSTPONE_FLOWS`, is **off**: it is not in the module's install constants, and the
setup screen does not even offer it — `admin/setup_options.php` only carries a comment describing
it. It exists for someone who sets it by hand in `llx_const`.

So on a stock installation, every case in section 4 does the opposite of postponing: it **aborts the
whole synchronization** on that flow, leaves every flow behind it unread, and — because nothing was
stored for it — **aborts at the same flow on the next run, and the one after**, until a human clears
the cause. That stall is the out-of-the-box behaviour, and it is deliberate: see section 7 for why.

---

## 2. The delivery defaults that decide this

| Constant | Default | Where the default comes from |
|---|---|---|
| `EINVOICING_ENABLE_POSTPONE_FLOWS` | **unset → off** | Nowhere. Not installed, not in the setup screen. |
| `EINVOICING_ENABLE_MANUAL_ACTION_QUEUE` | **unset → off** | Idem. The other opt-in, see section 8. |
| `EINVOICING_SYNC_MARGIN_TIME_HOURS` | `12` | `modEInvoicing::$const` |
| `EINVOICING_FLOWS_SYNC_CALL_SIZE` | `100` | `modEInvoicing::$const` |
| `EINVOICING_FLOWS_SYNC_CALL_LIMIT` | `1` | `modEInvoicing::$const` (shows the "max flows" field) |
| `EINVOICING_FLOWS_SYNC_CRON_SIZE` | `100` | Fallback in `Document::cronSyncFlows()` |
| Scheduled job `EInvoicingDocumentSync` | **created disabled** (`status => 0`), 1/hour once enabled | `modEInvoicing::$cronjobs` |

Two consequences worth stating plainly: a stock installation **synchronizes only when somebody
presses the button** in the flow list, and the retry of section 6 is therefore manual too.

---

## 3. What "postponed" means in the code

`syncFlow()` answers one flow with a `res` code, and `syncFlows()` decides what to do with it:

| `res` | Flow stored? | Meaning | Comes back on a later run? |
|---|---|---|---|
| `1` | yes | Imported / recorded | no |
| `0` | yes | Resolved, nothing to create — or nothing that a retry would change | no |
| `-1` **with** `postponeflow` | **no** | Could not be read *this time*, nothing was written | **yes**, if the window still reaches it |
| `-1` without `postponeflow` | no | Real failure | yes, and it stalls the run again |

`postponeflow` is not a state, a table or a timer. It is one key in the return array, and it says
exactly one thing: *nothing was persisted for this flow, so dropping it now loses nothing*. Whether
anything is done with that fact is the switch of section 1.

---

## 4. The ten cases that ask to be postponed

### 4.1 Importing a received supplier invoice

| Action code | Raised by | When |
|---|---|---|
| `CONVERSION_FORMAT_NOT_SUPPORTED` | `SuperPDPProvider::syncFlow()`, `EsalinkPDPProvider::syncFlow()` | The access point holds no document for this flow in a syntax the module can read (`Converted`, then `Original`, then the readable view all failed), or the account's conversion format is not set. |
| `LINKED_INVOICE_NOT_FOUND` (document) | `CIIProtocol::resolveMissingReferencedDocument()` | BT-25 names a preceding invoice absent from Dolibarr **and** the document declares an amount already paid (BT-113 > 0). Without BT-113, the reference is stepped over and the invoice imported. |
| `LINKED_INVOICE_NOT_FOUND` (line) | `CIIProtocol::postponeForMissingLineDocument()` | Same, one level down: a line's BT-128 names an invoice Dolibarr does not hold while the document declares an amount already paid. |
| `SUPPLIER_INVOICE_FOUND_WITH_BAD_AMOUNT` | `CIIProtocol` duplicate check | The reference already exists on this vendor, with a **different total**. Someone has to settle which is right. |
| `LINKED_INVOICE_LOOKUP_FAILED` | `SupplierInvoiceHelper::refLookupPostponedResult()` | The reference lookup itself could not answer — a database failure, or a reference matching several invoices. |

### 4.2 Reading a status the vendor sent about one of our supplier invoices

Both raised by `AbstractPDPProvider::postponeIncomingSupplierInvoiceStatus()`, action code
`CANT_READ_INCOMING_LIFECYCLE_STATUS`:

- the platform returned something other than 200 for **both** `Original` and `Converted`;
- the platform returned 200 with an **empty body** — the document is not materialized yet on its
  side, which is the textbook case that clears itself on a later run.

### 4.3 Recording a status about an invoice we sent

Raised by `SuperPDPProvider` and `EsalinkPDPProvider`, action code
`CANT_RECORD_SENT_INVOICE_LIFECYCLE_STATUS`:

- both `Original` and `Converted` failed to fetch;
- `Facture::fetch()` returned a **negative** code while looking the customer invoice up by the
  CDAR's `IssuerAssignedID` — that is an SQL failure; a reference simply matching no invoice
  returns 0 and is stored instead;
- an **exception** while processing the CDAR. Nothing is committed at that point, so the flow can be
  taken again whole.

---

## 5. What a stock installation does with those ten cases

The switch being off, `syncFlows()` never reaches its postpone branch. Instead:

1. the failure is turned into a **business message with the action to do** (create the missing
   invoice, fix the amount, set the conversion format…), shown in the synchronization report;
2. `$error++`, then **`break`** — out of the flow loop, and out of the batch loop;
3. the run answers `res = -1` and the report ends with *"Aborting synchronization due to errors."*
   and the `SyncAborted` line naming the flow it stopped on;
4. nothing was stored for that flow, so the **next run lists it again, and stops on it again**.

That last point is the one that surprises people: a single unreadable flow freezes reception
entirely, and it stays frozen until the cause is fixed, not until some retry budget runs out.

> The four *manual-action* business errors — `THIRDPARTY_NOT_FOUND`, `PRODUCT_NOT_FOUND`,
> `THIRDPARTY_DUPLICATE_VAT`, `THIRDPARTY_DUPLICATE_SUPPLIER_CODE` — abort the same way, with a
> different closing line: *"Aborting synchronization due to a business error. There is a manual
> action to do."* They never carry `postponeflow`; their opt-in alternative is section 8.

---

## 6. "Retry": what it is, and what it is not

There is **no retry mechanism** in the module — no attempt counter, no backoff, no delay, no retry
table, and nothing that distinguishes a first failure from a hundredth. What the code and the
messages call "retried on the next synchronization" means only this: *the flow was not stored, so
the next synchronization will list it again — if it still falls inside the window.*

The window is built from one cursor, `updatedAfter`:

- `getLastSyncDate($margin)` = `MAX(updatedat)` of `llx_einvoicing_document` for this provider and
  entity, **minus** `EINVOICING_SYNC_MARGIN_TIME_HOURS` (12 h by default);
- flows are then walked in batches of `EINVOICING_FLOWS_SYNC_CALL_SIZE` (100), the cursor moving to
  just before the last `updatedAt` of each batch — one batch of overlap, never a skipped flow;
- already-stored flowIds of the listing are discarded up front, so the overlap is cheap.

The margin exists **for** the postponed flows: a flow that stored nothing does not move
`MAX(updatedat)`, but flows synchronized *after* it do, and without the margin the cursor would
walk past it. Twelve hours is the default reach back.

---

## 7. Why the switch is off, and what turning it on changes

With `EINVOICING_ENABLE_POSTPONE_FLOWS = 1`, each of the ten cases stops aborting: the flow is
reported with its action, counted in `TotalPostponedSync` ("Total of flows postponed (not imported,
retried on the next synchronization)"), and the run **carries on with the flows behind it**.

The risk is written in the code, and it is the reason for the default:

> *When a flow is postponed, if some flow are recorded after, the postponed one may become out of
> range of the next sync and be definitely lost.*

Carrying on stores later flows, which moves `MAX(updatedat)` forward. Once the postponed flow falls
further back than the margin, **no later run ever lists it again** — a received invoice silently
never imported. Aborting is loud and blocks everything; postponing is quiet and can lose a document.
The module ships with the loud one.

---

## 8. The other opt-in: the manual-action queue

`EINVOICING_ENABLE_MANUAL_ACTION_QUEUE` (also off, also hidden) covers the *other* family — a
missing third party, a missing product, a supplier invoice found with a different amount. It is
**not** the same mechanism: the flow is written to a persistent queue
(`sync_pending_list.php`, counted as `TotalQueuedForManualAction`), the run carries on, and the flow
is retried **on demand** once the product or third party exists.

Because it persists the flow, it does not have the loss problem of section 7: leaving the
synchronization window no longer matters, the queue still holds it. That is the difference worth
remembering between the two opt-ins — postponing relies on the window, queueing does not.

---

## 9. In one table

| | Out of the box | `ENABLE_POSTPONE_FLOWS` | `ENABLE_MANUAL_ACTION_QUEUE` |
|---|---|---|---|
| Unreadable document, missing linked invoice, unreadable status | abort the whole run | skip, retried next run | not covered |
| Missing third party / product, amount mismatch | abort the whole run | not covered | queued, retried on demand |
| Flows behind the faulty one | not read | read | read |
| Can a document be lost? | no | **yes**, if it leaves the window | no |
| Where you see it | `SyncAborted` + the action to do | `TotalPostponedSync` | `TotalQueuedForManualAction` + the queue page |
