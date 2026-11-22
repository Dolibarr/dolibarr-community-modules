---
title: "Automatic tasks"
weight: 60
description: "Configuration of scheduled tasks (cron) for synchronisation, automatic direct debit and reminders."
source_hash: "8c5efb9c"
---

# Automatic tasks

The Stancer module registers five scheduled tasks in the Dolibarr cron task manager. By default, they are all **disabled** and must be enabled manually.

Access the scheduled tasks in **Home > Setup > Scheduled tasks** and look for the tasks starting with "Stancer".

![List of Stancer scheduled tasks in the Dolibarr configuration with their frequency and status](screenshots/taches-planifiees.webp)

## Payment and payout synchronisation

**Task name**: StancerCheckPay

This task performs the same operation as the "Refresh" button of the payment and payout lists:

1. Retrieves the new payments from the Stancer API.
2. Checks the status of local payments pending.
3. Retrieves the new payouts.
4. Deletes obsolete payment drafts.

When a customer paid an order or a quote without coming back to the site (tab closed, interrupted 3-D Secure return), this task creates the invoice and records the payment, exactly as the return page would have. A payment already recorded is never taken a second time.

> **Important:** this task is installed disabled. As long as it does not run, these payments stay collected by Stancer with no invoice nor payment in Dolibarr. In live mode, the module setup page shows a warning while it is inactive.

**Recommended frequency:** daily (once a day). The task is configured by default to run at a random hour between 0 am and 6 am.

> **Tip:** if you process a large volume of payments, you can increase the frequency to several times a day. For moderate use, a daily run is enough.

## Automatic invoice direct debit

**Task name**: StancerCheckTakePayments

This task looks for overdue unpaid invoices and automatically launches the payment (card or SEPA) for customers having a registered payment method.

The process is as follows:

1. Search for unpaid invoices whose due date has passed.
2. For each invoice, check whether the customer has an active credit card or SEPA mandate.
3. Launch the payment via the Stancer API.
4. Record the result (success or error).

**Recommended frequency:** daily.

Each run shows the number of invoices examined, for SEPA direct debit and for card. An output saying "disabled" means that payment mode is switched off in the Stancer setup.

The task ends in error, with the reason in its output, in two cases:

- SEPA direct debit (or card payment) is disabled in the setup while invoices wait for that payment mode on the Stancer account: they would never be collected;
- the invoices to collect cannot be selected, for instance because the Stancer bank account is not chosen in the setup.

The SEPA tab of the setup also shows a warning when SEPA direct debit is disabled while Stancer SEPA mandates exist.

> **Important:** this task respects the SEPA configuration settings (notification delay, contract restriction, CB/SEPA switch amount). Check your [SEPA configuration](/stancer/sepa) before enabling it.

## Paid invoice check

**Task name**: StancerCheckInvoicesPaid

This task checks recent payments and marks the corresponding invoices as "Paid" if the total amount has been collected. It acts as a safety net for invoices that would not have been marked automatically when the payment was received.

**Recommended frequency:** daily.

## Sending reminders

**Task name**: StancerSendPaymentReminders

This task sends reminder emails to customers whose payment failed, according to the schedule and templates (soft, firm, monthly) defined in the [notification configuration](/stancer/notifications). It scans failed payments less than 6 months old and automatically chooses the right email template according to the reminder rank.

**Recommended frequency:** daily.

> **Note:** reminders are only sent if the option is enabled in the Mail tab of the configuration. A summary of the reminders sent can be sent to the administrator if the option is checked.

## Automatic sending of invoices at validation (deferred)

**Task name**: StancerSendPendingValidationMails

This task automatically emails validated invoices whose grace delay has passed, unless they were sent manually in the meantime. It does nothing if the **Email the invoice at validation** option is not enabled. With a delay of 0, this task sends the invoice at its next run: a mail sent at the very moment of validation would still carry the provisional reference and PDF.

At each run, the task:

1. Looks for scheduled sendings whose deadline has passed.
2. Cancels the sending if the invoice is no longer validated (draft, abandoned, deleted).
3. Cancels the sending if the invoice was sent manually after scheduling.
4. Otherwise, sends the email to the customer via the configured template and marks the sending as done.

**Recommended frequency:** hourly (default).

> **Note:** the list of pending sendings is visible in the Stancer dashboard.

## Enabling and configuration

To enable a scheduled task:

1. Go to **Home > Setup > Scheduled tasks**.
2. Click the Stancer task to enable.
3. Set the status to **Enabled**.
4. Configure the desired execution frequency.
5. Save.

> **Prerequisite:** the Dolibarr cron must be configured on your server for the scheduled tasks to run. See the Dolibarr documentation for system cron configuration.

## Execution order

The tasks run independently, but the recommended logical order is:

1. **StancerCheckPay** (synchronisation) -- to have up-to-date data
2. **StancerCheckTakePayments** (direct debit) -- to launch the payments
3. **StancerCheckInvoicesPaid** (check) -- to mark paid invoices
4. **StancerSendPaymentReminders** (reminders) -- to follow up failures
5. **StancerSendPendingValidationMails** (deferred sending at validation) -- to send invoices whose grace delay has passed

In case of an error during synchronisation (for example, an API authentication error), the following tasks in the chain are not blocked because they are independent. Each task handles its own errors and logs them in the `dolibarr.log` file.
