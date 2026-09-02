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
 *      \file       test/phpunit/LineWithoutQuantityTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for a received line that carries an amount but no invoiced quantity
 *                  (issue #726), and for the subtype of an EXTENDED line.
 *
 *                  BR-22 is fatal on the EN 16931 profile and tests the presence of
 *                  ram:BilledQuantity, so a quantity of zero passes and an absent element does not.
 *                  On EXTENDED CTC-FR, BR-FREXT-BR-22 requires it only for a line whose subtype
 *                  BT-X-8 is DETAIL or absent, and BR-FREXT-CO-10 sums BT-131 into BT-106 over those
 *                  same lines only. The parser has to read that subtype, and the import has to stop
 *                  turning the amount of such a line into a silent zero.
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
class LineWithoutQuantityTest extends CommonClassTest
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
		$this->assertStringContainsString('BR-22', $amounts['warning']);
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
}
