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
 *      \file       test/phpunit/NegativeLineAmountTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for a received line that subtracts from the invoice (issue #772).
 *
 *                  BR-27 forbids a negative item net price (BT-146), so a line that takes money off
 *                  the invoice carries its sign somewhere else: on the invoiced quantity (BT-129), or
 *                  on the net line amount (BT-131) alone. The reported vendor does the latter on a free
 *                  item: quantity 1, unit price 0.00, a line allowance (BG-27) of 0.30 and a BT-131 of
 *                  -0.30. Quantity times price rebuilt 0.00, so every one of those credits was silently
 *                  dropped and the imported invoice came out above the document.
 *
 *                  The line reproduced below is the one of the reported document, translated into the
 *                  CII the platform hands to the module.
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
class NegativeLineAmountTest extends CommonClassTest
{
	/**
	 * Call CIIProtocol::resolveLineAmounts() through reflection: pure decision logic, no DB access
	 * and no side effect, the kind of protected method the project convention allows testing this way
	 * instead of through a full import.
	 *
	 * @param	CIIProtocol		$protocol		Protocol instance
	 * @param	array			$parsedLine		One line as parseInvoiceLines() returns it
	 * @param	float			$qty			Quantity read from the document
	 * @param	float			$subprice		Unit price resolved by the caller
	 * @param	float			$remisePercent	Discount percent resolved by the caller
	 * @return	array{qty:float,subprice:float,remise_percent:float,warning:string}	What the import would store
	 */
	private function callResolveLineAmounts(CIIProtocol $protocol, array $parsedLine, $qty, $subprice, $remisePercent = 0.0)
	{
		$method = new ReflectionMethod(CIIProtocol::class, 'resolveLineAmounts');
		$method->setAccessible(true);

		return $method->invoke($protocol, $parsedLine, $qty, $subprice, $remisePercent);
	}

	/**
	 * The reported case (issue #772), on the line of the real document: a free item, priced at 0.00,
	 * carrying a line allowance and a net line amount that is a credit. Read and resolved the way
	 * createSupplierInvoiceLinesFromSource() does it, allowance included, because the discount is part
	 * of what the core rebuilds.
	 *
	 * @return	void
	 */
	public function testTheReportedCreditLineReachesTheInvoice()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$xml = $this->documentWithLine('
      <ram:AssociatedDocumentLineDocument><ram:LineID>475726401</ram:LineID></ram:AssociatedDocumentLineDocument>
      <ram:SpecifiedTradeProduct><ram:Name>Free starter account with domain</ram:Name></ram:SpecifiedTradeProduct>
      <ram:SpecifiedLineTradeAgreement>
        <ram:NetPriceProductTradePrice><ram:ChargeAmount>0.000000</ram:ChargeAmount></ram:NetPriceProductTradePrice>
      </ram:SpecifiedLineTradeAgreement>
      <ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="C62">1.0000</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>
      <ram:SpecifiedLineTradeSettlement>
        <ram:ApplicableTradeTax>
          <ram:TypeCode>VAT</ram:TypeCode>
          <ram:CategoryCode>S</ram:CategoryCode>
          <ram:RateApplicablePercent>20.00</ram:RateApplicablePercent>
        </ram:ApplicableTradeTax>
        <ram:SpecifiedTradeAllowanceCharge>
          <ram:ChargeIndicator><udt:Indicator>false</udt:Indicator></ram:ChargeIndicator>
          <ram:ActualAmount>0.30</ram:ActualAmount>
          <ram:Reason>GIFT - SPECIAL</ram:Reason>
        </ram:SpecifiedTradeAllowanceCharge>
        <ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>-0.30</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation>
      </ram:SpecifiedLineTradeSettlement>');

		$lines = $protocol->parseInvoiceLines($xml);
		$this->assertCount(1, $lines);
		$this->assertEquals(-0.30, (float) $lines[0]['lineTotalAmount'], 'BT-131 keeps its sign');
		$this->assertEquals(0.0, (float) $lines[0]['netpriceamount'], 'BT-146 is zero, BR-27 forbidding it to be negative');
		$this->assertCount(1, $lines[0]['lineAllowances'], 'the line allowance is read');

		// What createSupplierInvoiceLinesFromSource() resolves before it calls resolveLineAmounts().
		// Whatever the allowance resolves to on such a line - today a discount of -100 percent, over an
		// amount the allowance brings back to 0.00 - the couple it leaves rebuilds nothing, which is the
		// state this test is about. The two are read the way the caller reads them, no more.
		$discount = $this->callResolveLineDiscountPercent($protocol, $lines[0]['lineAllowances'], $lines[0]['lineTotalAmount']);
		$remisePercent = ($discount === false) ? 0.0 : (float) $discount['percent'];
		$subprice = ($discount === false)
			? (float) $lines[0]['netpriceamount']
			: round($discount['priceWithoutDiscount'] / (float) $lines[0]['billedquantity'], 8);
		$this->assertSame(0.0, round((float) $lines[0]['billedquantity'] * $subprice * (1 - ($remisePercent / 100)), 2), 'quantity, price and discount rebuild nothing');

		$amounts = $this->callResolveLineAmounts(
			$protocol,
			$lines[0],
			(float) $lines[0]['billedquantity'],
			$subprice,
			$remisePercent
		);

		$this->assertSame(1.0, $amounts['qty'], 'the amount is carried as a single unit');
		$this->assertSame(-0.30, $amounts['subprice'], 'the credit of the document reaches the invoice');
		$this->assertSame(0.0, $amounts['remise_percent'], 'and the meaningless discount goes with it');
		$this->assertStringContainsString('BT-131', $amounts['warning'], 'the repair is reported, not silent');
		$this->assertStringContainsString('BT-146', $amounts['warning']);
	}

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
  <rsm:ExchangedDocument><ram:ID>INV-772</ram:ID></rsm:ExchangedDocument>
  <rsm:SupplyChainTradeTransaction>
    <ram:IncludedSupplyChainTradeLineItem>' . $lineBody . '</ram:IncludedSupplyChainTradeLineItem>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>';
	}

	/**
	 * Call CIIProtocol::resolveLineDiscountPercent() through reflection.
	 *
	 * @param	CIIProtocol		$protocol			Protocol instance
	 * @param	array			$lineAllowances		Allowances as parseInvoiceLines() returns them
	 * @param	float			$lineTotalAmount	BT-131 of the line
	 * @return	false|array{percent:float,base:float,discountAmount:float,priceWithoutDiscount:float}	What the caller would apply
	 */
	private function callResolveLineDiscountPercent(CIIProtocol $protocol, array $lineAllowances, $lineTotalAmount)
	{
		$method = new ReflectionMethod(CIIProtocol::class, 'resolveLineDiscountPercent');
		$method->setAccessible(true);

		return $method->invoke($protocol, $lineAllowances, $lineTotalAmount);
	}

	/**
	 * The same shape with a positive amount: a priced line whose unit price is missing is repaired the
	 * same way. Nothing about the fix is specific to a credit.
	 *
	 * @return	void
	 */
	public function testAnAmountWithoutUnitPriceIsCarriedAsOneUnit()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$parsedLine = array('lineid' => '18', 'lineTotalAmount' => 42.0, 'linestatusreasoncode' => 'DETAIL');
		$amounts = $this->callResolveLineAmounts($protocol, $parsedLine, 3.0, 0.0);

		$this->assertSame(1.0, $amounts['qty']);
		$this->assertSame(42.0, $amounts['subprice']);
		$this->assertNotSame('', $amounts['warning']);
	}

	/**
	 * The compliant way to write a credit line - a negative quantity over a non-negative unit price,
	 * which is what BR-27 leaves an issuer - already added up and must stay exactly as it was.
	 *
	 * @return	void
	 */
	public function testACreditWrittenOnTheQuantityIsUntouched()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$parsedLine = array('lineid' => '19', 'lineTotalAmount' => -0.30, 'linestatusreasoncode' => '');
		$amounts = $this->callResolveLineAmounts($protocol, $parsedLine, -1.0, 0.30);

		$this->assertSame(-1.0, $amounts['qty'], 'nothing is rewritten, the line already rebuilds its amount');
		$this->assertSame(0.30, $amounts['subprice']);
		$this->assertSame('', $amounts['warning']);
	}

	/**
	 * A line that announces a credit while its quantity and price rebuild a charge would move the
	 * invoice by twice its amount. The sign the document gives on BT-131 wins.
	 *
	 * @return	void
	 */
	public function testASignThatDisagreesWithTheDocumentIsCorrected()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$parsedLine = array('lineid' => '20', 'lineTotalAmount' => -12.50, 'linestatusreasoncode' => 'DETAIL');
		$amounts = $this->callResolveLineAmounts($protocol, $parsedLine, 1.0, 12.50);

		$this->assertSame(1.0, $amounts['qty']);
		$this->assertSame(-12.50, $amounts['subprice']);
		$this->assertStringContainsString('opposite sign', $amounts['warning']);
	}

	/**
	 * A whole credit note line - negative on both sides at once - rebuilds a positive amount and the
	 * document says positive too, so it is left alone.
	 *
	 * @return	void
	 */
	public function testTwoNegativesThatRebuildAChargeAreUntouched()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$parsedLine = array('lineid' => '21', 'lineTotalAmount' => 30.0, 'linestatusreasoncode' => '');
		$amounts = $this->callResolveLineAmounts($protocol, $parsedLine, -2.0, -15.0);

		$this->assertSame(-2.0, $amounts['qty']);
		$this->assertSame(-15.0, $amounts['subprice']);
		$this->assertSame('', $amounts['warning']);
	}

	/**
	 * A free line - zero on both sides - is not an anomaly and must stay silent, credit or not.
	 *
	 * @return	void
	 */
	public function testAFreeLineStaysSilent()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$parsedLine = array('lineid' => '22', 'lineTotalAmount' => 0.0, 'linestatusreasoncode' => '');
		$amounts = $this->callResolveLineAmounts($protocol, $parsedLine, 1.0, 0.0);

		$this->assertSame(1.0, $amounts['qty'], 'nothing to repair, the document announces nothing');
		$this->assertSame(0.0, $amounts['subprice']);
		$this->assertSame('', $amounts['warning']);
	}
}
