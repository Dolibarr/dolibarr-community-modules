<?php
/* Copyright (C) 2026	Charles Peltier
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       test/phpunit/EinvoicingLifecycleLibTest.php
 * \ingroup    einvoicing
 * \brief      Actor classification of lib/einvoicing_lifecycle.lib.php, read by the tracking tab.
 */

global $conf, $user, $langs, $db;

$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}
require_once $dolibarrHtdocs . '/master.inc.php';
dol_include_once('einvoicing/class/einvoicing.class.php');
dol_include_once('einvoicing/lib/einvoicing_lifecycle.lib.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class EinvoicingLifecycleLibTest
 */
class EinvoicingLifecycleLibTest extends CommonClassTest
{
	/**
	 * Every event we emitted belongs to the seller, whatever its code.
	 *
	 * @return void
	 */
	public function testOutgoingEventsBelongToSeller()
	{
		$this->assertSame('fournisseur', einvoicingLifecycleFlux(EInvoicing::STATUS_PAYMENT_SENT, 'out'));
		$this->assertSame('fournisseur', einvoicingLifecycleFlux(EInvoicing::STATUS_ISSUED, 'OUT'));
	}

	/**
	 * The deposit acknowledgement is the seller's step: it is the platform saying it received our
	 * submission, so it lands on the seller card, not on the platform card.
	 *
	 * @return void
	 */
	public function testDepositedBelongsToSeller()
	{
		$this->assertSame('fournisseur', einvoicingLifecycleFlux(EInvoicing::STATUS_DEPOSITED, 'in'));
	}

	/**
	 * The network statuses that follow the deposit are the platform's own steps.
	 *
	 * @return void
	 */
	public function testNetworkStatusesBelongToPlatform()
	{
		foreach (array(EInvoicing::STATUS_ISSUED, EInvoicing::STATUS_RECEIVED, EInvoicing::STATUS_AVAILABLE, EInvoicing::STATUS_REJECTED) as $status) {
			$this->assertSame('pdp', einvoicingLifecycleFlux($status, 'in'), 'status ' . $status);
		}
	}

	/**
	 * Anything else received is the buyer processing the invoice.
	 *
	 * @return void
	 */
	public function testBuyerStatusesBelongToBuyer()
	{
		foreach (array(EInvoicing::STATUS_TAKEN_OVER, EInvoicing::STATUS_APPROVED, EInvoicing::STATUS_REFUSED, EInvoicing::STATUS_PAID) as $status) {
			$this->assertSame('client', einvoicingLifecycleFlux($status, 'in'), 'status ' . $status);
		}
	}

	/**
	 * The note under the seller card tells "not transmitted" apart from "deposited, waiting for the
	 * platform", and says nothing once the local status is already a lifecycle code.
	 *
	 * @return void
	 */
	public function testLocalStatusNote()
	{
		$this->assertSame('EInvLocalStatusNotYetTransmitted', einvoicingLifecycleLocalStatusNote(EInvoicing::STATUS_UNKNOWN));
		$this->assertSame('EInvLocalStatusNotYetTransmitted', einvoicingLifecycleLocalStatusNote(EInvoicing::STATUS_GENERATED));
		$this->assertSame('EInvLocalStatusAwaitingPlatform', einvoicingLifecycleLocalStatusNote(EInvoicing::STATUS_AWAITING_VALIDATION));
		$this->assertSame('EInvLocalStatusAwaitingPlatform', einvoicingLifecycleLocalStatusNote(EInvoicing::STATUS_AWAITING_ACK));
		$this->assertSame('', einvoicingLifecycleLocalStatusNote(EInvoicing::STATUS_ERROR));
		$this->assertSame('', einvoicingLifecycleLocalStatusNote(EInvoicing::STATUS_DEPOSITED));
		$this->assertSame('', einvoicingLifecycleLocalStatusNote(EInvoicing::STATUS_ISSUED));
	}
}
