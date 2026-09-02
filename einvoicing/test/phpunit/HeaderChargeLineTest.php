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
 *      \file       test/phpunit/HeaderChargeLineTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the document level charges of a received document (BG-21).
 *
 *                  BR-CO-13 defines the total of the invoice as the sum of the line net amounts,
 *                  minus the allowances of the document level (BT-107), plus its charges (BT-108).
 *                  In CII an allowance and a charge are the same element, told apart only by
 *                  ram:ChargeIndicator, and the import used to keep the allowances and drop the
 *                  charges - so the invoice totalled less than the document it came from.
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
class HeaderChargeLineTest extends CommonClassTest
{
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
	public function testAChargeWithOnlyAReasonCodeIsLabelled()
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
