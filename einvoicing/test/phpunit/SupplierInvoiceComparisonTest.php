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
 *      \file       test/phpunit/SupplierInvoiceComparisonTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the amounts the comparison of a supplier invoice recomputes.
 *                  Confronting an invoice with the document it came from means recomputing its lines
 *                  in the two VAT conventions. That recomputation has to be the one the core made
 *                  when it wrote the invoice, or the comparison reports a difference that is its own.
 *
 *                  A file of its own rather than methods in SupplierInvoiceHelperTest, which is past
 *                  the thousand lines where .agents/AGENTS.md has the test file of a source split:
 *                  this is the half that recomputes amounts, all of it on the import side.
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
dol_include_once('einvoicing/class/utils/SupplierInvoiceHelper.class.php');
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
class SupplierInvoiceComparisonTest extends CommonClassTest
{
	/**
	 * Three lines at 20 %, chosen so the two conventions do not agree: 2.10 of VAT rounded line by
	 * line, 2.09 rounded on the total.
	 */
	const LINE_AMOUNTS = array(1.09, 7.79, 1.59);

	/**
	 * The rate every line of the fixture carries.
	 *
	 * @var float
	 */
	const VAT_RATE = 20.0;

	/**
	 * Ids of the invoices created by the tests, deleted at the end.
	 *
	 * @var int[]
	 */
	private $createdInvoiceIds = array();

	/**
	 * Constants the tests change, put back afterwards.
	 *
	 * @var array<string,string|null>
	 */
	private $savedConstants = array();

	/**
	 * Remember the settings the tests play with.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();

		$names = array(
			'MAIN_APPLY_DISCOUNT_ON_UNIT_PRICE_THEN_ROUND_BEFORE_MULTIPLICATION_BY_QTY',
			'MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND_SUPPLIER',
			'MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND',
		);
		foreach ($names as $name) {
			$this->savedConstants[$name] = isset($conf->global->$name) ? $conf->global->$name : null;
		}
	}

	/**
	 * Put the settings back and remove the invoices created by the test.
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
	 * A draft supplier invoice carrying the given lines. addline() ends on update_price(), so the
	 * totals the invoice comes out with are the ones the core computed, on this very version.
	 *
	 * @param	array<int,array{pu:float,qty:float,rem:float}>	$lines	Lines to add
	 * @return	FactureFournisseur										The created invoice, freshly fetched
	 */
	private function createInvoice($lines)
	{
		global $db, $user;

		$invoice = new FactureFournisseur($db);
		$invoice->initAsSpecimen();
		$invoice->lines = array();
		$invoice->ref_supplier = 'CMP' . strtoupper(bin2hex(random_bytes(5)));
		// addline() reads $this->special_code, a property the class does not declare on Dolibarr 18
		$invoice->special_code = 0;
		$id = $invoice->create($user);
		$this->assertGreaterThan(0, $id, $invoice->errorsToString());
		$this->createdInvoiceIds[] = $id;

		foreach ($lines as $line) {
			// The first eight arguments of addline() are the same from Dolibarr 18 to 25
			$res = $invoice->addline('Line of ' . $line['pu'], $line['pu'], self::VAT_RATE, 0, 0, $line['qty'], 0, $line['rem']);
			$this->assertGreaterThan(0, $res, $invoice->errorsToString());
		}

		$invoice->fetch($id);

		return $invoice;
	}

	/**
	 * The amounts the comparison recomputes for an invoice, in one of its three modes.
	 *
	 * @param	FactureFournisseur	$invoice	The invoice to recompute
	 * @param	int					$mode		0 to read the invoice, 1 for "total of round", 2 for "round of total"
	 * @return	array{total_ht:float,total_ttc:float,total_tva:float,vat_by_rate:array<string,array{vat_amount:float,vat_basis_amount:float}>}
	 */
	private function detailsFor(FactureFournisseur $invoice, $mode)
	{
		$method = new ReflectionMethod(SupplierInvoiceHelper::class, 'getInvoiceDetailsForComparison');
		$method->setAccessible(true);

		return $method->invokeArgs(null, array($invoice, $mode));
	}

	/**
	 * The invariant the whole comparison rests on: an invoice Dolibarr has just computed itself, on
	 * an instance rounding line by line, must come back identical when the comparison recomputes it
	 * in that same convention. Anything else is a difference the module invents.
	 *
	 * @return void
	 */
	public function testModeOneReproducesTheTotalsTheCoreWrote()
	{
		global $conf;

		$conf->global->MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND_SUPPLIER = '0';
		$conf->global->MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND = '0';

		$invoice = $this->createInvoice(array(
			array('pu' => 1.09, 'qty' => 1, 'rem' => 0),
			array('pu' => 7.79, 'qty' => 1, 'rem' => 0),
			array('pu' => 1.59, 'qty' => 1, 'rem' => 0),
		));

		$details = $this->detailsFor($invoice, 1);

		$this->assertEquals((float) $invoice->total_ht, $details['total_ht'], 'the net total the core wrote');
		$this->assertEquals((float) $invoice->total_tva, $details['total_tva'], 'the VAT the core wrote');
		$this->assertEquals((float) $invoice->total_ttc, $details['total_ttc'], 'the gross total the core wrote');
	}

