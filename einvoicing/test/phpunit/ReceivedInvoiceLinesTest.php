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
 *      \file       test/phpunit/ReceivedInvoiceLinesTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the lines a received CII document produces: the quantity, the
 *                  unit price and the discount resolved for each one, and the extra lines a charge
 *                  of the line (BG-28) or of the document (BG-21) becomes.
 *                  Covers issues #726, #735, #772, #777, #778 and #783.
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
class ReceivedInvoiceLinesTest extends CommonClassTest
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
	 * Build a one-line CII document, with the line body given as a string.
	 *
	 * @param	string	$lineBody	The children of ram:IncludedSupplyChainTradeLineItem
	 * @return	string				A parsable CII document
	 */
	private function documentWithLine($lineBody)
	{
		return '<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
  <rsm:ExchangedDocument><ram:ID>INV-726</ram:ID></rsm:ExchangedDocument>
  <rsm:SupplyChainTradeTransaction>
    <ram:IncludedSupplyChainTradeLineItem>' . $lineBody . '</ram:IncludedSupplyChainTradeLineItem>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>';
	}

	/**
	 * The subtype of the line (BT-X-8) used to be hardcoded to the placeholder 'NA', so the import had
	 * no way to tell a regular item from a comment or a subtotal. It is now read from the document.
	 *
	 * @return	void
	 */
	public function testLineSubtypeIsReadFromTheDocument()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$xml = $this->documentWithLine('
      <ram:AssociatedDocumentLineDocument>
        <ram:LineID>899</ram:LineID>
        <ram:LineStatusCode>NEW</ram:LineStatusCode>
        <ram:LineStatusReasonCode>INFORMATION</ram:LineStatusReasonCode>
      </ram:AssociatedDocumentLineDocument>
      <ram:SpecifiedTradeProduct><ram:Name>COMMENT</ram:Name></ram:SpecifiedTradeProduct>');

		$lines = $protocol->parseInvoiceLines($xml);
		$this->assertCount(1, $lines);
		$this->assertSame('NEW', $lines[0]['linestatuscode'], 'BT-X-7 reaches the parsed line');
		$this->assertSame('INFORMATION', $lines[0]['linestatusreasoncode'], 'BT-X-8 reaches the parsed line');
	}

	/**
	 * An EN 16931 document has no subtype at all, and every one of its lines is a regular item.
	 *
	 * @return	void
	 */
	public function testLineSubtypeIsEmptyWhenTheDocumentHasNone()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$xml = $this->documentWithLine('
      <ram:AssociatedDocumentLineDocument><ram:LineID>1</ram:LineID></ram:AssociatedDocumentLineDocument>
      <ram:SpecifiedTradeProduct><ram:Name>4W90111</ram:Name></ram:SpecifiedTradeProduct>');

		$lines = $protocol->parseInvoiceLines($xml);
		$this->assertCount(1, $lines);
		$this->assertEmpty($lines[0]['linestatusreasoncode'], 'no BT-X-8 in the document, none in the line');
		$this->assertTrue($this->isDetail($lines[0]), 'a line without a subtype is a regular item');
	}

	/**
	 * The predicate of BR-FREXT-CO-10, over the codes a document can carry.
	 *
	 * @return	void
	 */
	public function testOnlyDetailLinesCarryAnAmount()
	{
		$this->assertTrue($this->isDetail(array()), 'an absent BT-X-8 means a regular item');
		$this->assertTrue($this->isDetail(array('linestatusreasoncode' => null)));
		$this->assertTrue($this->isDetail(array('linestatusreasoncode' => '')));
		$this->assertTrue($this->isDetail(array('linestatusreasoncode' => 'DETAIL')));
		$this->assertTrue($this->isDetail(array('linestatusreasoncode' => ' detail ')), 'the code is compared without case or padding');

		$this->assertFalse($this->isDetail(array('linestatusreasoncode' => 'INFORMATION')));
		$this->assertFalse($this->isDetail(array('linestatusreasoncode' => 'GROUP')));
		$this->assertFalse($this->isDetail(array('linestatusreasoncode' => 'SUB_TOTAL')));
	}

	/**
	 * Call CIIProtocol::isDetailLine() through reflection.
	 *
	 * @param	array	$parsedLine		One parsed line
	 * @return	bool					What the predicate answers
	 */
	private function isDetail(array $parsedLine)
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, 'isDetailLine');
		$method->setAccessible(true);

		return $method->invoke(new CIIProtocol($db), $parsedLine);
	}

	/**
	 * The reported case (issue #726): a line with a net amount and no invoiced quantity. The import used
	 * to store a quantity of zero, so the core recomputed the line at 0.00 and the invoice no longer
	 * totalled what the document announced.
	 *
	 * @return	void
	 */
	public function testAnAmountWithoutQuantityIsCarriedAsOneUnit()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$parsedLine = array('lineid' => '899', 'lineTotalAmount' => 12.0, 'linestatusreasoncode' => 'DETAIL');
		$amounts = $this->callResolveLineAmounts($protocol, $parsedLine, 0.0, 12.0);

		$this->assertSame(1.0, $amounts['qty'], 'the amount is carried as a single unit');
		$this->assertSame(12.0, $amounts['subprice']);
		$this->assertSame(0.0, $amounts['remise_percent']);
		$this->assertStringContainsString('BT-129', $amounts['warning'], 'the repair is reported, not silent');
		$this->assertStringContainsString('BT-131', $amounts['warning']);
	}

	/**
	 * The shape of the document actually reported on #726: the quantity is not absent, it is present and
	 * zero - which satisfies BR-22, a presence test - and the line still announces 12.00.
	 *
	 * @return	void
	 */
	public function testAQuantityPresentAndZeroIsTreatedTheSameWay()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$xml = $this->documentWithLine('
      <ram:AssociatedDocumentLineDocument><ram:LineID>899</ram:LineID></ram:AssociatedDocumentLineDocument>
      <ram:SpecifiedTradeProduct><ram:Name>COMMENT</ram:Name></ram:SpecifiedTradeProduct>
      <ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="C62">0.0000</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>
      <ram:SpecifiedLineTradeSettlement>
        <ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>12.00</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation>
      </ram:SpecifiedLineTradeSettlement>');

		$lines = $protocol->parseInvoiceLines($xml);
		$this->assertCount(1, $lines);
		$this->assertEquals(0.0, (float) $lines[0]['billedquantity'], 'BT-129 is there and it is zero');
		$this->assertTrue($this->isDetail($lines[0]), 'no BT-X-8 means a regular item, whatever the product is called');

		$amounts = $this->callResolveLineAmounts($protocol, $lines[0], (float) $lines[0]['billedquantity'], 12.0);
		$this->assertSame(1.0, $amounts['qty']);
		$this->assertSame(12.0, $amounts['subprice']);
		$this->assertNotSame('', $amounts['warning']);
	}

	/**
	 * A line that is not a regular item carries no amount: BR-FREXT-CO-10 leaves it out of BT-106, so
	 * importing it as a priced line would count its amount a second time.
	 *
	 * @return	void
	 */
	public function testANonDetailLineCarriesNoAmount()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$parsedLine = array('lineid' => '002', 'lineTotalAmount' => 12.0, 'linestatusreasoncode' => 'INFORMATION');
		$amounts = $this->callResolveLineAmounts($protocol, $parsedLine, 0.0, 12.0);

		$this->assertSame(0.0, $amounts['qty']);
		$this->assertSame(0.0, $amounts['subprice']);
		$this->assertSame('', $amounts['warning'], 'a comment line without a quantity is not an anomaly');
	}

	/**
	 * A line that adds up is left exactly as it was, warning included: this is the whole existing corpus
	 * of received documents, and it must not move.
	 *
	 * @return	void
	 */
	public function testALineThatAddsUpIsUntouched()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$parsedLine = array('lineid' => '001', 'lineTotalAmount' => 930.30, 'linestatusreasoncode' => '');
		$amounts = $this->callResolveLineAmounts($protocol, $parsedLine, 2.0, 465.15);

		$this->assertSame(2.0, $amounts['qty']);
		$this->assertSame(465.15, $amounts['subprice']);
		$this->assertSame('', $amounts['warning']);
	}

	/**
	 * A discounted line still has to add up, the discount being applied.
	 *
	 * @return	void
	 */
	public function testADiscountedLineThatAddsUpIsUntouched()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$parsedLine = array('lineid' => '003', 'lineTotalAmount' => 90.0, 'linestatusreasoncode' => 'DETAIL');
		$amounts = $this->callResolveLineAmounts($protocol, $parsedLine, 1.0, 100.0, 10.0);

		$this->assertSame(1.0, $amounts['qty']);
		$this->assertSame('', $amounts['warning'], '100.00 less 10 percent is the 90.00 the document announces');
	}

	/**
	 * A line whose quantity and price do not rebuild what the document announces keeps the amount the
	 * core computes - it is the only one Dolibarr can store - but says so.
	 *
	 * @return	void
	 */
	public function testAnAmountThatDoesNotRebuildIsReported()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$parsedLine = array('lineid' => '004', 'lineTotalAmount' => 100.0, 'linestatusreasoncode' => 'DETAIL');
		$amounts = $this->callResolveLineAmounts($protocol, $parsedLine, 2.0, 40.0);

		$this->assertSame(2.0, $amounts['qty'], 'nothing is invented, the document is only reported');
		$this->assertSame(40.0, $amounts['subprice']);
		$this->assertStringContainsString('BT-131', $amounts['warning']);
		$this->assertStringContainsString('80', $amounts['warning'], 'the warning names what was rebuilt');
	}

	/**
	 * A line at zero on both sides - a free sample, a heading - is not an anomaly and must stay silent.
	 *
	 * @return	void
	 */
	public function testAZeroLineIsNotReported()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$parsedLine = array('lineid' => '002', 'lineTotalAmount' => 0.0, 'linestatusreasoncode' => '');
		$amounts = $this->callResolveLineAmounts($protocol, $parsedLine, 0.0, 0.0);

		$this->assertSame(0.0, $amounts['qty']);
		$this->assertSame('', $amounts['warning']);
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
		// Whatever the allowance resolves to on such a line - today nothing at all, the line being worth
		// 0.00 before the allowance, so there is no percentage that expresses it - the couple it leaves
		// rebuilds nothing, which is the state this test is about. The two are read the way the caller
		// reads them, no more.
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
	/**
	 * Call CIIProtocol::resolveLineUnitPrice() through reflection: pure decision logic, no DB access
	 * and no side effect, the kind of protected method the project convention allows testing this way
	 * instead of through a full import.
	 *
	 * @param	array	$parsedLine		One line as parseInvoiceLines() returns it
	 * @return	float					The unit price the import would store
	 */
	private function unitPrice(array $parsedLine)
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, 'resolveLineUnitPrice');
		$method->setAccessible(true);

		return $method->invoke(new CIIProtocol($db), $parsedLine);
	}

	/**
	 * Call CIIProtocol::resolveLineAmounts() through reflection, the same way LineWithoutQuantityTest
	 * does: it is what tells whether the line the import is about to write rebuilds BT-131.
	 *
	 * @param	array	$parsedLine		One line as parseInvoiceLines() returns it
	 * @param	float	$qty			Quantity read from the document
	 * @param	float	$subprice		Unit price resolved by the caller
	 * @param	float	$remisePercent	Discount percent resolved by the caller
	 * @return	array{qty:float,subprice:float,remise_percent:float,warning:string}	What the import would store
	 */
	private function amounts(array $parsedLine, $qty, $subprice, $remisePercent = 0.0)
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, 'resolveLineAmounts');
		$method->setAccessible(true);

		return $method->invoke(new CIIProtocol($db), $parsedLine, $qty, $subprice, $remisePercent);
	}

	/**
	 * BT-149 reaches the parsed line, with its unit code (BT-150).
	 *
	 * @return	void
	 */
	public function testTheBaseQuantityIsReadFromTheDocument()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$xml = $this->documentWithLine('
      <ram:AssociatedDocumentLineDocument><ram:LineID>1</ram:LineID></ram:AssociatedDocumentLineDocument>
      <ram:SpecifiedTradeProduct><ram:Name>SURCHARGE</ram:Name></ram:SpecifiedTradeProduct>
      <ram:SpecifiedLineTradeAgreement>
        <ram:NetPriceProductTradePrice>
          <ram:ChargeAmount>2.000000</ram:ChargeAmount>
          <ram:BasisQuantity unitCode="C62">100.0000</ram:BasisQuantity>
        </ram:NetPriceProductTradePrice>
      </ram:SpecifiedLineTradeAgreement>
      <ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="C62">50.0000</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>
      <ram:SpecifiedLineTradeSettlement>
        <ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>1.00</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation>
      </ram:SpecifiedLineTradeSettlement>');

		$lines = $protocol->parseInvoiceLines($xml);
		$this->assertCount(1, $lines);
		$this->assertEquals(2.0, (float) $lines[0]['netpriceamount'], 'BT-146');
		$this->assertEquals(100.0, (float) $lines[0]['netpricebasisquantity'], 'BT-149');
		$this->assertSame('C62', $lines[0]['netpricebasisquantityunitcode'], 'BT-150');
	}

	/**
	 * The reported case of issue #778: 2.00 per 100 units, billed on 50. Quantity times the price the
	 * document states rebuilds 100.00; quantity times the price of a single unit rebuilds the 1.00 the
	 * document announces as BT-131.
	 *
	 * @return	void
	 */
	public function testAPricePerHundredIsBroughtBackToOneUnit()
	{
		$parsedLine = array(
			'lineid' => '1',
			'netpriceamount' => 2.0,
			'netpricebasisquantity' => 100.0,
			'billedquantity' => 50.0,
			'lineTotalAmount' => 1.0,
		);

		$this->assertEqualsWithDelta(0.02, $this->unitPrice($parsedLine), 0.0000001, 'BT-146 divided by BT-149');

		$amounts = $this->amounts($parsedLine, 50.0, $this->unitPrice($parsedLine));
		$this->assertSame('', $amounts['warning'], 'the line rebuilds the net amount the document announces');
		$this->assertEquals(1.0, round($amounts['qty'] * $amounts['subprice'], 2), 'BT-131 is 1.00, not 100.00');
	}

	/**
	 * The reported case of issue #777: a metered consumption priced per 100 000. The unit price is far
	 * below the precision the core stores a unit price with, and the total still has to come out right -
	 * calcul_price_total() computes the total of the line from the price it is handed, and only rounds
	 * the copy it stores as pu_ht.
	 *
	 * @return	void
	 */
	public function testAPricePerHundredThousandRebuildsTheAnnouncedAmount()
	{
		$parsedLine = array(
			'lineid' => '2',
			'netpriceamount' => 0.134195,
			'netpricebasisquantity' => 100000.0,
			'billedquantity' => 74391833.0,
			'lineTotalAmount' => 99.83,
		);

		$amounts = $this->amounts($parsedLine, 74391833.0, $this->unitPrice($parsedLine));
		$this->assertEquals(99.83, round($amounts['qty'] * $amounts['subprice'], 2), 'not 9 983 012.03');
		$this->assertStringNotContainsString('rebuild', $amounts['warning'], 'the amount the document announces is reached');
	}

	/**
	 * A unit price the core cannot store is reported. 99.83 spread over 74 391 833 units is 0.00000134,
	 * which price2num(..., 'MU') turns into zero: the line totals what the document announces, because
	 * the core totals it from the unrounded price, but its stored unit price is 0.00000 and editing the
	 * line would recompute it to zero. That has to be said, not left to be found out.
	 *
	 * @return	void
	 */
	public function testAUnitPriceBelowTheStoredPrecisionIsReported()
	{
		$parsedLine = array(
			'lineid' => '2',
			'netpriceamount' => 0.134195,
			'netpricebasisquantity' => 100000.0,
			'billedquantity' => 74391833.0,
			'lineTotalAmount' => 99.83,
		);

		$amounts = $this->amounts($parsedLine, 74391833.0, $this->unitPrice($parsedLine));
		$this->assertStringContainsString('MAIN_MAX_DECIMALS_UNIT', $amounts['warning'], 'the import says why');
		$this->assertStringContainsString('BT-131', $amounts['warning']);

		// A price the core can store says nothing: the tolerable rounding of a unit price is ordinary.
		$ordinary = array('lineid' => '1', 'lineTotalAmount' => 2.08);
		$this->assertSame('', $this->amounts($ordinary, 744.0, 0.002796)['warning'], 'nothing to report on a storable price');
	}

	/**
	 * Every other shape of BT-149 leaves the price alone. It is optional and means one when absent;
	 * BR-64 requires it to be positive when it is there, so a zero or a negative one is a broken
	 * document and the price it states is the best the import can do with it.
	 *
	 * @return	void
	 */
	public function testAnAbsentOrUnusableBaseQuantityLeavesThePriceAlone()
	{
		$this->assertEquals(12.5, $this->unitPrice(array('netpriceamount' => 12.5)), 'BT-149 absent');
		$this->assertEquals(12.5, $this->unitPrice(array('netpriceamount' => 12.5, 'netpricebasisquantity' => null)));
		$this->assertEquals(12.5, $this->unitPrice(array('netpriceamount' => 12.5, 'netpricebasisquantity' => 1.0)), 'BT-149 of one is the default');
		$this->assertEquals(12.5, $this->unitPrice(array('netpriceamount' => 12.5, 'netpricebasisquantity' => 0.0)), 'BR-64 refuses a zero, it is not a divisor');
		$this->assertEquals(12.5, $this->unitPrice(array('netpriceamount' => 12.5, 'netpricebasisquantity' => -10.0)), 'BR-64 refuses a negative one too');
	}

	/**
	 * A fractional base quantity is legal - BR-64 only asks for a positive number - and divides the
	 * same way, raising the unit price instead of lowering it.
	 *
	 * @return	void
	 */
	public function testAFractionalBaseQuantityDividesTheSameWay()
	{
		$parsedLine = array(
			'lineid' => '3',
			'netpriceamount' => 3.0,
			'netpricebasisquantity' => 0.5,
			'billedquantity' => 4.0,
			'lineTotalAmount' => 24.0,
		);

		$this->assertEquals(6.0, $this->unitPrice($parsedLine));

		$amounts = $this->amounts($parsedLine, 4.0, $this->unitPrice($parsedLine));
		$this->assertSame('', $amounts['warning']);
		$this->assertEquals(24.0, round($amounts['qty'] * $amounts['subprice'], 2));
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
	 * The second document of the report: BT-137 is stated, so the base is not in question, but the
	 * allowance amount carries a minus sign - <ActualAmount>-0.6</ActualAmount> under a ChargeIndicator
	 * of false. Taken with that sign the discount came out negative and the line was imported at 39.06
	 * against the 39.08 the document announces. Read as a magnitude, which is what the indicator makes
	 * it, the line totals what the document says and its unit price is BT-146 again.
	 *
	 * @return	void
	 */
	public function testAnAllowanceAmountWrittenNegativeIsReadAsAMagnitude()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$lines = $protocol->parseInvoiceLines($this->documentWithLine('
      <ram:AssociatedDocumentLineDocument><ram:LineID>4</ram:LineID></ram:AssociatedDocumentLineDocument>
      <ram:SpecifiedTradeProduct><ram:Name>6C - Colissimo Domicile Sign. F</ram:Name></ram:SpecifiedTradeProduct>
      <ram:SpecifiedLineTradeAgreement>
        <ram:GrossPriceProductTradePrice><ram:ChargeAmount>19.84</ram:ChargeAmount></ram:GrossPriceProductTradePrice>
        <ram:NetPriceProductTradePrice>
          <ram:ChargeAmount>19.84</ram:ChargeAmount>
          <ram:BasisQuantity unitCode="EA">1</ram:BasisQuantity>
        </ram:NetPriceProductTradePrice>
      </ram:SpecifiedLineTradeAgreement>
      <ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="EA">2</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>
      <ram:SpecifiedLineTradeSettlement>
        <ram:ApplicableTradeTax>
          <ram:TypeCode>VAT</ram:TypeCode>
          <ram:CategoryCode>S</ram:CategoryCode>
          <ram:RateApplicablePercent>20</ram:RateApplicablePercent>
        </ram:ApplicableTradeTax>
        <ram:SpecifiedTradeAllowanceCharge>
          <ram:ChargeIndicator><udt:Indicator>false</udt:Indicator></ram:ChargeIndicator>
          <ram:CalculationPercent>2</ram:CalculationPercent>
          <ram:BasisAmount>39.68</ram:BasisAmount>
          <ram:ActualAmount>-0.6</ram:ActualAmount>
          <ram:ReasonCode>95</ram:ReasonCode>
          <ram:Reason>Remise</ram:Reason>
        </ram:SpecifiedTradeAllowanceCharge>
        <ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>39.08</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation>
      </ram:SpecifiedLineTradeSettlement>'));

		$this->assertEqualsWithDelta(-0.6, $lines[0]['lineAllowances'][0]['actualAmount'], 0.001, 'the sign of the document is read as it stands');

		$discount = $this->call('resolveLineDiscountPercent', array($lines[0]['lineAllowances'], $lines[0]['lineTotalAmount']));

		$this->assertNotFalse($discount);
		$this->assertEqualsWithDelta(0.6, $discount['discountAmount'], 0.001, 'and turned into the size of the allowance');
		$this->assertGreaterThan(0, $discount['percent'], 'a discount that subtracts, not one that adds back');
		$this->assertEqualsWithDelta(39.68, $discount['priceWithoutDiscount'], 0.001, 'the line before its allowance');

		$imported = $this->importedLine($lines[0]);

		$this->assertEqualsWithDelta(19.84, $imported['subprice'], 0.001, 'the unit price is BT-146 again');
		$this->assertEqualsWithDelta(39.08, $imported['rebuilt'], 0.001, 'and the line totals what the document announces');
		$this->assertSame('', $imported['warning'], 'so there is nothing left to report');

		// The control that this test would fail on the code it replaces: -0.6 over the stated base gave a
		// discount of -1.5121 percent, a unit price of 19.24, and the 39.06 of the report.
		$this->assertEqualsWithDelta(-1.5121, round((-0.6 / 39.68) * 100, 4), 0.0001, 'what the signed amount computed');
		$this->assertEqualsWithDelta(39.06, round(2 * 19.24 * (1 - (-1.5121 / 100)), 2), 0.001, 'and the amount it imported');
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
	/**
	 * Call CIIProtocol::buildHeaderChargeLines() through reflection: the whole decision, no database
	 * access and no side effect.
	 *
	 * @param	CIIProtocol		$protocol	Protocol instance
	 * @param	array			$charges	Parsed header allowances and charges
	 * @return	array						The lines the import would add
	 */
	private function callBuildHeaderChargeLines(CIIProtocol $protocol, array $charges)
	{
		$method = new ReflectionMethod(CIIProtocol::class, 'buildHeaderChargeLines');
		$method->setAccessible(true);

		return $method->invoke($protocol, $charges);
	}

	/**
	 * A document carrying one allowance and one charge at document level.
	 *
	 * @return	string	A parsable CII document
	 */
	private function documentWithAllowanceAndCharge()
	{
		return '<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
  <rsm:ExchangedDocument><ram:ID>INV-BG21</ram:ID></rsm:ExchangedDocument>
  <rsm:SupplyChainTradeTransaction>
    <ram:ApplicableHeaderTradeSettlement>
      <ram:SpecifiedTradeAllowanceCharge>
        <ram:ChargeIndicator><udt:Indicator>false</udt:Indicator></ram:ChargeIndicator>
        <ram:ActualAmount>5.00</ram:ActualAmount>
        <ram:ReasonCode>95</ram:ReasonCode>
        <ram:Reason>Commercial gesture</ram:Reason>
        <ram:CategoryTradeTax>
          <ram:TypeCode>VAT</ram:TypeCode>
          <ram:CategoryCode>S</ram:CategoryCode>
          <ram:RateApplicablePercent>20.00</ram:RateApplicablePercent>
        </ram:CategoryTradeTax>
      </ram:SpecifiedTradeAllowanceCharge>
      <ram:SpecifiedTradeAllowanceCharge>
        <ram:ChargeIndicator><udt:Indicator>true</udt:Indicator></ram:ChargeIndicator>
        <ram:ActualAmount>10.40</ram:ActualAmount>
        <ram:ReasonCode>FC</ram:ReasonCode>
        <ram:CategoryTradeTax>
          <ram:TypeCode>VAT</ram:TypeCode>
          <ram:CategoryCode>S</ram:CategoryCode>
          <ram:RateApplicablePercent>20.00</ram:RateApplicablePercent>
        </ram:CategoryTradeTax>
      </ram:SpecifiedTradeAllowanceCharge>
    </ram:ApplicableHeaderTradeSettlement>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>';
	}

	/**
	 * An allowance and a charge are the same element in CII: the parser must keep the indicator that
	 * tells them apart, along with the amount, the reason code and the VAT rate.
	 *
	 * @return	void
	 */
	public function testTheIndicatorReachesTheParsedHeader()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$header = $protocol->parseInvoiceHeader($this->documentWithAllowanceAndCharge());

		$this->assertCount(2, $header['headerAllowancesCharges']);
		$this->assertSame('false', $header['headerAllowancesCharges'][0]['indicator'], 'BG-20, an allowance');
		$this->assertSame('true', $header['headerAllowancesCharges'][1]['indicator'], 'BG-21, a charge');
		$this->assertEqualsWithDelta(10.40, $header['headerAllowancesCharges'][1]['actualAmount'], 0.001);
		$this->assertSame('FC', $header['headerAllowancesCharges'][1]['reasonCode']);
		$this->assertEqualsWithDelta(20.0, $header['headerAllowancesCharges'][1]['rateApplicablePercent'], 0.001);
	}

	/**
	 * Only the charges become lines: an allowance is a DiscountAbsolute and is handled elsewhere, and
	 * turning it into a line here would subtract it twice.
	 *
	 * @return	void
	 */
	public function testOnlyChargesBecomeLines()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$header = $protocol->parseInvoiceHeader($this->documentWithAllowanceAndCharge());
		$lines = $this->callBuildHeaderChargeLines($protocol, $header['headerAllowancesCharges']);

		$this->assertCount(1, $lines, 'the allowance is not one of them');
		$this->assertEquals(1, $lines[0]->qty);
		$this->assertEqualsWithDelta(10.40, $lines[0]->subprice, 0.001);
		$this->assertEqualsWithDelta(20.0, $lines[0]->tva_tx, 0.001);
		$this->assertEquals(0, $lines[0]->remise_percent);
		$this->assertEquals(1, $lines[0]->product_type, 'a charge is a service');
	}

	/**
	 * BR-38 accepts a reason code alone. A bare "FC" says nothing to whoever reads the invoice, so the
	 * line is labelled and the code kept alongside.
	 *
	 * @return	void
	 */
	public function testAHeaderChargeWithOnlyAReasonCodeIsLabelled()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$lines = $this->callBuildHeaderChargeLines($protocol, array(
			array('indicator' => 'true', 'actualAmount' => 10.40, 'reasonCode' => 'FC', 'reason' => '', 'rateApplicablePercent' => 20.0),
		));

		$this->assertCount(1, $lines);
		$this->assertStringContainsString('FC', $lines[0]->desc, 'the code of the document is kept');
		$this->assertNotSame('FC', $lines[0]->desc, 'but it is not the whole label');
	}

	/**
	 * When the document gives a reason in clear (BT-104), it is what the line says - nothing is invented.
	 *
	 * @return	void
	 */
	public function testTheReasonOfTheDocumentIsUsedWhenThereIsOne()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$lines = $this->callBuildHeaderChargeLines($protocol, array(
			array('indicator' => 'true', 'actualAmount' => 12.00, 'reasonCode' => 'FC', 'reason' => 'Frais de port', 'rateApplicablePercent' => 5.5),
		));

		$this->assertSame('Frais de port', $lines[0]->desc);
		$this->assertEqualsWithDelta(5.5, $lines[0]->tva_tx, 0.001);
	}

	/**
	 * Several charges give several lines, in the order of the document; a charge at zero gives none,
	 * the way a zero allowance creates no discount.
	 *
	 * @return	void
	 */
	public function testSeveralChargesAndTheZeroOne()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$lines = $this->callBuildHeaderChargeLines($protocol, array(
			array('indicator' => 'true', 'actualAmount' => 10.40, 'reason' => 'Freight', 'rateApplicablePercent' => 20.0),
			array('indicator' => 'true', 'actualAmount' => 0.00, 'reason' => 'Nothing', 'rateApplicablePercent' => 20.0),
			array('indicator' => 'true', 'actualAmount' => 2.50, 'reason' => 'Packaging', 'rateApplicablePercent' => 20.0),
		));

		$this->assertCount(2, $lines);
		$this->assertSame('Freight', $lines[0]->desc);
		$this->assertSame('Packaging', $lines[1]->desc);
	}

	/**
	 * A document with no allowance and no charge must add nothing - this is the whole existing corpus of
	 * received documents, and it must not move.
	 *
	 * @return	void
	 */
	public function testADocumentWithoutChargesAddsNothing()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$this->assertCount(0, $this->callBuildHeaderChargeLines($protocol, array()));
		$this->assertCount(0, $this->callBuildHeaderChargeLines($protocol, array(
			array('indicator' => 'false', 'actualAmount' => 5.00, 'reason' => 'Commercial gesture', 'rateApplicablePercent' => 20.0),
		)));
	}
}
