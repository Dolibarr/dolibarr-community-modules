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
 *      \file       test/phpunit/PrepaidIsADepositTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the rule telling an amount already paid that the import has to
 *                  attach from one the vendor cashed in himself. BR-FR-CO-09 reads BT-23 in B2, S2
 *                  or M2 as "invoice already paid", where BT-113 equals BT-112 and BT-115 is zero.
 *                  Both the document level guard and the line level postpone read this one rule.
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
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class PrepaidIsADepositTest extends CommonClassTest
{
	/**
	 * Every invoicing framework BR-FR-08 allows, with what BT-113 means under it.
	 *
	 * @return array<string,array{0:string,1:float,2:float}>	Framework, announced BT-113, amount to attach
	 */
	public function frameworkProvider()
	{
		$cases = array();
		// BR-FR-CO-09: under these three the invoice is already paid, BT-113 = BT-112 and BT-115 = 0.
		foreach (array('B2', 'S2', 'M2') as $paid) {
			$cases['already paid ' . $paid] = array($paid, 540.28, 0.0);
		}
		// BR-FR-CO-08 names these as the final invoices after a deposit: BT-113 is the deposit.
		foreach (array('B4', 'S4', 'M4') as $afterDeposit) {
			$cases['after a deposit ' . $afterDeposit] = array($afterDeposit, 120.00, 120.00);
		}
		// Every other framework keeps the previous behaviour: an announced amount is waited for.
		foreach (array('B1', 'S1', 'M1', 'S3', 'S5', 'S6', 'B7', 'S7', 'B8', 'S8', 'M8', 'B9', 'S9', 'M9') as $other) {
			$cases['ordinary ' . $other] = array($other, 120.00, 120.00);
		}
		// A document carrying no BT-23 at all is read the same way: nothing says it is settled.
		$cases['no framework'] = array('', 120.00, 120.00);

		return $cases;
	}

	/**
	 * A document saying it was already paid leaves nothing for the import to attach.
	 *
	 * @param	string	$framework	BT-23 of the document
	 * @param	float	$announced	BT-113 of the document
	 * @param	float	$expected	What the import still has to attach
	 * @return	void
	 * @dataProvider frameworkProvider
	 */
	public function testFrameworkTellsADepositFromASettledInvoice($framework, $announced, $expected)
	{
		$rule = $this->rule(array('businessProcessId' => $framework, 'totalPrepaidAmount' => $announced));

		$this->assertEqualsWithDelta($expected, $rule, 0.001, 'BT-23 "' . $framework . '" with BT-113 ' . $announced);
	}

	/**
	 * Nothing announced, nothing to attach - whatever the framework says.
	 *
	 * @return	void
	 */
	public function testNothingAnnouncedIsNothingToAttach()
	{
		$this->assertEqualsWithDelta(0.0, $this->rule(array('businessProcessId' => 'B1')), 0.001, 'BT-113 absent');
		$this->assertEqualsWithDelta(0.0, $this->rule(array('businessProcessId' => 'B1', 'totalPrepaidAmount' => 0)), 0.001, 'BT-113 = 0');
		// Half a cent is below what a document can carry (BR-DEC-16 allows two decimals).
		$this->assertEqualsWithDelta(0.0, $this->rule(array('totalPrepaidAmount' => 0.004)), 0.001, 'BT-113 under a cent');
	}

	/**
	 * BT-113 is read with its sign: a negative amount already paid is no deposit the import can attach.
	 *
	 * @return	void
	 */
	public function testANegativeAmountAlreadyPaidIsNoDepositToAttach()
	{
		$rule = $this->rule(array('businessProcessId' => 'B1', 'totalPrepaidAmount' => -120.00));

		$this->assertEqualsWithDelta(0.0, $rule, 0.001, 'a negative BT-113 announces nothing to attach');
	}

	/**
	 * An invoice declaring itself already paid has no deposit to wait for behind its preceding invoice (BG-3).
	 * @return	void
	 */
	public function testAnAlreadyPaidInvoiceIsNotPostponedOnAnUnknownPrecedingInvoice()
	{
		$messages = array();
		$result = $this->resolveMissing(array('documentno' => 'F-2', 'businessProcessId' => 'B2', 'totalPrepaidAmount' => 540.28), $messages);

		$this->assertNull($result, 'BT-23 B2 with BT-113 540.28 and an unknown BG-3 is stepped over');
		$this->assertCount(1, $messages);
	}

	/**
	 * An ordinary invoice announcing an amount already paid still waits for the deposit it deducts.
	 * @return	void
	 */
	public function testAnOrdinaryInvoiceAnnouncingADepositIsStillPostponed()
	{
		$messages = array();
		$result = $this->resolveMissing(array('documentno' => 'F-2', 'businessProcessId' => 'B1', 'totalPrepaidAmount' => 120.00), $messages);

		$this->assertIsArray($result);
		$this->assertSame(1, $result['postponeflow']);
	}

	/**
	 * Referenced invoices missing from Dolibarr, with what the import can tell of them.
	 *
	 * @return array<string,array{0:string,1:string,2:array<int,array<string,string>>,3:string}>	BT-3, BT-23, BG-3 entries, expected REFERENCE_* constant
	 */
	public function referenceProvider()
	{
		return array(
			'typed down payment' => array('380', 'B1', array(array('IssuerAssignedID' => 'F-1', 'TypeCode' => '386')), CIIProtocol::REFERENCE_DOWN_PAYMENT),
			'typed credit note' => array('380', 'B4', array(array('IssuerAssignedID' => 'F-1', 'TypeCode' => '381')), CIIProtocol::REFERENCE_UNKNOWN),
			'typed self-billed down payment' => array('380', 'B1', array(array('IssuerAssignedID' => 'F-1', 'TypeCode' => '500')), CIIProtocol::REFERENCE_DOWN_PAYMENT),
			'unknown type code' => array('380', 'B4', array(array('IssuerAssignedID' => 'F-1', 'TypeCode' => '130')), CIIProtocol::REFERENCE_UNKNOWN),
			'lone reference after a down payment' => array('380', 'B4', array(array('IssuerAssignedID' => 'F-1')), CIIProtocol::REFERENCE_DOWN_PAYMENT),
			'two references after a down payment' => array('380', 'B4', array(array('IssuerAssignedID' => 'F-1'), array('IssuerAssignedID' => 'A-9')), CIIProtocol::REFERENCE_UNKNOWN),
			'ordinary invoice' => array('380', 'B1', array(array('IssuerAssignedID' => 'F-1')), CIIProtocol::REFERENCE_UNKNOWN),
			'replacement invoice' => array('384', 'B4', array(array('IssuerAssignedID' => 'F-1')), CIIProtocol::REFERENCE_UNKNOWN),
		);
	}

	/**
	 * Only a typed down payment, or the lone reference of a final invoice after a down payment, is taken as one.
	 *
	 * @dataProvider referenceProvider
	 * @param	string								$typeCode		BT-3 of the received document
	 * @param	string								$framework		BT-23 of the received document
	 * @param	array<int,array<string,string>>		$refDocs		BG-3 entries of the received document
	 * @param	string								$expected		Expected REFERENCE_* constant for "F-1"
	 * @return	void
	 */
	public function testWhatAMissingReferenceIsKnownToBe($typeCode, $framework, $refDocs, $expected)
	{
		$method = new ReflectionMethod('CIIProtocol', 'classifyReferencedInvoice');
		$method->setAccessible(true);
		$header = array('documenttypecode' => $typeCode, 'businessProcessId' => $framework, 'invoiceRefDocs' => $refDocs);

		$this->assertSame($expected, $method->invoke(new CIIProtocol($GLOBALS['db']), 'F-1', $header));
	}

	/**
	 * A missing down payment is named with the amount paid, offered as a down payment invoice, and vendor data shown as text.
	 * @return	void
	 */
	public function testAMissingDownPaymentIsOfferedAsOne()
	{
		global $langs;

		$ref = '<span onmouseover="x">F-1';
		$messages = array();
		$result = $this->resolveMissing(array('documentno' => '<span onmouseover="x">F-2', 'documenttypecode' => '380', 'businessProcessId' => 'B4', 'totalPrepaidAmount' => 120.00, 'invoiceRefDocs' => array(array('IssuerAssignedID' => $ref))), $messages, $ref);

		$this->assertStringContainsString('&type=' . CommonInvoice::TYPE_DEPOSIT . '&', $result['action']);
		$this->assertStringContainsString(price(120.00, 0, $langs, 1, -1, -1, ''), $result['businessmessage']);
		$this->assertStringNotContainsString('<span', $result['businessmessage']);
		$this->assertStringContainsString(' <a ', $result['action']);
		$this->assertStringNotContainsString('<span', strstr($result['action'], ' <a ', true));
	}

	/**
	 * A line reference (BT-128) is never offered as a down payment invoice.
	 * @return	void
	 */
	public function testALineReferenceIsNotOfferedAsADownPayment()
	{
		$method = new ReflectionMethod('CIIProtocol', 'postponeForMissingLineDocument');
		$method->setAccessible(true);
		$header = array('documentno' => 'F-2', 'documenttypecode' => '380', 'businessProcessId' => 'B4', 'totalPrepaidAmount' => 120.00, 'invoiceRefDocs' => array(array('IssuerAssignedID' => 'F-1')));

		$result = $method->invoke(new CIIProtocol($GLOBALS['db']), 'F-1', '1', 1, $header);

		$this->assertStringNotContainsString('type=', $result['action']);
	}

	/**
	 * A replacement does not deduct the invoice it replaces, even a down payment: it is not offered as one.
	 * @return	void
	 */
	public function testAReplacedDownPaymentIsNotOfferedAsADeduction()
	{
		$messages = array();
		$result = $this->resolveMissing(array('documentno' => 'F-2', 'documenttypecode' => '384', 'businessProcessId' => 'B1', 'totalPrepaidAmount' => 120.00, 'invoiceRefDocs' => array(array('IssuerAssignedID' => 'F-1', 'TypeCode' => '386'))), $messages);

		$this->assertStringNotContainsString('type=', $result['action']);
	}

	/**
	 * Run resolveMissingReferencedDocument() on an unknown preceding invoice, "F-1" by default.
	 * @param	array<string,mixed>	$parsedHeader		Parsed header of the received document
	 * @param	string[]			$messages			Import messages
	 * @param	string				$refDoc				Reference of the unknown preceding invoice
	 * @return	array<string,mixed>|null					Postpone result, or null when stepped over
	 */
	protected function resolveMissing(array $parsedHeader, array &$messages, $refDoc = 'F-1')
	{
		$method = new ReflectionMethod('CIIProtocol', 'resolveMissingReferencedDocument');
		$method->setAccessible(true);

		return $method->invokeArgs(new CIIProtocol($GLOBALS['db']), array($refDoc, $parsedHeader, 1, 'preceding invoice of', &$messages));
	}

	/**
	 * Run the rule, which is protected because it is an implementation detail of the two guards.
	 *
	 * @param	array<string,mixed>	$parsedHeader	Parsed header of the received document
	 * @return	float								What the import still has to attach
	 */
	protected function rule(array $parsedHeader)
	{
		$method = new ReflectionMethod('CIIProtocol', 'depositAnnouncedByDocument');
		$method->setAccessible(true);

		return (float) $method->invoke(new CIIProtocol($GLOBALS['db']), $parsedHeader);
	}
}
