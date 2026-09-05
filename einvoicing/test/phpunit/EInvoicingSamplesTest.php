<?php
/* Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *      \file       test/phpunit/EInvoicingSamplesTest.php
 *      \ingroup    test
 *      \brief      Regression test for the  sample invoice chain (deposit, standard, credit
 *                  note) built by EInvoicing::generateSampleEInvoicesForTests().
 * 					Generated XML is compared against the reference fixtures
 * 					in test/phpunit/fixtures/einvoicing_samples/.
 */


// This script must only be run from the command line.
if (PHP_SAPI !== 'cli') {
	echo "Error: this script must be run from the command line (CLI), not through a web server.\n";
	exit(1);
}

// PHPUnit loads this file via an include done inside one of its own methods, not at the true global
// scope. Binding these as global here first ensures master.inc.php's assignments below land on the
// real global variables (needed by Dolibarr core functions that do their own "global $conf;" etc.).
global $conf, $user, $langs, $db;

// Load Dolibarr environment
// This module is deployed by symlinking this repository into htdocs/custom/einvoicing of one or
// several Dolibarr instances. Some test runners resolve the real (non-symlinked) path of this
// file before including it, which breaks a fixed "../../htdocs/master.inc.php" relative path.
// DOLIBARR_HTDOCS let's the developer/CI point explicitly at the Dolibarr instance to test
// against; otherwise we fall back to the standard relative path (valid when this file is reached
// through the htdocs/custom/einvoicing/test/phpunit symlink without realpath resolution).
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}
require_once $dolibarrHtdocs . '/master.inc.php';


/**
 * The main.inc.php has been included so the following variable are now defined:
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

dol_include_once('einvoicing/class/einvoicing.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';


/**
 * Class EInvoicingSamplesTest
 *
 * This test class ensures that the generated XML output for different invoice types remains
 * consistent and matches the committed reference fixtures. It validates three invoice types:
 * - Deposit invoices
 * - Standard invoices
 * - Credit notes
 *
 * Generated XML is compared against the reference fixtures stored in
 * test/phpunit/fixtures/einvoicing_samples/ using semantic XML comparison.
 *
 * The same documents also have to hold together on their own, whatever the fixtures say: the VAT
 * breakdown (BG-23) of each one is checked here too, on the chain generated once for the class.
 */
class EInvoicingSamplesTest extends CommonClassTest
{
	const RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

	/**
	 * Holds the generated sample invoice chain (deposit, standard, credit note) for the whole test class.
	 * @var array{deposit:string, standard:string, creditnote:string}|null
	 */
	private static $generated = null;

	/**
	 * Generates the sample invoice chain (deposit, standard, credit note) once for the whole test class and returns it.
	 *
	 * @return array{deposit:string, standard:string, creditnote:string}
	 */
	private function getGenerated()
	{
		if (self::$generated === null) {
			self::$generated = EInvoicing::generateSampleEInvoicesForTests();
		}

		return self::$generated;
	}

	/**
	 * Loads a committed reference fixture.
	 *
	 * @param	string	$filename	Fixture file name (e.g. 'cii_deposit.xml')
	 * @return	string	Reference XML content
	 */
	private function loadFixture($filename)
	{
		$path = __DIR__ . '/fixtures/einvoicing_samples/' . $filename;
		$this->assertFileExists(
			$path,
			'Reference fixture ' . $filename . ' is missing. Generate it with: scripts/regenerate_einvoicing_fixtures.php'
		);

		return (string) file_get_contents($path);
	}

	/**
	 * Compares a generated (already normalized) XML against its reference fixture.
	 *
	 * @param	string	$expectedFixtureFile	Fixture file name (e.g. 'cii_deposit.xml')
	 * @param	string	$actualXml				Normalized XML actually generated by this test run
	 * @return	void
	 */
	private function assertMatchesFixture($expectedFixtureFile, $actualXml)
	{
		$expectedXml = $this->loadFixture($expectedFixtureFile);

		$this->assertXmlStringEqualsXmlString(
			$expectedXml,
			$actualXml,
			'Generated CII XML no longer matches ' . $expectedFixtureFile . '. If this change is intentional, regenerate the reference invoices with: scripts/regenerate_einvoicing_fixtures.php, review the diff, then commit the updated fixture.'
		);
	}

	/**
	 * The deposit sample invoice must keep producing the same CII XML.
	 *
	 * @return void
	 */
	public function testDepositInvoiceMatchesReference()
	{
		$generated = $this->getGenerated();
		$this->assertMatchesFixture('cii_deposit.xml', $generated['deposit']);
	}

	/**
	 * The standard sample invoice must keep producing the same CII XML.
	 *
	 * @return void
	 */
	public function testStandardInvoiceMatchesReference()
	{
		$generated = $this->getGenerated();
		$this->assertMatchesFixture('cii_standard.xml', $generated['standard']);
	}

	/**
	 * The replacement sample invoice must keep producing the same CII XML.
	 *
	 * @return void
	 */
	public function testReplacementInvoiceMatchesReference()
	{
		$generated = $this->getGenerated();
		$this->assertMatchesFixture('cii_replacement.xml', $generated['replacement']);
	}

