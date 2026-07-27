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
 *      \brief      PHPUnit test for the line billing period export (issue #435):
 *                  CIIProtocol::buildLineItem() must map the line date_start/date_end
 *                  (already parsed upstream into linePeriodStart/linePeriodEnd) to the
 *                  BillingSpecifiedPeriod block (EN 16931 BG-26 / BT-134 / BT-135), placed
 *                  between ApplicableTradeTax and SpecifiedTradeAllowanceCharge/
 *                  SpecifiedTradeSettlementLineMonetarySummation as required by the CII
 *                  D22B schema sequence.
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
require_once DOL_DOCUMENT_ROOT . '/../test/phpunit/CommonClassTest.class.php';

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
}
