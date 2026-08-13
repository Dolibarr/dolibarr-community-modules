<?php
/* Copyright (C) 2026 ATM Consulting
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
 *      \file       test/phpunit/CIIProtocolTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the line billing period (EN 16931 BG-26 / BT-134 / BT-135), in
 *                  both directions.
 *                  Export (issue #435): CIIProtocol::buildLineItem() must map the line
 *                  date_start/date_end (already parsed upstream into linePeriodStart/linePeriodEnd)
 *                  to the BillingSpecifiedPeriod block, placed between ApplicableTradeTax and
 *                  SpecifiedTradeAllowanceCharge/SpecifiedTradeSettlementLineMonetarySummation as
 *                  required by the CII D22B schema sequence.
 *                  Import (issue #576): CIIProtocol::resolveLinePeriod() must turn what the parser
 *                  read back into the timestamps a supplier invoice line stores, keeping one side
 *                  alone and refusing a period that ends before it starts.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

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
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class CIIProtocolTest extends CommonClassTest
{
	/**
	 * Call the private CIIProtocol::buildLineItem() through reflection: pure line-level XML
	 * generation logic (no DB access, no side effect), the kind of private method the project
	 * convention allows testing this way instead of via a full document generation round-trip.
	 *
	 * @param	CIIProtocol		$protocol	Protocol instance
	 * @param	DOMDocument		$doc		Document used to create nodes
	 * @param	array			$line		Line data (see baseLineData())
	 * @return	DOMElement					The generated IncludedSupplyChainTradeLineItem node
	 */
	private function callBuildLineItem(CIIProtocol $protocol, DOMDocument $doc, array $line): DOMElement
	{
		$method = new ReflectionMethod(CIIProtocol::class, 'buildLineItem');
		$method->setAccessible(true);

		return $method->invoke($protocol, $doc, $line, 'EN16931');
	}

	/**
	 * Minimal line data covering every key read by buildLineItem(), with a neutral (no period,
	 * no discount, not a deposit) baseline that each test overrides as needed.
	 *
	 * @return array
	 */
	private function baseLineData(): array
	{
		return [
			'lineid' => 1,
			'prodsellerid' => '',
			'prodname' => 'Test product',
			'proddesc' => '',
			'netpriceamount' => 100.0,
			'billedquantity' => 1.0,
			'billedquantityunitcode' => 'C62',
			'tva_tx' => 20.0,
			'vat_src_code' => '',
			'categoryCode' => 'S',
			'rateApplicablePercent' => '20.00',
			'discountPercent' => 0,
			'lineTotalAmount' => 100.0,
			'linePeriodStart' => null,
			'linePeriodEnd' => null,
			'isDepositLine' => false,
		];
	}

	/**
	 * Return the direct DOMElement children (skipping comment nodes) of a node, as a plain
	 * array of tag names in document order - used to assert the CII schema element sequence.
	 *
	 * @param	DOMElement	$node	Parent node
	 * @return	array<int,string>	Tag names of DOMElement children, in order
	 */
	private function childElementNames(DOMElement $node): array
	{
		$names = [];
		foreach ($node->childNodes as $child) {
			if ($child instanceof DOMElement) {
				$names[] = $child->tagName;
			}
		}
		return $names;
	}

	/**
	 * With both bounds set, BillingSpecifiedPeriod must contain a StartDateTime and an
	 * EndDateTime, each formatted "Ymd" with the format="102" attribute (CII date format),
	 * and be positioned right after ApplicableTradeTax.
	 *
	 * @return void
	 */
	public function testBothDatesSetProducesFullPeriod()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$doc = new DOMDocument();

		$line = $this->baseLineData();
		$line['linePeriodStart'] = new DateTime('2026-07-01');
		$line['linePeriodEnd'] = new DateTime('2026-07-31');

		$el = $this->callBuildLineItem($protocol, $doc, $line);
		$sett = $el->getElementsByTagName('ram:SpecifiedLineTradeSettlement')->item(0);
		$this->assertNotNull($sett);

		$this->assertEquals(
			['ram:ApplicableTradeTax', 'ram:BillingSpecifiedPeriod', 'ram:SpecifiedTradeSettlementLineMonetarySummation'],
			$this->childElementNames($sett)
		);

		$period = $sett->getElementsByTagName('ram:BillingSpecifiedPeriod')->item(0);
		$this->assertNotNull($period);

		$start = $period->getElementsByTagName('ram:StartDateTime')->item(0);
		$this->assertNotNull($start);
		$startStr = $start->getElementsByTagName('udt:DateTimeString')->item(0);
		$this->assertEquals('20260701', $startStr->nodeValue);
		$this->assertEquals('102', $startStr->getAttribute('format'));

		$end = $period->getElementsByTagName('ram:EndDateTime')->item(0);
		$this->assertNotNull($end);
		$endStr = $end->getElementsByTagName('udt:DateTimeString')->item(0);
		$this->assertEquals('20260731', $endStr->nodeValue);
		$this->assertEquals('102', $endStr->getAttribute('format'));
	}

	/**
	 * With only the start date set, BillingSpecifiedPeriod must contain StartDateTime but no
	 * EndDateTime (BR-CO-20 allows a single-bound period).
	 *
	 * @return void
	 */
	public function testOnlyStartDateProducesStartOnly()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$doc = new DOMDocument();

		$line = $this->baseLineData();
		$line['linePeriodStart'] = new DateTime('2026-07-01');

		$el = $this->callBuildLineItem($protocol, $doc, $line);
		$sett = $el->getElementsByTagName('ram:SpecifiedLineTradeSettlement')->item(0);

		$period = $sett->getElementsByTagName('ram:BillingSpecifiedPeriod')->item(0);
		$this->assertNotNull($period);
		$this->assertEquals(1, $period->getElementsByTagName('ram:StartDateTime')->length);
		$this->assertEquals(0, $period->getElementsByTagName('ram:EndDateTime')->length);
	}

	/**
	 * With only the end date set, BillingSpecifiedPeriod must contain EndDateTime but no
	 * StartDateTime.
	 *
	 * @return void
	 */
	public function testOnlyEndDateProducesEndOnly()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$doc = new DOMDocument();

		$line = $this->baseLineData();
		$line['linePeriodEnd'] = new DateTime('2026-07-31');

		$el = $this->callBuildLineItem($protocol, $doc, $line);
		$sett = $el->getElementsByTagName('ram:SpecifiedLineTradeSettlement')->item(0);

		$period = $sett->getElementsByTagName('ram:BillingSpecifiedPeriod')->item(0);
		$this->assertNotNull($period);
		$this->assertEquals(0, $period->getElementsByTagName('ram:StartDateTime')->length);
		$this->assertEquals(1, $period->getElementsByTagName('ram:EndDateTime')->length);
	}

	/**
	 * With neither bound set (the vast majority of lines), no BillingSpecifiedPeriod node must
	 * be emitted at all (EN 16931 BR-CO-20: never an empty period block).
	 *
	 * @return void
	 */
	public function testNoDateProducesNoPeriodBlock()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$doc = new DOMDocument();

		$line = $this->baseLineData();

		$el = $this->callBuildLineItem($protocol, $doc, $line);
		$sett = $el->getElementsByTagName('ram:SpecifiedLineTradeSettlement')->item(0);

		$this->assertEquals(0, $sett->getElementsByTagName('ram:BillingSpecifiedPeriod')->length);
	}

	/**
	 * When a line has both a period and a discount, BillingSpecifiedPeriod must still be
	 * inserted before SpecifiedTradeAllowanceCharge (the discount block) - this is the exact
	 * case that a naive "insert before MonetarySummation" implementation would get wrong,
	 * since the CII D22B schema requires ApplicableTradeTax, then BillingSpecifiedPeriod, then
	 * SpecifiedTradeAllowanceCharge, then SpecifiedTradeSettlementLineMonetarySummation, in
	 * that exact order.
	 *
	 * @return void
	 */
	public function testPeriodAndDiscountKeepSchemaOrder()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$doc = new DOMDocument();

		$line = $this->baseLineData();
		$line['linePeriodStart'] = new DateTime('2026-07-01');
		$line['linePeriodEnd'] = new DateTime('2026-07-31');
		$line['discountPercent'] = 10;

		$el = $this->callBuildLineItem($protocol, $doc, $line);
		$sett = $el->getElementsByTagName('ram:SpecifiedLineTradeSettlement')->item(0);

		$this->assertEquals(
			['ram:ApplicableTradeTax', 'ram:BillingSpecifiedPeriod', 'ram:SpecifiedTradeAllowanceCharge', 'ram:SpecifiedTradeSettlementLineMonetarySummation'],
			$this->childElementNames($sett)
		);
	}

	/**
	 * Call the private CIIProtocol::resolveLinePeriod() through reflection, the same convention as
	 * callBuildLineItem() above: it reads nothing but its argument and touches no database.
	 *
	 * @param	CIIProtocol		$protocol	Protocol instance
	 * @param	array			$parsedLine	One line, as parseInvoiceLines() returns it
	 * @return	array{start: ?int, end: ?int}
	 */
	private function callResolveLinePeriod(CIIProtocol $protocol, array $parsedLine): array
	{
		$method = new ReflectionMethod(CIIProtocol::class, 'resolveLinePeriod');
		$method->setAccessible(true);

		return $method->invoke($protocol, $parsedLine);
	}

	/**
	 * A received line with both dates gives the two timestamps the supplier invoice line stores. The
	 * assertion is on the date, not on the exact second: what matters is the day the document declared.
	 *
	 * @return void
	 */
	public function testAReceivedPeriodBecomesTheLineDates()
	{
		global $db;

		$period = $this->callResolveLinePeriod(new CIIProtocol($db), [
			'lineid' => '1',
			'linePeriodStart' => '2026-06-01',
			'linePeriodEnd' => '2026-06-30',
		]);

		$this->assertIsInt($period['start']);
		$this->assertIsInt($period['end']);
		$this->assertSame('2026-06-01', dol_print_date($period['start'], '%Y-%m-%d', 'gmt'));
		$this->assertSame('2026-06-30', dol_print_date($period['end'], '%Y-%m-%d', 'gmt'));
	}

	/**
	 * One side alone is a period BR-CO-20 accepts, and facture_fourn_det holds one date without the
	 * other, so the side that is there is kept rather than the whole period dropped.
	 *
	 * @return void
	 */
	public function testOneSideAloneIsImported()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$startOnly = $this->callResolveLinePeriod($protocol, ['linePeriodStart' => '2026-06-01', 'linePeriodEnd' => null]);
		$this->assertSame('2026-06-01', dol_print_date($startOnly['start'], '%Y-%m-%d', 'gmt'));
		$this->assertNull($startOnly['end']);

		$endOnly = $this->callResolveLinePeriod($protocol, ['linePeriodStart' => null, 'linePeriodEnd' => '2026-06-30']);
		$this->assertNull($endOnly['start']);
		$this->assertSame('2026-06-30', dol_print_date($endOnly['end'], '%Y-%m-%d', 'gmt'));
	}

	/**
	 * A line with no period declares none: no date is invented from the invoice date, and the keys may
	 * be absent altogether since the parser only sets what the document carried.
	 *
	 * @return void
	 */
	public function testALineWithoutAPeriodGetsNoDates()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$this->assertSame(['start' => null, 'end' => null], $this->callResolveLinePeriod($protocol, []));
		$this->assertSame(['start' => null, 'end' => null], $this->callResolveLinePeriod($protocol, ['linePeriodStart' => null, 'linePeriodEnd' => null]));
		$this->assertSame(['start' => null, 'end' => null], $this->callResolveLinePeriod($protocol, ['linePeriodStart' => '', 'linePeriodEnd' => '']));
	}

	/**
	 * A period that ends before it starts breaks BR-30 and must not reach updateline(), which answers
	 * -1 on that pair (ErrorStartDateGreaterEnd) and would fail the import of the whole invoice. The
	 * line is imported without its period instead.
	 *
	 * @return void
	 */
	public function testAnInvertedPeriodIsDroppedRatherThanFailingTheImport()
	{
		global $db;

		$period = $this->callResolveLinePeriod(new CIIProtocol($db), [
			'lineid' => '2',
			'linePeriodStart' => '2026-03-01',
			'linePeriodEnd' => '2026-01-31',
		]);

		$this->assertSame(['start' => null, 'end' => null], $period);
	}

	/**
	 * Something unreadable where a date was expected must not reach idate() either: dol_stringtotime()
	 * answers a value that is not a usable timestamp, and the line is imported without the period.
	 *
	 * @return void
	 */
	public function testAnUnreadableDateIsIgnored()
	{
		global $db;

		$period = $this->callResolveLinePeriod(new CIIProtocol($db), [
			'linePeriodStart' => 'not a date',
			'linePeriodEnd' => 'NA',
		]);

		$this->assertSame(['start' => null, 'end' => null], $period);
	}

	/**
	 * The shape resolveLinePeriod() expects is not an assumption: this reads a document back through
	 * parseInvoiceLines() and checks the parser hands the two dates as 'Y-m-d' strings, then that the pair
	 * resolves to the days the document declared.
	 *
	 * @return void
	 */
	public function testWhatTheParserHandsOverIsWhatTheImportReads()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$xml = '<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100" xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100" xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
  <rsm:SupplyChainTradeTransaction>
    <ram:IncludedSupplyChainTradeLineItem>
      <ram:AssociatedDocumentLineDocument><ram:LineID>1</ram:LineID></ram:AssociatedDocumentLineDocument>
      <ram:SpecifiedTradeProduct><ram:Name>Prestation</ram:Name></ram:SpecifiedTradeProduct>
      <ram:SpecifiedLineTradeAgreement>
        <ram:NetPriceProductTradePrice><ram:ChargeAmount>100.00</ram:ChargeAmount></ram:NetPriceProductTradePrice>
      </ram:SpecifiedLineTradeAgreement>
      <ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="C62">1</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>
      <ram:SpecifiedLineTradeSettlement>
        <ram:ApplicableTradeTax>
          <ram:TypeCode>VAT</ram:TypeCode>
          <ram:CategoryCode>S</ram:CategoryCode>
          <ram:RateApplicablePercent>20.00</ram:RateApplicablePercent>
        </ram:ApplicableTradeTax>
        <ram:BillingSpecifiedPeriod>
          <ram:StartDateTime><udt:DateTimeString format="102">20260601</udt:DateTimeString></ram:StartDateTime>
          <ram:EndDateTime><udt:DateTimeString format="102">20260630</udt:DateTimeString></ram:EndDateTime>
        </ram:BillingSpecifiedPeriod>
        <ram:SpecifiedTradeSettlementLineMonetarySummation><ram:LineTotalAmount>100.00</ram:LineTotalAmount></ram:SpecifiedTradeSettlementLineMonetarySummation>
      </ram:SpecifiedLineTradeSettlement>
    </ram:IncludedSupplyChainTradeLineItem>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>';

		$lines = $protocol->parseInvoiceLines($xml);
		$this->assertCount(1, $lines);
		$this->assertSame('2026-06-01', $lines[0]['linePeriodStart'], 'the parser normalises BT-134 to Y-m-d');
		$this->assertSame('2026-06-30', $lines[0]['linePeriodEnd'], 'the parser normalises BT-135 to Y-m-d');

		$period = $this->callResolveLinePeriod($protocol, $lines[0]);
		$this->assertSame('2026-06-01', dol_print_date($period['start'], '%Y-%m-%d', 'gmt'));
		$this->assertSame('2026-06-30', dol_print_date($period['end'], '%Y-%m-%d', 'gmt'));
	}
}
