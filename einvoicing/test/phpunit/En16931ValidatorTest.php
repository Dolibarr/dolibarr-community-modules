<?php
/* Copyright (C) 2026		Gregory Aliot			<greg.aliot@gmail.com>
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
 */

/**
 *      \file       test/phpunit/En16931ValidatorTest.php
 *      \ingroup    test
 *      \brief      PHPUnit tests for the En16931Validator local business-rule checks.
 *
 *                  Validates the arithmetic rules (BR-CO-10 through BR-CO-17) on in-memory
 *                  XML strings. No database is accessed by these tests.
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
dol_include_once('einvoicing/class/utils/En16931Validator.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class En16931ValidatorTest extends CommonClassTest
{
	/**
	 * Build a minimal CII CrossIndustryInvoice XML string for use in tests.
	 *
	 * Only the elements the validator actually reads are included. SIREN nodes are
	 * omitted intentionally: their absence skips BR-FR-10, which is not under test here.
	 *
	 * @param	string								$typeCode		BT-3 document type code (e.g. '380', '381')
	 * @param	array<int,array<string,string>>		$lines			Line items: keys netPrice, lineTotal, vatRate
	 * @param	array<string,string>				$summation		Monetary summation: keys lineTotal, taxBasis,
	 *																taxTotal, grandTotal, duePayable, prepaid (opt.)
	 * @param	array<int,array<string,string>>		$vatBreakdown	VAT breakdown entries: keys calculated, basis, rate
	 * @return	string								Well-formed CII XML string
	 */
	private function buildCiiXml($typeCode, $lines, $summation, $vatBreakdown)
	{
		$linesXml = '';
		foreach ($lines as $i => $line) {
			$linesXml .= '
		<ram:IncludedSupplyChainTradeLineItem>
			<ram:AssociatedDocumentLineDocument>
				<ram:LineID>' . ($i + 1) . '</ram:LineID>
			</ram:AssociatedDocumentLineDocument>
			<ram:SpecifiedLineTradeAgreement>
				<ram:NetPriceProductTradePrice>
					<ram:ChargeAmount>' . $line['netPrice'] . '</ram:ChargeAmount>
				</ram:NetPriceProductTradePrice>
			</ram:SpecifiedLineTradeAgreement>
			<ram:SpecifiedLineTradeSettlement>
				<ram:ApplicableTradeTax>
					<ram:TypeCode>VAT</ram:TypeCode>
					<ram:CategoryCode>S</ram:CategoryCode>
					<ram:RateApplicablePercent>' . $line['vatRate'] . '</ram:RateApplicablePercent>
				</ram:ApplicableTradeTax>
				<ram:SpecifiedTradeSettlementLineMonetarySummation>
					<ram:LineTotalAmount>' . $line['lineTotal'] . '</ram:LineTotalAmount>
				</ram:SpecifiedTradeSettlementLineMonetarySummation>
			</ram:SpecifiedLineTradeSettlement>
		</ram:IncludedSupplyChainTradeLineItem>';
		}

		$vatXml = '';
		foreach ($vatBreakdown as $vat) {
			$vatXml .= '
			<ram:ApplicableTradeTax>
				<ram:CalculatedAmount>' . $vat['calculated'] . '</ram:CalculatedAmount>
				<ram:TypeCode>VAT</ram:TypeCode>
				<ram:BasisAmount>' . $vat['basis'] . '</ram:BasisAmount>
				<ram:CategoryCode>S</ram:CategoryCode>
				<ram:RateApplicablePercent>' . $vat['rate'] . '</ram:RateApplicablePercent>
			</ram:ApplicableTradeTax>';
		}

		$prepaidXml = isset($summation['prepaid'])
			? '<ram:TotalPrepaidAmount>' . $summation['prepaid'] . '</ram:TotalPrepaidAmount>'
			: '';

		return '<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice
	xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"
	xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
	xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
	<rsm:ExchangedDocument>
		<ram:TypeCode>' . $typeCode . '</ram:TypeCode>
	</rsm:ExchangedDocument>
	<rsm:SupplyChainTradeTransaction>' . $linesXml . '
		<ram:ApplicableHeaderTradeSettlement>
			<ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>' . $vatXml . '
			<ram:SpecifiedTradeSettlementHeaderMonetarySummation>
				<ram:LineTotalAmount>' . $summation['lineTotal'] . '</ram:LineTotalAmount>
				<ram:TaxBasisTotalAmount>' . $summation['taxBasis'] . '</ram:TaxBasisTotalAmount>
				<ram:TaxTotalAmount currencyID="EUR">' . $summation['taxTotal'] . '</ram:TaxTotalAmount>
				<ram:GrandTotalAmount>' . $summation['grandTotal'] . '</ram:GrandTotalAmount>
				' . $prepaidXml . '
				<ram:DuePayableAmount>' . $summation['duePayable'] . '</ram:DuePayableAmount>
			</ram:SpecifiedTradeSettlementHeaderMonetarySummation>
		</ram:ApplicableHeaderTradeSettlement>
	</rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>';
	}

	/**
	 * A TypeCode 380 invoice with a legitimately negative total (correction invoice with
	 * negative-quantity lines, GrandTotal < 0, TotalPrepaidAmount = 0) must not trigger
	 * the BR-CO-16 "DuePayableAmount is negative" guard.
	 *
	 * Real-world case: DIAC LOCATION / Mobilize invoice P61250497 — two lines, one of
	 * which carries qty -1, resulting in GrandTotal = -182.77 and DuePayable = -182.77
	 * with no prepaid amount at all.
	 *
	 * @return void
	 */
	public function testType380WithNegativeGrandTotalIsNotBlockedByBRCO16()
	{
		$xml = $this->buildCiiXml(
			'380',
			array(
				array('netPrice' => '100.48', 'lineTotal' => '100.48',  'vatRate' => '20.00'),
				array('netPrice' => '252.79', 'lineTotal' => '-252.79', 'vatRate' => '20.00'),
			),
			array(
				'lineTotal'  => '-152.31',
				'taxBasis'   => '-152.31',
				'taxTotal'   => '-30.46',
				'grandTotal' => '-182.77',
				'prepaid'    => '0.00',
				'duePayable' => '-182.77',
			),
			array(
				array('calculated' => '-30.46', 'basis' => '-152.31', 'rate' => '20.00'),
			)
		);

		$validator = new En16931Validator();
		$violations = $validator->validate($xml);

		$this->assertEmpty(
			$violations,
			'A type-380 invoice with a legitimately negative GrandTotal must produce no violations: ' . implode('; ', $violations)
		);
	}

	/**
	 * When a type-380 invoice has a positive GrandTotal but a negative DuePayableAmount
	 * because TotalPrepaidAmount exceeds GrandTotal, BR-CO-16 must fire.
	 *
	 * This is the prepaid double-count scenario the guard was originally designed for, and
	 * it must keep being caught after the negative-GrandTotal fix.
	 *
	 * @return void
	 */
	public function testType380PrepaidExceedingGrandTotalTriggersBRCO16()
	{
		$xml = $this->buildCiiXml(
			'380',
			array(
				array('netPrice' => '83.33', 'lineTotal' => '83.33', 'vatRate' => '20.00'),
			),
			array(
				'lineTotal'  => '83.33',
				'taxBasis'   => '83.33',
				'taxTotal'   => '16.67',
				'grandTotal' => '100.00',
				'prepaid'    => '150.00',
				'duePayable' => '-50.00',
			),
			array(
				array('calculated' => '16.67', 'basis' => '83.33', 'rate' => '20.00'),
			)
		);

		$validator = new En16931Validator();
		$violations = $validator->validate($xml);

		$negativeDueViolations = array_filter($violations, function ($v) {
			return strpos($v, 'BR-CO-16') !== false && strpos($v, 'negative') !== false;
		});
		$this->assertNotEmpty(
			$negativeDueViolations,
			'A type-380 invoice where TotalPrepaidAmount exceeds GrandTotal must be flagged by BR-CO-16'
		);
	}
}
