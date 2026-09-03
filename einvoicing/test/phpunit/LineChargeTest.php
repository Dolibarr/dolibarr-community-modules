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
 *      \file       test/phpunit/LineChargeTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for an invoice line charge (BG-28) of a received document, issue #735.
 *
 *                  A Dolibarr line holds a quantity, a unit price and a discount percentage: it can
 *                  express BG-27, an allowance, and has nowhere to put BG-28, a charge. The import used
 *                  to drop the charge and, worse, to fold its amount into the base the discount
 *                  percentage was applied to - BT-131 already contains it - so the line came out at
 *                  neither the right amount nor the right unit price.
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
class LineChargeTest extends CommonClassTest
{
	/**
	 * Call a protected method of CIIProtocol through reflection: pure decision logic, no database access
	 * and no side effect.
	 *
	 * @param	string	$name	Method name
	 * @param	array	$args	Arguments
	 * @return	mixed			What the method answers
	 */
	private function call($name, array $args)
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, $name);
		$method->setAccessible(true);

		return $method->invokeArgs(new CIIProtocol($db), $args);
	}

	/**
	 * The line of the report: quantity 5 at 100.05, an allowance of 50.03 and a charge of 7.00, so
	 * BT-131 = 500.25 - 50.03 + 7.00 = 457.22.
	 *
	 * @return	array	The two entries of the line, as the parser returns them
	 */
	private function allowanceAndCharge()
	{
		return array(
			array('indicator' => 'false', 'basisAmount' => 500.25, 'actualAmount' => 50.03, 'reasonCode' => '95', 'reason' => ''),
			array('indicator' => 'true', 'actualAmount' => 7.00, 'reasonCode' => 'FC', 'reason' => 'Handling'),
		);
	}

	/**
	 * The unit price rebuilt for the line must be the one the document gives (BT-146, 100.05), not one
	 * inflated by the charge. It used to come out at 101.45, because BT-131 already contains the charge
	 * and was used as if it did not.
	 *
	 * @return	void
	 */
	public function testTheChargeLeavesTheUnitPriceOfTheLineAlone()
	{
		$discount = $this->call('resolveLineDiscountPercent', array($this->allowanceAndCharge(), 457.22));

		$this->assertNotFalse($discount);
		$this->assertEqualsWithDelta(500.25, $discount['priceWithoutDiscount'], 0.001, 'the gross of the line, charge excluded');
		$this->assertEqualsWithDelta(100.05, $discount['priceWithoutDiscount'] / 5, 0.001, 'which is BT-146 for a quantity of 5');
	}

	/**
	 * A line with an allowance and no charge must be resolved exactly as before: this is the whole
	 * existing corpus of received documents.
	 *
	 * @return	void
	 */
	public function testALineWithoutChargeIsUnchanged()
	{
		$allowanceOnly = array(
			array('indicator' => 'false', 'basisAmount' => 500.25, 'actualAmount' => 50.03, 'reasonCode' => '95', 'reason' => ''),
		);
		$discount = $this->call('resolveLineDiscountPercent', array($allowanceOnly, 450.23));

		$this->assertNotFalse($discount);
		$this->assertEqualsWithDelta(500.26, $discount['priceWithoutDiscount'], 0.001);
		$this->assertEqualsWithDelta(10.001, $discount['percent'], 0.001);
	}

	/**
	 * The charge becomes a line of its own, at the VAT rate of the line it belongs to - in CII a line
	 * level charge carries none of its own, CII-SR-191 forbidding ram:CategoryTradeTax there.
	 *
	 * @return	void
	 */
	public function testTheChargeBecomesItsOwnLine()
	{
		$parsedLine = array('lineid' => '1', 'rateApplicablePercent' => 20.0, 'lineAllowances' => $this->allowanceAndCharge());
		$lines = $this->call('buildLineChargeLines', array($parsedLine));

		$this->assertCount(1, $lines, 'the allowance does not become a line, it is the discount of its own line');
		$this->assertEquals(1, $lines[0]->qty);
		$this->assertEqualsWithDelta(7.00, $lines[0]->subprice, 0.001);
		$this->assertEqualsWithDelta(20.0, $lines[0]->tva_tx, 0.001, 'the rate of the line, not one of its own');
		$this->assertEquals(1, $lines[0]->product_type, 'a charge is a service');
		$this->assertStringContainsString('Handling', $lines[0]->desc, 'the reason of the document is kept');
		$this->assertStringContainsString('1', $lines[0]->desc, 'and the line it belongs to is named');
		$this->assertStringNotContainsString('EInvoicing', $lines[0]->desc, 'the language file is loaded, so no raw translation key survives');
	}

	/**
	 * BR-44 accepts a reason code alone. A bare "FC" says nothing, so the line is labelled and the code
	 * kept alongside.
	 *
	 * @return	void
	 */
	public function testAChargeWithOnlyAReasonCodeIsLabelled()
	{
		$parsedLine = array('lineid' => '7', 'rateApplicablePercent' => 5.5, 'lineAllowances' => array(
			array('indicator' => 'true', 'actualAmount' => 2.50, 'reasonCode' => 'FC', 'reason' => ''),
		));
		$lines = $this->call('buildLineChargeLines', array($parsedLine));

		$this->assertCount(1, $lines);
		$this->assertStringContainsString('FC', $lines[0]->desc);
		$this->assertNotSame('FC', $lines[0]->desc);
		$this->assertStringNotContainsString('EInvoicing', $lines[0]->desc, 'the label is translated, not a raw key');
		$this->assertEqualsWithDelta(5.5, $lines[0]->tva_tx, 0.001);
	}

	/**
	 * A line with no charge adds nothing, whether it carries an allowance, nothing at all, or a charge
	 * worth zero. This is the control that the change is inert on every document received so far.
	 *
	 * @return	void
	 */
	public function testALineWithoutChargeAddsNoLine()
	{
		$this->assertCount(0, $this->call('buildLineChargeLines', array(array('lineid' => '1'))));
		$this->assertCount(0, $this->call('buildLineChargeLines', array(array('lineid' => '1', 'lineAllowances' => array()))));
		$this->assertCount(0, $this->call('buildLineChargeLines', array(array('lineid' => '1', 'lineAllowances' => array(
			array('indicator' => 'false', 'basisAmount' => 100.0, 'actualAmount' => 10.0),
		)))));
		$this->assertCount(0, $this->call('buildLineChargeLines', array(array('lineid' => '1', 'lineAllowances' => array(
			array('indicator' => 'true', 'actualAmount' => 0.0, 'reason' => 'Nothing'),
		)))));
	}

	/**
	 * Several charges on one line give several lines, in the order of the document.
	 *
	 * @return	void
	 */
	public function testSeveralChargesOnOneLine()
	{
		$parsedLine = array('lineid' => '3', 'rateApplicablePercent' => 20.0, 'lineAllowances' => array(
			array('indicator' => 'true', 'actualAmount' => 7.00, 'reason' => 'Handling'),
			array('indicator' => 'true', 'actualAmount' => 2.50, 'reason' => 'Packaging'),
		));
		$lines = $this->call('buildLineChargeLines', array($parsedLine));

		$this->assertCount(2, $lines);
		$this->assertStringContainsString('Handling', $lines[0]->desc);
		$this->assertStringContainsString('Packaging', $lines[1]->desc);
	}

	/**
	 * A line carrying only a charge has no discount to resolve, and the charge is still carried by its
	 * own line - the case where BT-131 is larger than quantity times unit price.
	 *
	 * @return	void
	 */
	public function testAChargeWithoutAnyAllowance()
	{
		$chargeOnly = array(array('indicator' => 'true', 'actualAmount' => 7.00, 'reason' => 'Handling'));

		$this->assertFalse($this->call('resolveLineDiscountPercent', array($chargeOnly, 507.25)), 'no allowance, no discount');
		$this->assertCount(1, $this->call('buildLineChargeLines', array(
			array('lineid' => '1', 'rateApplicablePercent' => 20.0, 'lineAllowances' => $chargeOnly),
		)));
	}
}
