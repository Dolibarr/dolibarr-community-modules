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
 *      \file       test/phpunit/ImportVatCalculationModeTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the VAT calculation mode of a received invoice (issue #781).
 *
 *                  Dolibarr computes the VAT of an invoice either by rounding it on every line and
 *                  adding the results up ("total of round", mode 1, the default), or by adding the
 *                  line amounts up and rounding the VAT once ("round of total", mode 2). A received
 *                  document does not leave that open: BT-110 and BT-112 are the ones its issuer
 *                  computed, so the imported invoice has to carry them whatever the instance is set
 *                  to.
 *
 *                  The three line amounts used below are taken from the reported 46 line document:
 *                  1.09, 7.79 and 1.59 at 20 %, which round to 0.22 + 1.56 + 0.32 = 2.10 line by
 *                  line, where the document announces 10.47 x 20 % = 2.09.
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
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

if (empty($user->id)) {
	$user->fetch(1);
	// User::loadRights() only exists from Dolibarr 19 on, older versions name it getrights()
	if (method_exists($user, 'loadRights')) {
		$user->loadRights();
	} else {
		$user->getrights();
	}
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class ImportVatCalculationModeTest extends CommonClassTest
{
	/**
	 * The line net amounts of the fixture invoice, at 20 %: 2.10 of VAT line by line, 2.09 on the total.
	 */
	const LINE_AMOUNTS = array(1.09, 7.79, 1.59);

	/**
	 * Ids of the invoices created by the tests, deleted at the end.
	 *
	 * @var int[]
	 */
	private $createdInvoiceIds = array();

	/**
	 * The two names the core gives that setting: the _SUPPLIER variant only exists from Dolibarr 20 on,
	 * before that the generic one drives supplier documents as well.
	 *
	 * @var array<string,string|null>
	 */
	private $savedConstants = array();

	/**
	 * Remember the rounding setting of the instance, whichever of the two names carries it.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();

		foreach (array('MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND_SUPPLIER', 'MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND') as $name) {
			$this->savedConstants[$name] = isset($conf->global->$name) ? $conf->global->$name : null;
		}
	}

	/**
	 * Put the setting back and remove the invoices created by the test.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf, $db, $user;

		foreach ($this->savedConstants as $name => $value) {
			if ($value === null) {
				unset($conf->global->$name);
			} else {
				$conf->global->$name = $value;
			}
		}
		$this->savedConstants = array();

		foreach ($this->createdInvoiceIds as $id) {
			$invoice = new FactureFournisseur($db);
			if ($invoice->fetch($id) > 0) {
				$invoice->delete($user);
			}
		}
		$this->createdInvoiceIds = array();

		parent::tearDown();
	}

	/**
	 * Set the VAT calculation mode of the instance, under both names so the test says the same thing
	 * on every supported version.
	 *
	 * @param	int		$mode	1 for "total of round", 2 for "round of total"
	 * @return	void
	 */
	private function setInstanceVatMode($mode)
	{
		global $conf;

		$value = ($mode == 2 ? '1' : '0');
		$conf->global->MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND_SUPPLIER = $value;
		$conf->global->MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND = $value;
	}

	/**
	 * A draft supplier invoice carrying the three lines of the fixture, built the way the import does:
	 * addline() ends on update_price(1, 'auto'), so the invoice comes out in the mode of the instance.
	 *
	 * @return	FactureFournisseur	The created invoice, freshly fetched
	 */
	private function createFixtureInvoice()
	{
		global $db, $user;

		$invoice = new FactureFournisseur($db);
		$invoice->initAsSpecimen();
		$invoice->lines = array();
		$invoice->ref_supplier = 'PR781' . strtoupper(bin2hex(random_bytes(5)));
		// addline() reads $this->special_code, a property the class does not declare on Dolibarr 18
		$invoice->special_code = 0;
		$id = $invoice->create($user);
		$this->assertGreaterThan(0, $id, $invoice->errorsToString());
		$this->createdInvoiceIds[] = $id;

		foreach (self::LINE_AMOUNTS as $amount) {
			// The first six arguments of addline() are the same from Dolibarr 18 to 24
			$res = $invoice->addline('Line of ' . $amount, $amount, 20, 0, 0, 1);
			$this->assertGreaterThan(0, $res, $invoice->errorsToString());
		}

		$invoice->fetch($id);

		return $invoice;
	}

	/**
	 * Run the realignment on an invoice, as the import does once every line exists.
	 *
	 * @param	FactureFournisseur	$invoice		The invoice to confront with the document
	 * @param	float				$announcedTva	BT-110 of the document
	 * @param	float				$announcedTtc	BT-112 of the document
	 * @param	string[]			$messages		Messages of the import, completed by the call
	 * @return	FactureFournisseur					The invoice, re-read from the database
	 */
	private function alignWith(FactureFournisseur $invoice, $announcedTva, $announcedTtc, array &$messages)
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, 'alignInvoiceTotalsWithDocument');
		$method->setAccessible(true);
		$method->invokeArgs(
			new CIIProtocol($db),
			array($invoice->id, array('taxTotalAmount' => $announcedTva, 'grandTotalAmount' => $announcedTtc), &$messages)
		);

		$reread = new FactureFournisseur($db);
		$reread->fetch($invoice->id);

		return $reread;
	}

	/**
	 * The reported case: an instance left on mode 1, a document whose VAT was rounded on the total.
	 * The invoice must end up on the totals of the document, and say so.
	 *
	 * @return void
	 */
	public function testTheInvoiceCarriesTheTotalsTheDocumentAnnounces()
	{
		$this->setInstanceVatMode(1);

		$invoice = $this->createFixtureInvoice();
		$this->assertEquals(10.47, (float) $invoice->total_ht, 'the net amount is the same under both conventions');
		$this->assertEquals(2.10, (float) $invoice->total_tva, 'mode 1 rounds the VAT line by line');
		$this->assertEquals(12.57, (float) $invoice->total_ttc);

		$messages = array();
		$realigned = $this->alignWith($invoice, 2.09, 12.56, $messages);

		$this->assertEquals(10.47, (float) $realigned->total_ht, 'the net amount is untouched');
		$this->assertEquals(2.09, (float) $realigned->total_tva, 'the VAT is the one of the document');
		$this->assertEquals(12.56, (float) $realigned->total_ttc);
		$this->assertCount(1, $messages, 'the import says what it recalculated');
		$this->assertStringContainsString('Mode 2', $messages[0]);
	}

	/**
	 * The net amount of every line stays what the document put on it: the core only ever moves a
	 * rounding difference onto the VAT of a line, so BT-131 and their sum BT-106 do not move.
	 *
	 * @return void
	 */
	public function testTheLineNetAmountsAreNotTouched()
	{
		$this->setInstanceVatMode(1);

		$invoice = $this->createFixtureInvoice();
		$before = array();
		foreach ($invoice->lines as $line) {
			$before[] = (float) $line->total_ht;
		}
		sort($before);

		$messages = array();
		$realigned = $this->alignWith($invoice, 2.09, 12.56, $messages);

		$after = array();
		foreach ($realigned->lines as $line) {
			$after[] = (float) $line->total_ht;
		}
		sort($after);

		$expected = self::LINE_AMOUNTS;
		sort($expected);

		$this->assertEquals($before, $after);
		$this->assertEquals($expected, $after, 'the line net amounts are the ones of the document');
	}

	/**
	 * An invoice that already totals what the document announces is left alone - no recalculation, no
	 * message.
	 *
	 * @return void
	 */
	public function testAnInvoiceThatAlreadyAgreesIsLeftAlone()
	{
		$this->setInstanceVatMode(1);

		$invoice = $this->createFixtureInvoice();

		$messages = array();
		$untouched = $this->alignWith($invoice, 2.10, 12.57, $messages);

		$this->assertEquals(2.10, (float) $untouched->total_tva);
		$this->assertEquals(12.57, (float) $untouched->total_ttc);
		$this->assertCount(0, $messages, 'nothing to say when the invoice already matches');
	}

	/**
	 * A difference neither convention explains is a real one. The invoice is left exactly as the import
	 * built it, and nothing is said here: the comparison of SupplierInvoiceHelper is what reports it.
	 *
	 * @return void
	 */
	public function testADifferenceThatIsNotARoundingConventionIsLeftAlone()
	{
		$this->setInstanceVatMode(1);

		$invoice = $this->createFixtureInvoice();

		$messages = array();
		$untouched = $this->alignWith($invoice, 3.00, 13.47, $messages);

		$this->assertEquals(10.47, (float) $untouched->total_ht);
		$this->assertEquals(2.10, (float) $untouched->total_tva, 'the invoice keeps the mode of the instance');
		$this->assertEquals(12.57, (float) $untouched->total_ttc);
		$this->assertCount(0, $messages);
	}

	/**
	 * The other direction, which is the reason the mode is not read from the setting: an instance on
	 * mode 2 receiving a document whose VAT was rounded line by line.
	 *
	 * @return void
	 */
	public function testAnInstanceOnMode2ReceivingADocumentRoundedLineByLine()
	{
		$this->setInstanceVatMode(2);

		$invoice = $this->createFixtureInvoice();
		$this->assertEquals(2.09, (float) $invoice->total_tva, 'mode 2 rounds the VAT on the total');

		$messages = array();
		$realigned = $this->alignWith($invoice, 2.10, 12.57, $messages);

		$this->assertEquals(10.47, (float) $realigned->total_ht);
		$this->assertEquals(2.10, (float) $realigned->total_tva);
		$this->assertEquals(12.57, (float) $realigned->total_ttc);
		$this->assertCount(1, $messages);
		$this->assertStringContainsString('Mode 1', $messages[0]);
	}

	/**
	 * A credit note is stored negative by Dolibarr while BT-110 and BT-112 are announced positive, the
	 * document type being what carries the sign. The comparison is therefore made on absolute values.
	 *
	 * @return void
	 */
	public function testTheComparisonIsMadeOnAbsoluteValues()
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, 'totalsAgreeWithDocument');
		$method->setAccessible(true);

		$creditNote = new FactureFournisseur($db);
		$creditNote->total_tva = -2.09;
		$creditNote->total_ttc = -12.56;
		$this->assertTrue($method->invoke(null, $creditNote, 2.09, 12.56));

		$off = new FactureFournisseur($db);
		$off->total_tva = -2.10;
		$off->total_ttc = -12.57;
		$this->assertFalse($method->invoke(null, $off, 2.09, 12.56));
	}
}
