# STANCER FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## Features

Collect card payments and SEPA direct debits straight from Dolibarr, with the
French payment platform [Stancer](https://www.stancer.com/).

- **Card payment**: online payment link sent to the customer from an invoice,
  an order or a proposal, secure payment page (3-D Secure), deposit on orders
  and proposals, invoice classified as paid once the payment is confirmed
- **SEPA direct debit**: public page where the customer enters the IBAN, SEPA
  mandate PDF created automatically, optional electronic signature with
  UptoSign, automatic collection of due invoices
- **SEPA rejections**: customer and administrator notified, rejection fee
  invoice created automatically if you wish
- **Follow-up**: dashboard, lists of payments, payouts, refunds and disputes,
  Stancer tab on every thirdparty card
- **Synchronisation** with Stancer, on demand or by a scheduled job
- **Emails** of confirmation, error and reminder after a refused payment
- **Accounting**: Stancer fees booked on the bank account, payouts reconciled,
  accounting gaps detected
- **Associations**: online payment of membership fees and donations
- **Multi-company**, test mode and production mode

Other external modules are available on [Dolistore.com](https://www.dolistore.com/index.php?controller=search&orderby=position&orderway=desc&tag=&website=marketplace&search_query=cap-rel&submit_search=).

## Requirements

- Dolibarr 15.0 or above
- PHP 7.4 or above
- The Banks and cash and Direct debit modules enabled
- A Stancer account and its API keys

## Documentation

User documentation: [doc.cap-rel.fr/stancer](https://doc.cap-rel.fr/stancer/)

## Translations

Translations can be completed manually by editing files into directories *langs*.

<!--

## Installation

### From the ZIP file and GUI interface

Go into menu ```Home - Setup - Modules - Deploy external module``` and upload the zip file.

### <a name="final_steps"></a>Final steps

From your browser:

  - Log into Dolibarr as a super-administrator
  - Go to "Setup" -> "Modules"
  - You should now be able to find and enable the module

-->

## Support

Every help, support or maintenance request goes through:

[https://cap-rel.fr/sav-module-dolibarr/](https://cap-rel.fr/sav-module-dolibarr/)

## Licenses

### Main code

GPLv3 or (at your option) any later version. See file COPYING for more information.

### Documentation

All texts and readmes are licensed under GFDL.
