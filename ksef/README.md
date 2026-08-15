# KSeF for Dolibarr ERP CRM

<p align="center" width="100%">
<img alt="Icon" src="./img/ksef.png" width="20%" />
</p>

## [Po Polsku](README_PL.md)

## Description

A complete KSeF (Krajowy System e-Faktur) e-invoicing integration for Dolibarr. Generate and submit FA(3) invoices to Poland's National e-Invoice System, handle correction chains, and import incoming invoices. In production use since the system went live in February 2026.

## Features

- Generation, signing, and submission of FA(3) XML invoices to KSeF (online and offline modes)
- Correction invoices (KOR) with chain tracking and payment settlement across corrections
- Download, sync, and import of incoming invoices with supplier and product matching
- Batch import with auto-creation of suppliers and products
- KSeF system monitoring via Latarnia/Lighthouse API with outage warnings
- NBP exchange rate integration for foreign currency invoices
- KSeF-style PDF invoice visualizations for outgoing and incoming invoices
- Automatic QR code on PDF invoices (online + offline)
- Tax exemption support with configurable legal basis
- Configurable notes, extrafields, and order/contract references in FA(3) XML
- Per-environment authentication (Test/Demo/Production) with token or certificate
- GUS/REGON company lookup by NIP (requires GUS API key - instructions in How To Use)
- REST API endpoints
- Third party KSeF exclusion (e.g. B2C customers)
- In-module How To Use documentation
- Scheduled jobs for status checking, incoming sync, offline retry, and KSeF monitoring

## Documentation
There is complete in-module documentation in the “How To Use” tab. However, we also have finished a more detailed documentation with a bunch of screenshots which is available on our website as well in English and Polish. If you run into issues or think something is missing, please let us know and we’ll add it!

https://inpointautomation.com/content/ksef-integration-module-for-dolibarr/

## Requirements

### System Requirements

- Dolibarr: Tested and works on v22 and above
- PHP: 7.4 or higher
- PHP Extensions:
    - `OpenSSL`
    - `cURL`
    - `DOM`
- Dolibarr Barcode Module: for generating QR codes on PDFs

### KSeF Account

- A valid Polish NIP (tax identification number) configured in your company settings.
- An active KSeF account with an authorization token generated from the official KSeF portal for your chosen
  environment (Test, Demo, or Production).

## Installation

The recommended installation method is from a ZIP file.

1. Download the latest module ZIP file from the [release page](https://github.com/InPoint-Automation/Dolibarr-KSeF-Module/releases)
2. In Dolibarr, go to Home → Setup → Modules/Applications
3. Select the Deploy/install external module tab
4. Upload the module's ZIP file
5. Find the KSeF Integration module in the list and enable it

## Contributing

Feel free to fork this module or contribute a PR to help improve the Dolibarr community

Build instructions can be found in the developer readme [DEV.md](DEV.md)

## Licenses

### Main code

This project is licensed under the AGPLv3+

See [LICENSE](LICENSE) file for details.

### Third-Party Libraries

This module currently includes the following third-party libraries in the `lib/vendor` directory:

- [phpseclib/phpseclib](https://github.com/phpseclib/phpseclib) - MIT License - Used for API authentication and invoice
  encryption

- [paragonie/constant_time_encoding](https://github.com/paragonie/constant_time_encoding) - MIT License - Used by
  phpseclib

- [composer](https://github.com/composer/composer) - MIT License - Composer files included in vendor directory, not used at runtime

## Legal Notice

This module is not officially endorsed by or affiliated with:

- The Polish Ministry of Finance
- The KSeF (Krajowy System e-Faktur) system operators

Use of this module does not guarantee compliance with Polish tax regulations. Users are responsible for ensuring their
invoicing practices comply with current Polish law.

## Credits

Thanks to Éric Seigne and the FacturX plugin (https://registry.inligit.fr/cap-rel/dolibarr/plugin-facturx). Without
learning from their module, this project would have taken ages longer to complete

This module was developed by InPoint Automation Sp. z o.o.

## Changelog
See [CHANGELOG.md](CHANGELOG.md) for the full version history.
