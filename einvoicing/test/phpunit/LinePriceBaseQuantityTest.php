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
 *      \file       test/phpunit/LinePriceBaseQuantityTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for a received line priced "per N units" (issues #777 and #778).
 *
 *                  EN 16931 gives BT-146, the item net price, as the price of BT-149 units of the
 *                  item - ram:BasisQuantity in CII, cbc:BaseQuantity in UBL. A Dolibarr line has no
 *                  such divisor, so BT-149 has to be folded into the unit price at import. It used
 *                  to be read and dropped, which multiplied the line, and the total of the invoice,
 *                  by that base quantity.
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
class LinePriceBaseQuantityTest extends CommonClassTest
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
  <rsm:ExchangedDocument><ram:ID>INV-778</ram:ID></rsm:ExchangedDocument>
  <rsm:SupplyChainTradeTransaction>
    <ram:IncludedSupplyChainTradeLineItem>' . $lineBody . '</ram:IncludedSupplyChainTradeLineItem>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>';
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
}
