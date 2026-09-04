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
 *      \file       test/phpunit/VatBreakdownStatesItsRateTest.php
 *      \ingroup    test
 *      \brief      The VAT breakdown (BG-23) of a generated document has to hold together on its own.
 *      \remarks    EInvoicingSamplesTest compares the generated documents against committed reference
 *                  documents, which answers "did the output change" - a question whose answer is
 *                  legitimately "yes" every time a reference is regenerated on purpose. It does not
 *                  answer "is the output still arithmetically true", and a regenerated reference
 *                  makes it green again whatever it now contains.
 *
 *                  This file asks the second question, on the documents as they are generated now:
 *                  a VAT breakdown states the rate it taxes, the tax amount it announces follows
 *                  from its own basis and rate (BR-CO-17), and two breakdowns of the same category
 *                  and rate never coexist (BR-S-08, the defect #709 set out to fix).
 *
 *                  On the merge of #709 every one of these documents carried a rate of 0.00 against
 *                  a non-zero tax amount: this file is red on that code with no reference document
 *                  to regenerate, and the three rules it checks are the three the FNFE validators
 *                  answered INVALID with.
 */


// This script must only be run from the command line.
if (PHP_SAPI !== 'cli') {
	echo "Error: this script must be run from the command line (CLI), not through a web server.\n";
	exit(1);
}

global $conf, $user, $langs, $db;

// Load Dolibarr environment. Same resolution as the other test files of the module: DOLIBARR_HTDOCS
// points at the instance to test against, and the relative path is the fallback when the module is
// reached through the htdocs/custom/einvoicing symlink.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}
require_once $dolibarrHtdocs . '/master.inc.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

dol_include_once('einvoicing/class/einvoicing.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';


/**
 * Class VatBreakdownStatesItsRateTest
 *
 * Checks the VAT breakdown of the five specimen documents (deposit, standard, replacement, credit
 * note, situation) against the rules that make it exploitable, whatever the reference documents say.
 */
class VatBreakdownStatesItsRateTest extends CommonClassTest
{
	const RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

	/**
	 * The five specimen documents, generated once for the whole class.
	 * @var array<string,string>|null
	 */
	private static $generated = null;

	/**
	 * Generate the specimen chain once and return it.
	 *
	 * @return array<string,string>	One CII document per invoice type
	 */
	private function getGenerated()
	{
		if (self::$generated === null) {
			self::$generated = EInvoicing::generateSampleEInvoicesForTests();
		}

		return self::$generated;
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
