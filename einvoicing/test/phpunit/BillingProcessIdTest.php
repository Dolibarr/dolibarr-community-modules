<?php
/* Copyright (C) 2026 Pierre Grasswill
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
 * or see https://www.gnu.org/
 */

/**
 *      \file       test/phpunit/BillingProcessIdTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the billing frame (BT-23).
 *                  BT-23 describes the billing case of the document, not a payment status: in the
 *                  AFNOR nominal use case (XP Z12-012 annexe B, UC1_F202500003) the invoice is
 *                  issued in frame S1 and stays S1 while the CDAR lifecycle reports 211 then 212.
 *                  The "already paid" frames B2/S2/M2 are therefore reserved to an invoice whose
 *                  amount was already received when it was issued, which BR-FR-CO-09 makes
 *                  concrete: BT-113 must equal BT-112 and BT-115 must be 0, both fatal.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// This module is deployed by symlinking this repository into htdocs/custom/einvoicing. Some test
// runners resolve the real (non-symlinked) path of this file before including it, which breaks a
// fixed "../../htdocs/master.inc.php" relative path. DOLIBARR_HTDOCS let's the developer/CI point
// explicitly at the Dolibarr instance to test against; otherwise we fall back to the relative path
// (valid when this file is reached through the symlink without realpath resolution).
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class BillingProcessIdTest extends CommonClassTest
{
	/**
	 * An in-memory invoice: getBillingProcessID() only reads lines, status, close_code and
	 * total_ttc, so nothing has to be written to the database.
	 *
	 * @param	int		$status			Facture::STATUS_* value
	 * @param	float	$totalTtc		Invoice total including VAT
	 * @param	array	$productTypes	product_type of each line (0 goods, 1 service)
	 * @param	string	$closeCode		Dolibarr close code, non-empty means closed for another reason than payment
	 * @return	Facture					The stub invoice
	 */
	private function makeInvoice($status, $totalTtc, array $productTypes = [0], $closeCode = '')
	{
		global $db;

		$invoice = new Facture($db);
		$invoice->status     = $status;
		$invoice->statut     = $status;
		$invoice->close_code = $closeCode;
		$invoice->total_ttc  = $totalTtc;
		$invoice->lines      = [];

		foreach ($productTypes as $type) {
			$line = new FactureLigne($db);
			$line->product_type = $type;
			$invoice->lines[] = $line;
		}

		return $invoice;
	}

	/**
	 * An invoice whose amount was already received when it was issued is the case B2/S2/M2
	 * describes, and BR-FR-CO-09 is satisfied because BT-113 does equal BT-112.
	 *
	 * @return void
	 */
	public function testFullyPaidAtIssuanceUsesTheAlreadyPaidFrame()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$this->assertSame('B2', $protocol->getBillingProcessID($this->makeInvoice(Facture::STATUS_CLOSED, 120.0, [0]), 120.0));
		$this->assertSame('S2', $protocol->getBillingProcessID($this->makeInvoice(Facture::STATUS_CLOSED, 120.0, [1]), 120.0));
		$this->assertSame('M2', $protocol->getBillingProcessID($this->makeInvoice(Facture::STATUS_CLOSED, 120.0, [0, 1]), 120.0));
	}

	/**
	 * An invoice closed as paid without matching payment records still reports BT-113 = 0, so it
	 * must not claim the already-paid frame: that combination is a fatal BR-FR-CO-09. This is what
	 * "Classify as paid" produces, and what a deposit invoice turned into a discount produces.
	 *
	 * @return void
	 */
	public function testClosedWithoutPaymentKeepsTheInitialFrame()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$this->assertSame('B1', $protocol->getBillingProcessID($this->makeInvoice(Facture::STATUS_CLOSED, 360.0, [0]), 0.0));
		$this->assertSame('S1', $protocol->getBillingProcessID($this->makeInvoice(Facture::STATUS_CLOSED, 360.0, [1]), 0.0));
	}

	/**
	 * A partially paid invoice is not "already paid" either.
	 *
	 * @return void
	 */
	public function testPartiallyPaidKeepsTheInitialFrame()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$this->assertSame('B1', $protocol->getBillingProcessID($this->makeInvoice(Facture::STATUS_CLOSED, 2220.0, [0]), 1000.0));
	}

	/**
	 * An invoice closed for another reason than payment (close_code set, e.g. abandoned) never
	 * claims the already-paid frame.
	 *
	 * @return void
	 */
	public function testClosedForAnotherReasonKeepsTheInitialFrame()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$invoice = $this->makeInvoice(Facture::STATUS_CLOSED, 120.0, [0], 'bankcharge');

		$this->assertSame('B1', $protocol->getBillingProcessID($invoice, 120.0));
	}

	/**
	 * A validated but unpaid invoice is the ordinary initial frame.
	 *
	 * @return void
	 */
	public function testValidatedUnpaidUsesTheInitialFrame()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$this->assertSame('B1', $protocol->getBillingProcessID($this->makeInvoice(Facture::STATUS_VALIDATED, 120.0, [0]), 0.0));
	}

	/**
	 * A final invoice consuming a deposit keeps the "after deposit" frame, which BR-FR-CO-08 ties
	 * to a document that is not itself a deposit invoice.
	 *
	 * @return void
	 */
	public function testFinalInvoiceAfterDepositUsesTheDepositFrame()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$invoice = $this->makeInvoice(Facture::STATUS_VALIDATED, 1200.0, [0]);
		$invoice->lines[0]->desc = '(DEPOSIT)';

		$this->assertSame('B4', $protocol->getBillingProcessID($invoice, 0.0));
	}
}
