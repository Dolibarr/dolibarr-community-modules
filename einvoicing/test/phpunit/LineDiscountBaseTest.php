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
 *      \file       test/phpunit/LineDiscountBaseTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the base a line discount is converted against (issue #783).
 *
 *                  A Dolibarr line carries a discount as a percentage, so the fixed amount EN 16931
 *                  states (BT-136) has to be turned into one at import. The base of that percentage is
 *                  BT-137, which is optional and often absent. It used to fall back to BT-131, the
 *                  amount that remains *after* the allowance: the ratio came out too large and the line
 *                  was imported short - 94.74 for a line the document announces at 95.00.
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
class LineDiscountBaseTest extends CommonClassTest
{
	/**
	 * Build a one-line CII document, with the line body given as a string.
	 *
	 * @param	string	$lineBody	The children of ram:IncludedSupplyChainTradeLineItem
	 * @return	string				A parsable CII document
	 */
	private function documentWithLine($lineBody)
	{
		return '<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
  <rsm:ExchangedDocument><ram:ID>INV-783</ram:ID></rsm:ExchangedDocument>
  <rsm:SupplyChainTradeTransaction>
    <ram:IncludedSupplyChainTradeLineItem>' . $lineBody . '</ram:IncludedSupplyChainTradeLineItem>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>';
	}

	/**
	 * One discounted line, as a vendor writes it: a quantity, a net item price (BT-146), an allowance of
	 * a fixed amount, and the net line amount that follows (BT-131). BT-137 is written only when a base
	 * is given, which is the whole point of the test.
	 *
	 * @param	float		$qty			Invoiced quantity (BT-129)
	 * @param	float		$unitPrice		Item net price (BT-146)
	 * @param	float		$allowance		Allowance amount of the line (BT-136)
	 * @param	float		$lineTotal		Net line amount announced (BT-131)
	 * @param	float|null	$basisAmount	BT-137, or null to leave it out as the reported vendor does
	 * @return	string						The document, ready to parse
	 */
	private function discountedLine($qty, $unitPrice, $allowance, $lineTotal, $basisAmount = null)
	{
		$basis = ($basisAmount === null) ? '' : '
          <ram:BasisAmount>' . number_format($basisAmount, 2, '.', '') . '</ram:BasisAmount>';

		return $this->documentWithLine('
      <ram:AssociatedDocumentLineDocument><ram:LineID>1</ram:LineID></ram:AssociatedDocumentLineDocument>
      <ram:SpecifiedTradeProduct><ram:Name>Hosting</ram:Name></ram:SpecifiedTradeProduct>
      <ram:SpecifiedLineTradeAgreement>
        <ram:NetPriceProductTradePrice><ram:ChargeAmount>' . number_format($unitPrice, 6, '.', '') . '</ram:ChargeAmount></ram:NetPriceProductTradePrice>
      </ram:SpecifiedLineTradeAgreement>
      <ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="C62">' . number_format($qty, 4, '.', '') . '</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>
      <ram:SpecifiedLineTradeSettlement>
        <ram:ApplicableTradeTax>
          <ram:TypeCode>VAT</ram:TypeCode>
          <ram:CategoryCode>S</ram:CategoryCode>
          <ram:RateApplicablePercent>20.00</ram:RateApplicablePercent>
        </ram:ApplicableTradeTax>
        <ram:SpecifiedTradeAllowanceCharge>
          <ram:ChargeIndicator><udt:Indicator>false</udt:Indicator></ram:ChargeIndicator>' . $basis . '
          <ram:ActualAmount>' . number_format($allowance, 2, '.', '') . '</ram:ActualAmount>
          <ram:Reason>Commercial discount</ram:Reason>
        </ram:SpecifiedTradeAllowanceCharge>
        <ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>' . number_format($lineTotal, 2, '.', '') . '</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation>
      </ram:SpecifiedLineTradeSettlement>');
	}

	/**
	 * Call a protected method of CIIProtocol through reflection: pure decision logic, no database access
	 * and no side effect, the kind of method the project convention allows testing this way instead of
	 * through a full import.
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
	 * Replay what createSupplierInvoiceLinesFromSource() does with one parsed line, up to the couple
	 * (quantity, unit price, discount) it hands to FactureFournisseur::updateline(), and rebuild the
	 * amount the core will then compute out of it - which is what actually lands on the invoice.
	 *
	 * @param	array	$parsedLine		One line as parseInvoiceLines() returns it
	 * @return	array{qty:float,subprice:float,remise_percent:float,warning:string,rebuilt:float}	The stored line and its total
	 */
	private function importedLine(array $parsedLine)
	{
		$discount = $this->call('resolveLineDiscountPercent', array($parsedLine['lineAllowances'], $parsedLine['lineTotalAmount']));

		$remisePercent = ($discount === false) ? 0.0 : (float) $discount['percent'];
		$subprice = ($discount === false || empty($parsedLine['billedquantity']))
			? (float) $this->call('resolveLineUnitPrice', array($parsedLine))
			: round($discount['priceWithoutDiscount'] / (float) $parsedLine['billedquantity'], 8);

		$amounts = $this->call('resolveLineAmounts', array($parsedLine, (float) $parsedLine['billedquantity'], $subprice, $remisePercent));

		// calcul_price_total() totals a line as quantity x unit price x (1 - discount), rounded to the
		// amount precision, which is what resolveLineAmounts() itself checks BT-131 against.
		$amounts['rebuilt'] = round($amounts['qty'] * $amounts['subprice'] * (1 - ($amounts['remise_percent'] / 100)), 2);

		return $amounts;
	}

	/**
	 * The two lines of the report: an allowance of 5.00 then of 10.00 on a line of 100.00, with no
	 * BT-137. The invoice has to end up at the amount the document announces, to the cent.
	 *
	 * @return	void
	 */
	public function testALineDiscountWithoutItsBaseIsImportedAtTheAnnouncedAmount()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		foreach (array(array(5.00, 95.00), array(10.00, 90.00)) as $case) {
			list($allowance, $announced) = $case;

			$lines = $protocol->parseInvoiceLines($this->discountedLine(1.0, 100.00, $allowance, $announced));
			$this->assertCount(1, $lines);
			$this->assertNull($lines[0]['lineAllowances'][0]['basisAmount'], 'BT-137 is absent, as the reported vendor writes it');

			$imported = $this->importedLine($lines[0]);

			$this->assertEqualsWithDelta($announced, $imported['rebuilt'], 0.001, 'the line totals what BT-131 announces');
			$this->assertSame('', $imported['warning'], 'and nothing has to be repaired or reported');
		}
	}

	/**
	 * The base the percentage is taken against, read on its own: the amount before the allowance, not
	 * the amount after it. This is the change itself, stated as the two figures of the report.
	 *
	 * @return	void
	 */
	public function testTheBaseIsTheAmountBeforeTheDiscount()
	{
		$allowanceOnly = array(array('indicator' => 'false', 'basisAmount' => null, 'actualAmount' => 5.00, 'reason' => 'Commercial discount'));

		$discount = $this->call('resolveLineDiscountPercent', array($allowanceOnly, 95.00));

		$this->assertNotFalse($discount);
		$this->assertEqualsWithDelta(100.00, $discount['base'], 0.001, 'the base is the line before its allowance');
		$this->assertEqualsWithDelta(5.0, $discount['percent'], 0.0001, 'so a 5.00 allowance on a 100.00 line is 5 percent');
		$this->assertEqualsWithDelta(100.00, $discount['priceWithoutDiscount'], 0.001, 'and the unit price of the document is unchanged');

		// The control that this test would fail on the code it replaces: BT-131 as the base gave 5.2632
		// percent, and 100.00 taken down by that much is the 94.74 of the report, not 95.00.
		$this->assertEqualsWithDelta(5.2632, round((5.00 / 95.00) * 100, 4), 0.0001, 'what the former base computed');
		$this->assertEqualsWithDelta(94.74, round(100.00 * (1 - (5.2632 / 100)), 2), 0.001, 'and the amount it imported');
	}

	/**
	 * A document that does state BT-137 is resolved exactly as before: the whole existing corpus of
	 * received documents goes through this branch and must not move.
	 *
	 * @return	void
	 */
	public function testADocumentStatingItsBaseIsUnchanged()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$lines = $protocol->parseInvoiceLines($this->discountedLine(5.0, 100.05, 50.03, 450.22, 500.25));
		$this->assertEqualsWithDelta(500.25, $lines[0]['lineAllowances'][0]['basisAmount'], 0.001, 'BT-137 is read');

		$discount = $this->call('resolveLineDiscountPercent', array($lines[0]['lineAllowances'], $lines[0]['lineTotalAmount']));

		$this->assertEqualsWithDelta(500.25, $discount['base'], 0.001, 'the base of the document is the one used');
		$this->assertEqualsWithDelta(10.001, $discount['percent'], 0.0001);
		$this->assertEqualsWithDelta(450.22, $this->importedLine($lines[0])['rebuilt'], 0.011, 'and the line still totals BT-131');
	}

	/**
	 * The same line with BT-137 left out resolves to the same unit price and the same imported amount:
	 * the fallback rebuilds the base the document did not state, it does not invent another one.
	 *
	 * @return	void
	 */
	public function testTheFallbackRebuildsTheBaseTheDocumentDidNotState()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$withBase = $protocol->parseInvoiceLines($this->discountedLine(5.0, 100.05, 50.03, 450.22, 500.25));
		$withoutBase = $protocol->parseInvoiceLines($this->discountedLine(5.0, 100.05, 50.03, 450.22));

		$statedBase = $this->call('resolveLineDiscountPercent', array($withBase[0]['lineAllowances'], $withBase[0]['lineTotalAmount']));
		$rebuiltBase = $this->call('resolveLineDiscountPercent', array($withoutBase[0]['lineAllowances'], $withoutBase[0]['lineTotalAmount']));

		$this->assertEqualsWithDelta($statedBase['base'], $rebuiltBase['base'], 0.011);
		$this->assertEqualsWithDelta($statedBase['percent'], $rebuiltBase['percent'], 0.0001);
		$this->assertEqualsWithDelta(100.05, $rebuiltBase['priceWithoutDiscount'] / 5, 0.001, 'which is BT-146 for a quantity of 5');
	}

	/**
	 * A line carrying a charge as well as an allowance (issue #735) keeps the treatment that issue gave
	 * it: the charge leaves by a line of its own, so it is out of the base and out of the unit price.
	 *
	 * @return	void
	 */
	public function testAChargeIsStillOutOfTheBase()
	{
		// Quantity 5 at 100.05, an allowance of 50.03 and a charge of 7.00, so BT-131 = 457.22. Without
		// BT-137 this time, which is the case the fallback now has to get right too.
		$lineAllowances = array(
			array('indicator' => 'false', 'basisAmount' => null, 'actualAmount' => 50.03, 'reason' => 'Commercial discount'),
			array('indicator' => 'true', 'actualAmount' => 7.00, 'reasonCode' => 'FC', 'reason' => 'Handling'),
		);

		$discount = $this->call('resolveLineDiscountPercent', array($lineAllowances, 457.22));

		$this->assertNotFalse($discount);
		$this->assertEqualsWithDelta(500.25, $discount['base'], 0.001, 'the gross of the line, charge excluded');
		$this->assertEqualsWithDelta(500.25, $discount['priceWithoutDiscount'], 0.001);
		$this->assertEqualsWithDelta(10.001, $discount['percent'], 0.0001);

		// 500.25 taken down by 10.001 percent is 450.22, and the charge of 7.00 rejoins the invoice as the
		// line buildLineChargeLines() adds, so the two together total the 457.22 of the document.
		$this->assertEqualsWithDelta(450.22, round(500.25 * (1 - ($discount['percent'] / 100)), 2), 0.011);
		$this->assertCount(1, $this->call('buildLineChargeLines', array(
			array('lineid' => '1', 'rateApplicablePercent' => 20.0, 'lineAllowances' => $lineAllowances),
		)));
	}

	/**
	 * Several allowances on one line are summed into a single percentage, and that sum is taken against
	 * the one base: 5.00 and 10.00 off a line of 100.00 is 15 percent, not the 17.65 percent that BT-131
	 * used to give.
	 *
	 * @return	void
	 */
	public function testSeveralAllowancesShareTheOneBase()
	{
		$lineAllowances = array(
			array('indicator' => 'false', 'basisAmount' => null, 'actualAmount' => 5.00, 'reason' => 'Commercial discount'),
			array('indicator' => 'false', 'basisAmount' => null, 'actualAmount' => 10.00, 'reason' => 'Volume rebate'),
		);

		$discount = $this->call('resolveLineDiscountPercent', array($lineAllowances, 85.00));

		$this->assertNotFalse($discount);
		$this->assertEqualsWithDelta(15.00, $discount['discountAmount'], 0.001, 'the two allowances are summed');
		$this->assertEqualsWithDelta(100.00, $discount['base'], 0.001);
		$this->assertEqualsWithDelta(15.0, $discount['percent'], 0.0001);

		$amounts = $this->call('resolveLineAmounts', array(
			array('lineid' => '1', 'lineTotalAmount' => 85.00),
			1.0,
			round($discount['priceWithoutDiscount'] / 1.0, 8),
			(float) $discount['percent'],
		));

		$this->assertEqualsWithDelta(85.00, round($amounts['qty'] * $amounts['subprice'] * (1 - ($amounts['remise_percent'] / 100)), 2), 0.001);
		$this->assertSame('', $amounts['warning']);
	}

	/**
	 * The gift line of issue #772 - BT-131 of -0.30 over a free item - has no percentage that expresses
	 * it: the amount before the allowance is 0.00. No discount is resolved, and the repair of issue #776
	 * carries the amount as a single unit, exactly as it did before this change.
	 *
	 * @return	void
	 */
	public function testTheFreeItemOfTheOtherReportIsStillRepairedTheSameWay()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$lines = $protocol->parseInvoiceLines($this->discountedLine(1.0, 0.00, 0.30, -0.30));

		$this->assertFalse(
			$this->call('resolveLineDiscountPercent', array($lines[0]['lineAllowances'], $lines[0]['lineTotalAmount'])),
			'nothing to convert: the line is worth 0.00 before its allowance'
		);

		$imported = $this->importedLine($lines[0]);

		$this->assertSame(1.0, $imported['qty'], 'the amount is carried as a single unit');
		$this->assertSame(-0.30, $imported['subprice'], 'at the amount the document announces');
		$this->assertSame(0.0, $imported['remise_percent']);
		$this->assertStringContainsString('BT-131', $imported['warning'], 'and the repair is reported, not silent');
	}

	/**
	 * An allowance stated as a percentage rather than an amount goes through the same path: CII carries
	 * ram:ActualAmount in both cases, so nothing else has to be read, and the line still totals BT-131.
	 *
	 * @return	void
	 */
	public function testAnAllowanceWrittenAsAPercentageIsUnaffected()
	{
		$lineAllowances = array(array(
			'indicator' => 'false', 'basisAmount' => null, 'calculationPercent' => 5.0,
			'actualAmount' => 5.00, 'reason' => 'Commercial discount',
		));

		$discount = $this->call('resolveLineDiscountPercent', array($lineAllowances, 95.00));

		$this->assertEqualsWithDelta(5.0, $discount['percent'], 0.0001, 'the percentage of the document is found again');
	}
}