	/**
	 * The credit note sample invoice must keep producing the same CII XML.
	 *
	 * @return void
	 */
	public function testCreditNoteInvoiceMatchesReference()
	{
		$generated = $this->getGenerated();
		$this->assertMatchesFixture('cii_creditnote.xml', $generated['creditnote']);
	}

	/**
	 * The situation sample invoice must keep producing the same CII XML.
	 *
	 * @return void
	 */
	public function testSituationInvoiceMatchesReference()
	{
		$generated = $this->getGenerated();
		$this->assertMatchesFixture('cii_situation.xml', $generated['situation']);
	}

	/**
	 * Read the VAT breakdowns (BG-23) of a document.
	 *
	 * @param	string	$xml	A CII document
	 * @return	array<int,array{basis:float,tax:float,rate:float,category:string,exemption:string}>	One entry per ram:ApplicableTradeTax of the settlement
	 */
	private function vatBreakdowns($xml)
	{
		$doc = new DOMDocument();
		$this->assertTrue($doc->loadXML($xml), 'the generated document is well formed XML');

		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('ram', self::RAM);

		$breakdowns = array();
		foreach ($xpath->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax') as $node) {
			$read = function ($name) use ($xpath, $node) {
				$found = $xpath->query('ram:' . $name, $node);
				return ($found->length > 0) ? trim($found->item(0)->textContent) : '';
			};

			$breakdowns[] = array(
				'basis' => (float) $read('BasisAmount'),					// BT-116
				'tax' => (float) $read('CalculatedAmount'),				// BT-117
				'rate' => (float) $read('RateApplicablePercent'),		// BT-119
				'category' => $read('CategoryCode'),					// BT-118
				'exemption' => $read('ExemptionReasonCode') . '|' . $read('ExemptionReason'),	// BT-121, BT-120
			);
		}

		return $breakdowns;
	}

	/**
	 * A breakdown that announces a tax amount states the rate that produced it.
	 *
	 * This is the shape #709 shipped: the rate was read from an array key that had become
	 * "S|20.0000||", and (float) of that is 0, so every document declared 0.00 % against a non-zero
	 * VAT amount.
	 *
	 * @return void
	 */
	public function testABreakdownThatTaxesStatesItsRate()
	{
		foreach ($this->getGenerated() as $type => $xml) {
			$breakdowns = $this->vatBreakdowns($xml);
			$this->assertNotEmpty($breakdowns, 'the ' . $type . ' specimen has a VAT breakdown');

			foreach ($breakdowns as $index => $breakdown) {
				$where = 'specimen ' . $type . ', VAT breakdown ' . ($index + 1) . ' (' . $breakdown['category'] . ')';

				if (abs($breakdown['tax']) < 0.005) {
					continue;		// An exempt or reverse charged breakdown legitimately taxes nothing.
				}

				$this->assertGreaterThan(
					0,
					$breakdown['rate'],
					$where . ': BT-119 is ' . $breakdown['rate'] . ' % while BT-117 announces ' . $breakdown['tax']
				);
			}
		}
	}

	/**
	 * The tax amount of a breakdown follows from its own basis and its own rate (BR-CO-17).
	 *
	 * @return void
	 */
	public function testTheTaxAmountFollowsFromTheBasisAndTheRate()
	{
		foreach ($this->getGenerated() as $type => $xml) {
			foreach ($this->vatBreakdowns($xml) as $index => $breakdown) {
				$expected = round($breakdown['basis'] * $breakdown['rate'] / 100, 2);
				$where = 'specimen ' . $type . ', VAT breakdown ' . ($index + 1) . ' (' . $breakdown['category'] . ')';

				$this->assertEqualsWithDelta(
					$expected,
					$breakdown['tax'],
					0.011,		// The tolerance BR-CO-17 itself grants.
					$where . ': BT-117 = ' . $breakdown['tax'] . ' where BT-116 ' . $breakdown['basis']
						. ' x BT-119 ' . $breakdown['rate'] . ' % gives ' . $expected
				);
			}
		}
	}

	/**
	 * A document carries one breakdown per category, rate and exemption reason, never two.
	 *
	 * That is what #709 was about - a national VAT source code was splitting one 20 % breakdown into
	 * two, and the access point refused the document - so it is checked here rather than left to the
	 * reference documents, which would not say why they changed.
	 *
	 * @return void
	 */
	public function testTwoBreakdownsNeverShareACategoryAndARate()
	{
		foreach ($this->getGenerated() as $type => $xml) {
			$seen = array();
			foreach ($this->vatBreakdowns($xml) as $breakdown) {
				$key = $breakdown['category'] . '|' . number_format($breakdown['rate'], 4, '.', '') . '|' . $breakdown['exemption'];

				$this->assertArrayNotHasKey(
					$key,
					$seen,
					'specimen ' . $type . ': two VAT breakdowns for category ' . $breakdown['category']
						. ' at ' . $breakdown['rate'] . ' %, which BR-S-08 and its siblings refuse'
				);
				$seen[$key] = true;
			}
		}
	}
}
