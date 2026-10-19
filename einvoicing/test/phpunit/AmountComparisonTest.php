<?php
/* Copyright (C) 2026 ATM Consulting
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
 *      \file       test/phpunit/AmountComparisonTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the guards that confront a received invoice with its document:
 *                  amounts are the same when they write the same cent, whatever the float noise or the
 *                  decimals of the instance.
 *      \remarks    To run this script as CLI: phpunit filename.php
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
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
dol_include_once('einvoicing/class/utils/SupplierInvoiceHelper.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class AmountComparisonTest extends CommonClassTest
{
	/**
	 * An in-memory supplier invoice with no deposit attached: totalsAgreeWithDocument() reads its totals only.
	 *
	 * @param	float	$totalTva	VAT total as stored
	 * @param	float	$totalTtc	Total including VAT as stored
	 * @param	int		$type		FactureFournisseur::TYPE_* value
	 * @return	FactureFournisseur
	 */
	private function makeSupplierInvoice($totalTva, $totalTtc, $type = FactureFournisseur::TYPE_STANDARD)
	{
		global $db;

		$invoice = new FactureFournisseur($db);
		$invoice->id = 0;
		$invoice->type = $type;
		$invoice->total_tva = $totalTva;
		$invoice->total_ttc = $totalTtc;

		return $invoice;
	}

	/**
	 * Invoice totals, announced totals, and whether the invoice agrees with its document.
	 *
	 * @return	array<string,array{0:float,1:float,2:float,3:float,4:int,5:bool}>
	 */
	public static function invoiceTotals(): array
	{
		return array(
			'same cents'                              => array(358.31, 2149.85, 358.31, 2149.85, FactureFournisseur::TYPE_STANDARD, true),
			'one cent of VAT'                         => array(358.28, 2149.85, 358.31, 2149.85, FactureFournisseur::TYPE_STANDARD, false),
			'one cent of total'                       => array(358.31, 2149.86, 358.31, 2149.85, FactureFournisseur::TYPE_STANDARD, false),
			'float noise of a sum of lines'           => array(0.1 + 0.2, 1.1 + 2.2, 0.3, 3.3, FactureFournisseur::TYPE_STANDARD, true),
			'instance on 3 decimals, half cents'      => array(1.015, 6.105, 1.02, 6.11, FactureFournisseur::TYPE_STANDARD, true),
			'instance on 3 decimals, other cent'      => array(1.014, 6.104, 1.02, 6.11, FactureFournisseur::TYPE_STANDARD, false),
			'credit note, same cents'                 => array(-358.31, -2149.85, 358.31, 2149.85, FactureFournisseur::TYPE_CREDIT_NOTE, true),
			'credit note on 3 decimals, half cents'   => array(-1.015, -6.105, 1.02, 6.11, FactureFournisseur::TYPE_CREDIT_NOTE, true),
			'credit note, one cent'                   => array(-358.30, -2149.85, 358.31, 2149.85, FactureFournisseur::TYPE_CREDIT_NOTE, false),
		);
	}

	/**
	 * The VAT and the total of the invoice are confronted to the cent the document writes.
	 *
	 * @dataProvider invoiceTotals
	 *
	 * @param	float	$totalTva		VAT total of the invoice
	 * @param	float	$totalTtc		Total of the invoice
	 * @param	float	$announcedTva	BT-110
	 * @param	float	$announcedTtc	BT-112
	 * @param	int		$type			Type of the invoice
	 * @param	bool	$expected		Whether they agree
	 * @return	void
	 */
	public function testTheInvoiceAgreesWithItsDocumentToTheCent($totalTva, $totalTtc, $announcedTva, $announcedTtc, $type, $expected)
	{
		$invoice = $this->makeSupplierInvoice($totalTva, $totalTtc, $type);

		$this->assertSame($expected, SupplierInvoiceHelper::totalsAgreeWithDocument($invoice, $announcedTva, $announcedTtc));
	}

	/**
	 * BT-115 is read when it answers BR-CO-16 to the cent, even off a document written with 3 decimals.
	 *
	 * @return	void
	 */
	public function testThePayableIsReadWhenItAnswersToTheCent()
	{
		$this->assertEquals(2149.85, SupplierInvoiceHelper::announcedTotalTtc(array('grandTotalAmount' => 2149.85, 'duePayableAmount' => 2149.85)));
		$this->assertEquals(1.02, SupplierInvoiceHelper::announcedTotalTtc(array('grandTotalAmount' => 1.015, 'duePayableAmount' => 1.02)), 'half a cent writes the same cent');
		$this->assertEquals(1.0, SupplierInvoiceHelper::announcedTotalTtc(array('grandTotalAmount' => 1.0, 'duePayableAmount' => 1.01)), 'one cent is a contradiction');
		$this->assertEquals(3.3, SupplierInvoiceHelper::announcedTotalTtc(array('grandTotalAmount' => 1.1 + 2.2, 'duePayableAmount' => 3.3)), 'float noise is none');
	}

	/**
	 * Prepaid amounts (BT-113), billing frames (BT-23), and the deposit still to attach.
	 *
	 * @return	array<string,array{0:?float,1:string,2:float}>
	 */
	public static function prepaidAmounts(): array
	{
		return array(
			'a deposit'                          => array(120.0, 'B1', 120.0),
			'one cent'                           => array(0.01, 'S1', 0.01),
			'half a cent'                        => array(0.005, 'B1', 0.005),
			'half a cent under float noise'      => array(0.004999999999999, 'B1', 0.004999999999999),
			'below half a cent'                  => array(0.004, 'B1', 0.0),
			'zero'                               => array(0.0, 'B1', 0.0),
			'absent'                             => array(null, 'B1', 0.0),
			'negative'                           => array(-5.0, 'B1', 0.0),
			'already paid frame B2'              => array(120.0, 'B2', 0.0),
			'already paid frame S2'              => array(120.0, 'S2', 0.0),
			'already paid frame M2'              => array(120.0, 'M2', 0.0),
		);
	}

	/**
	 * Only a prepaid amount writing a positive cent, outside an already paid frame, is a deposit to attach.
	 *
	 * @dataProvider prepaidAmounts
	 *
	 * @param	?float	$prepaid	BT-113, null when absent
	 * @param	string	$frame		BT-23
	 * @param	float	$expected	Amount still to attach
	 * @return	void
	 */
	public function testOnlyAPositiveCentIsADepositToAttach($prepaid, $frame, $expected)
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, 'depositAnnouncedByDocument');
		$method->setAccessible(true);

		$header = array('businessProcessId' => $frame);
		if ($prepaid !== null) {
			$header['totalPrepaidAmount'] = $prepaid;
		}

		$this->assertSame($expected, $method->invokeArgs(new CIIProtocol($db), array($header)));
	}

	/**
	 * A payable that answers BR-CO-16 to the cent does not mark the invoice.
	 *
	 * @return	void
	 */
	public function testAPayableAnsweringToTheCentIsNoContradiction()
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, 'flagPayableMismatch');
		$method->setAccessible(true);
		$protocol = new CIIProtocol($db);

		foreach (array(
			array(2149.85, 0.0, 2149.85),
			array(1.02, 0.0, 1.015),
			array(1.1, 2.2, 3.3),
			array(-1.02, 0.0, -1.015),
		) as list($due, $prepaid, $announcedTtc)) {
			$messages = array();
			$header = array('duePayableAmount' => $due, 'totalPrepaidAmount' => $prepaid, 'documentno' => 'TEST');
			$this->assertFalse($method->invokeArgs($protocol, array(0, $header, 0.0, $announcedTtc, &$messages)), 'BT-115 ' . $due . ' + BT-113 ' . $prepaid . ' against ' . $announcedTtc);
			$this->assertCount(0, $messages);
		}
	}
}
