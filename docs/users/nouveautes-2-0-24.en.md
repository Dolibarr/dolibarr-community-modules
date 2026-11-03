---
title: "What's new in version 2.0.24"
weight: 300
description: "Payment link sent from an order or an invoice, card without 3-D Secure, new attempt after a refusal, and a series of security hardening changes."
source_hash: "dece85f5"
---

# What's new in version 2.0.24

This version makes card collection easier when a first payment fails, and clearly strengthens the module's security: access controls, public pages, logs and separation between companies. It also fixes several synchronisation and accounting issues.

## Send the payment link from an order or an invoice

A **Send payment link** button appears on validated orders and invoices. It sends the customer the link to the online payment page, to the thirdparty's email address or, failing that, to one of its contacts. Details: [Credit card payments](/stancer/paiements-cb).

## Pay again after a refusal

A refused card payment no longer blocks the customer: the same link starts a new attempt. The return page now explains what happened, and confirms that nothing was debited when that is the case.

## Card payment without 3-D Secure, document by document

For a customer whose card cannot authenticate, you can allow payment without 3-D Secure on a specific order or invoice. The authorisation is recorded in the document events. Without 3-D Secure, a fraudulent dispute remains at your charge. Details: [Credit card payments](/stancer/paiements-cb).

## Security

- Actions that debit, refund or record a payment require the module write right and a security token.
- The amount of an invoice, an order or a quote is always computed by Dolibarr, never taken from the payment link.
- An invoice is marked as paid only if its payments really cover its total.
- The public card and IBAN pages require the online payment security key, and never act in the name of the first administrator.
- Card numbers, security cryptograms and the security key no longer appear in the logs, and the Stancer API certificate is verified.
- Payments, refunds, disputes and direct debits of each company remain separate in multi-company mode.

## Bug fixes

- Deferred invoice validation emails are sent again: the scheduled task was failing at each run.
- A SEPA payment forced from the repair tool is recorded as a direct debit, and not as a card payment.
- Donations and event registrations settled with Stancer are posted to the Stancer bank account.
- The same payout, refund or dispute can no longer be recorded twice.
- A user who created Stancer records can be deleted.
- The raw Stancer API response window works in the module installed from the archive.
- Payment launch messages are translated.

## Update

1. Replace the module files with those of the new archive.
2. In **Home > Setup > Modules**, disable then re-enable the Stancer module: this step applies the new database constraints and finishes the update.
3. Check that the **user for automated actions** is filled in the [configuration](/stancer/configuration): public pages now refuse to act without it or without the author of the thirdparty concerned.
4. On a thirdparty's Stancer tab, SEPA mandate verification is launched by the **Synchronise SEPA mandates with Stancer** button, and no longer on display.
