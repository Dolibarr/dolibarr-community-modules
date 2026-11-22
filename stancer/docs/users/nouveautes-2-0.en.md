---
title: "What's new in version 2.0"
weight: 399
description: "All the new features and improvements of the 2.0 versions of the Stancer module."
---

# What's new in version 2.0

## Version 2.0.28

Released on November 17, 2026. This version makes the billing of orders and quotes paid online reliable: one payment gives one invoice, only one, even when the customer reloads the page or never comes back to the site. It adds a deposit invoice of the exact amount collected, and the scheduled tasks now report what they cannot do.

### Orders and quotes billed only once

When a customer pays an order or a quote online, the invoice, its validation, the payment and the bank entry are recorded as one block. If a step fails, nothing is kept: no validated invoice is left without its payment any more.

Reloading the return page, or coming back to it late, no longer bills a second time: the module finds the payment already recorded. If an unpaid invoice already exists for the document, created by hand for instance, the payment is recorded on it instead of creating a new one. Details: [Card payments](/stancer/paiements-cb).

### Payments of customers who never come back to the site

A customer who closed the browser right after paying, or whose 3-D Secure return was interrupted, left a payment collected by Stancer with no invoice nor payment in Dolibarr. The scheduled task StancerCheckPay now creates the invoice of the order or the quote and records the payment, exactly as the return page would have.

This task is installed disabled. In live mode, the module setup page shows a warning while it does not run. Details: [Scheduled tasks](/stancer/taches-automatiques).

### Deposit of the collected amount

The **Bill the amount actually collected on an order or a quote as a deposit** option, in the Credit card tab of the setup, creates a deposit invoice of the exact amount paid when it is below the total of the document. The invoice takes the VAT rates of the document pro rata, to the cent, without requiring a deposit percentage nor deposit payment terms. In every case, the recorded payment is the amount Stancer actually collected. Details: [Card payments](/stancer/paiements-cb).

### Disabled debits: the task reports it

When SEPA direct debit or card payment is disabled in the setup while invoices wait for that payment mode, the debit task ends in error with the reason in its output, instead of collecting nothing silently. Each run also shows the number of invoices examined. The SEPA tab of the setup shows a warning when direct debit is disabled while Stancer SEPA mandates exist.

### Bug fixes

- An invoice emailed at validation goes with its final number and PDF, never as a draft: with a sending delay of 0, the hourly scheduled task sends it.
- On a quote paid as a deposit, the recorded payment is the amount collected, no longer the deposit percentage computed again on the quote total.
- The scheduled task could record the payment of an order that had no invoice yet on another invoice: it now creates the invoice of the order.

### Upgrade

Replace the module files. Then check, in the list of scheduled jobs, that the StancerCheckPay task is active.

## Version 2.0.26

Released on November 3, 2026. This version moves your customers to direct debit as soon as they sign their SEPA mandate, and makes payment tracking easier to read from the invoices. It also lets you collect customers who only left a mobile number, and fixes several issues around rejected direct debits.

### Template invoices on direct debit as soon as the mandate is signed

When a customer signs their SEPA mandate, their template invoices can switch automatically to direct debit on the Stancer bank account, including the ones they paid by card. The signed mandate becomes their default mandate. The payment mode of the thirdparty does not change: a one-off invoice keeps the mode you choose.

The switch happens on the UptoSign electronic signature, or with the **Mandate signed** button of the Stancer tab of the thirdparty for a mandate signed on paper. The option is disabled by default. Details: [SEPA direct debits](/stancer/sepa).

### Stancer payments in the invoice events

Each Stancer payment collected, refused, failed or disputed appears once in the invoice events, as Stripe direct debits do. Other modules can rely on the refusal event (`AC_PAYMENT_STANCER_KO`) to space out reminders or suspend a service. Details: [Payment tracking](/stancer/suivi-paiements).

A refused card payment now sends the failure email to the customer and the refusal event, like the other failures.

### Fewer repeated emails

The administrator is no longer warned at each daily synchronisation about the same failed payment: they receive a single email per payment.

### Customers reachable on their mobile only

A customer who only left a mobile number, with no email address, can pay a quote, an order or an invoice with Stancer. National numbers and those of members are recognised. Numbers of the French overseas departments are sent to Stancer with their own country code, even when the thirdparty country is France.

### Files attached by other modules

The email that sends an invoice also attaches the files provided by other modules, for instance the consumption statement of a reseller invoice.

### Bug fixes

- An invoice paid again after a rejected SEPA direct debit stays marked as paid, instead of going back to "started" with nothing left to pay.
- The result of the scheduled task lists the fully paid invoices that could not be marked as paid.
- The module refuses to be enabled if one of its tables could not be created, instead of being enabled without it.

### Update

Replace the module files: no other step is needed. To switch template invoices to direct debit when mandates are signed, enable the option in the SEPA setup of the module.

## Version 2.0.24

Released on September 24, 2026. This version makes card collection easier when a first payment fails, and clearly strengthens the module's security: access controls, public pages, logs and separation between companies. It also fixes several synchronisation and accounting issues.

### Send the payment link from an order or an invoice

A **Send payment link** button appears on validated orders and invoices. It sends the customer the link to the online payment page, to the thirdparty's email address or, failing that, to one of its contacts. Details: [Credit card payments](/stancer/paiements-cb).

### Pay again after a refusal

A refused card payment no longer blocks the customer: the same link starts a new attempt. The return page now explains what happened, and confirms that nothing was debited when that is the case.

### Card payment without 3-D Secure, document by document

For a customer whose card cannot authenticate, you can allow payment without 3-D Secure on a specific order or invoice. The authorisation is recorded in the document events. Without 3-D Secure, a fraudulent dispute remains at your charge. Details: [Credit card payments](/stancer/paiements-cb).

### Security

- Actions that debit, refund or record a payment require the module write right and a security token.
- The amount of an invoice, an order or a quote is always computed by Dolibarr, never taken from the payment link.
- An invoice is marked as paid only if its payments really cover its total.
- The public card and IBAN pages require the online payment security key, and never act in the name of the first administrator.
- Card numbers, security cryptograms and the security key no longer appear in the logs, and the Stancer API certificate is verified.
- Payments, refunds, disputes and direct debits of each company remain separate in multi-company mode.

### Bug fixes

- Deferred invoice validation emails are sent again: the scheduled task was failing at each run.
- A SEPA payment forced from the repair tool is recorded as a direct debit, and not as a card payment.
- Donations and event registrations settled with Stancer are posted to the Stancer bank account.
- The same payout, refund or dispute can no longer be recorded twice.
- A user who created Stancer records can be deleted.
- The raw Stancer API response window works in the module installed from the archive.
- Payment launch messages are translated.

### Update

1. Replace the module files with those of the new archive.
2. In **Home > Setup > Modules**, disable then re-enable the Stancer module: this step applies the new database constraints and finishes the update.
3. Check that the **user for automated actions** is filled in the [configuration](/stancer/configuration): public pages now refuse to act without it or without the author of the thirdparty concerned.
4. On a thirdparty's Stancer tab, SEPA mandate verification is launched by the **Synchronise SEPA mandates with Stancer** button, and no longer on display.
