---
title: "Associations"
weight: 65
description: "Online payment of membership fees and donations for associations via Stancer."
source_hash: "f8735111"
---

# Associations

The Stancer module supports online payment of membership fees and donations for associations using Dolibarr. The configuration is done in the **Associations** tab of the administration page.

![Associations tab of the Stancer configuration with the enabling option](screenshots/configuration-associations.webp)

## Enabling

Enable the **Enable Stancer for associations** option to make online payment available on member and donation records.

> **Prerequisite:** the **Members** and/or **Donations** modules must be enabled in Dolibarr.

## Membership fees

When the feature is enabled, a Stancer payment button appears on a member's record. The workflow is as follows:

1. The member receives a payment link (by email or via the public page).
2. They pay their membership fee online by credit card.
3. The payment is recorded in Dolibarr.
4. The membership fee is automatically validated.

The module links the payment to the member's Stancer customer account. If the member does not yet have a Stancer account, it is created automatically.

## Donations

The same mechanism works for donations:

1. A payment link is available on the donation record.
2. The donor pays online.
3. The donation is recorded and marked as paid.

## Public page

Payment links for membership fees and donations use the same public pages as standard payments. The customer does not need to log in to Dolibarr to make their payment.
