---
title: "FAQ"
weight: 80
description: "Answers to frequently asked questions and resolution of common issues."
source_hash: "e6367a44"
---

# FAQ

## Where do I find my Stancer API keys?

Log in to your Stancer dashboard on [manage.stancer.com](https://manage.stancer.com/). Your API keys (public and private) are available in your account settings, in test and production versions.

## The module displays "API authentication error"

This message appears when the Stancer API rejects your keys (HTTP 401 code). Check:

- That your API keys are correctly entered in the [configuration](/stancer/configuration).
- That you did not swap the public key and the private key.
- That the mode (test/production) matches the keys used (`ptest_`/`stest_` keys for test, `pprod_`/`sprod_` for production).

## Payouts do not appear in the list

Payouts are not available in test mode. Switch to production mode to see real payout data.

If you are in production mode and the list is empty, click the **Refresh** button to synchronise the data from the Stancer API.

## A payment is recorded but the invoice is not marked as paid

Several possible reasons:

- The payment amount does not cover the invoice's total balance (partial payment).
- The "Mark the invoice as paid" option is disabled in the [card configuration](/stancer/paiements-cb).
- The user configured for automated actions does not have sufficient permissions on invoices.

Check these points, then run the **StancerCheckInvoicesPaid** scheduled task or mark the invoice manually.

## How do I test the module without making real payments?

Use test mode:

1. In the [general configuration](/stancer/configuration), disable production mode.
2. Enter your test API keys (`ptest_` and `stest_`).
3. Run your tests with the test card numbers provided by Stancer.

When you switch to production, use the **Clean test data** button to delete the test payments.

## The SEPA direct debit is not launched automatically

Check the following points:

- The **StancerCheckTakePayments** scheduled task is enabled and the Dolibarr cron works.
- The customer has an active SEPA mandate (checkable in the Stancer tab of their third-party record).
- The SEPA notification delay is respected (14 days by default in France).
- If the "Only for customers under contract" option is enabled, the customer must have an active contract.
- The invoice has the "Direct debit" payment method and an associated RIB/IBAN.

## A SEPA direct debit was rejected, what happens?

When a rejection is detected during synchronisation:

1. The original invoice is reopened ("Unpaid" status).
2. If automatic fee invoicing is enabled, a rejection fee invoice is created.
3. An email is sent to the customer and the administrator with the rejection reason.

The rejection reason (ISO 20022 code) is visible in the payment list. See the [SEPA page](/stancer/sepa) for the list of common rejection codes.

## How do I refund a customer?

1. Go to **Bank > Stancer > Payments**.
2. Find the payment concerned and open its record.
3. Click **Refund**.
4. Enter the amount (full or partial) and confirm.

The refund is processed by the Stancer API. The associated invoice is automatically reopened.

## The Stancer API returns an empty response (JSON null)

This problem can occur in case of Stancer API overload or a temporary network problem. The module logs the error in `dolibarr.log`. Try again by clicking the **Refresh** button of the list concerned.

If the problem persists, check your server's connectivity to `api.stancer.com`.

## How do I view the module logs?

The module records its actions in Dolibarr's main log file (`dolibarr.log`). Entries are prefixed with "stancer". Search for this string to filter the relevant logs:

```bash
grep "stancer" /path/to/dolibarr/documents/dolibarr.log
```

## The payment QR code does not display

The module can generate a QR code for payment links. If the QR code does not display, check that the PHP `gd` library is installed and enabled on your server.

## How does action deduplication work?

The module uses an event system (ActionComm) with unique codes to avoid processing the same event several times. For example, if a SEPA rejection is detected during two successive synchronisations, the invoice is reopened only once and only one notification email is sent.

These events are visible in the **Events** tab of the invoice concerned.