	/**
	 * The same invariant on discounted lines, where the supported cores do not compute alike: 18 to 21
	 * ignore MAIN_APPLY_DISCOUNT_ON_UNIT_PRICE..., 22 and 23 round the discounted unit price, 24 rounds
	 * the discount itself. Each line is a case where those conventions part by a cent - the first on
	 * Dolibarr 18 to 21, the second on 24 and 25 - with prices a line really carries (five decimals,
	 * what addline() stores).
	 *
	 * @return void
	 */
	public function testADiscountedInvoiceIsRecomputedTheWayTheInstanceComputesIt()
	{
		global $conf;

		$conf->global->MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND_SUPPLIER = '0';
		$conf->global->MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND = '0';
		$conf->global->MAIN_APPLY_DISCOUNT_ON_UNIT_PRICE_THEN_ROUND_BEFORE_MULTIPLICATION_BY_QTY = 'MU';

		$invoice = $this->createInvoice(array(
			array('pu' => 76.345, 'qty' => 1000, 'rem' => 2.5),
			array('pu' => 0.47052, 'qty' => 1000, 'rem' => 12.5),
		));

		$details = $this->detailsFor($invoice, 1);

		$this->assertEquals((float) $invoice->total_ht, $details['total_ht'], 'the net total the core wrote');
		$this->assertEquals((float) $invoice->total_tva, $details['total_tva'], 'the VAT the core wrote');
		$this->assertEquals((float) $invoice->total_ttc, $details['total_ttc'], 'the gross total the core wrote');
	}

	/**
	 * The two conventions must really differ, otherwise the comparison would have nothing to suggest.
	 * Mode 1 rounds each line to the accounting precision and sums; mode 2 keeps the unit precision on
	 * the lines and rounds only the sums. On this fixture that is one cent of VAT.
	 *
	 * @return void
	 */
	public function testTheTwoVatConventionsDoNotAgreeOnThisFixture()
	{
		$invoice = $this->createInvoice(array(
			array('pu' => 1.09, 'qty' => 1, 'rem' => 0),
			array('pu' => 7.79, 'qty' => 1, 'rem' => 0),
			array('pu' => 1.59, 'qty' => 1, 'rem' => 0),
		));

		$totalOfRound = $this->detailsFor($invoice, 1);
		$roundOfTotal = $this->detailsFor($invoice, 2);

		$this->assertEquals(10.47, $totalOfRound['total_ht']);
		$this->assertEquals(10.47, $roundOfTotal['total_ht']);
		$this->assertEquals(2.10, $totalOfRound['total_tva'], 'rounded line by line');
		$this->assertEquals(2.09, $roundOfTotal['total_tva'], 'rounded on the total');
		$this->assertEquals(12.57, $totalOfRound['total_ttc']);
		$this->assertEquals(12.56, $roundOfTotal['total_ttc']);
	}

	/**
	 * The precision lent to the totals for mode 2 is given back: the sums that follow, and everything
	 * computed after the call, must round to the accounting precision of the instance.
	 *
	 * @return void
	 */
	public function testTheAccountingPrecisionIsGivenBackAfterTheComparison()
	{
		global $conf;

		$before = getDolGlobalString('MAIN_MAX_DECIMALS_TOT');

		$invoice = $this->createInvoice(array(
			array('pu' => 1.09, 'qty' => 1, 'rem' => 0),
			array('pu' => 7.79, 'qty' => 1, 'rem' => 0),
			array('pu' => 1.59, 'qty' => 1, 'rem' => 0),
		));

		$this->detailsFor($invoice, 2);

		$this->assertEquals($before, getDolGlobalString('MAIN_MAX_DECIMALS_TOT'), 'the instance keeps its own precision');
	}

	/**
	 * Mode 0 reads the invoice instead of recomputing it, and the VAT breakdown it returns is the one
	 * stored on the lines.
	 *
	 * @return void
	 */
	public function testModeZeroReadsTheInvoiceItself()
	{
		$invoice = $this->createInvoice(array(
			array('pu' => 1.09, 'qty' => 1, 'rem' => 0),
			array('pu' => 7.79, 'qty' => 1, 'rem' => 0),
			array('pu' => 1.59, 'qty' => 1, 'rem' => 0),
		));

		$details = $this->detailsFor($invoice, 0);

		$this->assertEquals((float) $invoice->total_ht, $details['total_ht']);
		$this->assertEquals((float) $invoice->total_tva, $details['total_tva']);
		$this->assertEquals((float) $invoice->total_ttc, $details['total_ttc']);
		$this->assertArrayHasKey('20', $details['vat_by_rate'], 'the rate of the fixture');
	}
}
