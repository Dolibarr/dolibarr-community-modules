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
 * \file        htdocs/einvoicing/class/utils/En16931Validator.class.php
 * \ingroup     einvoicing
 * \brief       Lightweight EN 16931 business-rules validator for generated CII invoices.
 *
 * Checks a focused subset of the EN 16931 business rules (BR, BR-CO) plus a few
 * CTC-FR ones (BR-FR) directly on the generated CrossIndustryInvoice XML, before
 * the file is stored/sent. This is NOT a replacement for the official Schematron
 * (still applied by the Approved Platform): it is a fast local safety net that
 * catches arithmetic inconsistencies (the most common generation bugs) with a
 * clear message, without a network call and for any PDP provider.
 *
 * Messages are technical by design (they quote the official rule id and the
 * amounts involved), like a validator report.
 */
class En16931Validator
{
	/**
	 * Rounding tolerance for amount comparisons, in invoice currency units.
	 * EN 16931 amounts are rounded to 2 decimals; one cent absorbs the allowed rounding.
	 *
	 * @var float
	 */
	const TOLERANCE = 0.011;

	/**
	 * VAT category codes that must carry a zero rate (UNCL 5305 subset used by EN 16931).
	 *
	 * @var string[]
	 */
	public static $zeroRateCategories = array('Z', 'E', 'AE', 'K', 'G', 'O');

	/**
	 * Validate a CII invoice XML string against the implemented rules subset.
	 *
	 * @param	string		$xml	CrossIndustryInvoice XML content
	 * @return	string[]			List of violation messages (empty array when compliant)
	 */
	public function validate($xml)
	{
		$violations = array();

		$prevUseErrors = libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$loaded = $dom->loadXML($xml);
		libxml_clear_errors();
		libxml_use_internal_errors($prevUseErrors);
		if (!$loaded) {
			return array('XML: generated document is not well-formed');
		}

		$xp = new DOMXPath($dom);
		$xp->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
		$xp->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
		$xp->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');

		$num = function ($query, $context = null) use ($xp) {
			$nodes = ($context !== null) ? $xp->query($query, $context) : $xp->query($query);
			return ($nodes !== false && $nodes->length > 0) ? (float) trim($nodes->item(0)->textContent) : null;
		};
		$str = function ($query, $context = null) use ($xp) {
			$nodes = ($context !== null) ? $xp->query($query, $context) : $xp->query($query);
			return ($nodes !== false && $nodes->length > 0) ? trim($nodes->item(0)->textContent) : null;
		};
		$eq = function ($a, $b) {
			return abs($a - $b) <= self::TOLERANCE;
		};
		$fmt = function ($v) {
			return number_format((float) $v, 2, '.', '');
		};

		$typeCode = $str('//rsm:ExchangedDocument/ram:TypeCode');

		// ---------------------------------------------------------------
		// Lines: BR-16, BR-27 and per-line VAT category/rate coherence
		// ---------------------------------------------------------------
		$lines = $xp->query('//ram:IncludedSupplyChainTradeLineItem');
		if ($lines === false || $lines->length === 0) {
			$violations[] = 'BR-16: an invoice shall have at least one invoice line';
			$lines = array();	// Allow the header checks below to still run
		}

		$sumLineTotals = 0.0;
		$i = 0;
		foreach ($lines as $line) {
			$i++;
			$netPrice = $num('.//ram:NetPriceProductTradePrice/ram:ChargeAmount', $line);
			if ($netPrice !== null && $netPrice < 0) {
				$violations[] = 'BR-27: line '.$i.' has a negative net price ('.$fmt($netPrice).')';
			}
			$lineTotal = $num('.//ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $line);
			if ($lineTotal !== null) {
				$sumLineTotals += $lineTotal;
			}
			$cat = $str('.//ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:CategoryCode', $line);
			$rate = $num('.//ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent', $line);
			if ($cat === 'S' && $rate !== null && $rate <= 0) {
				$violations[] = 'BR-S-05: line '.$i.' uses VAT category S with a non-positive rate ('.$fmt($rate).')';
			}
			if ($cat !== null && in_array($cat, self::$zeroRateCategories, true) && $rate !== null && abs($rate) > 0) {
				$violations[] = 'BR-'.$cat.'-05: line '.$i.' uses VAT category '.$cat.' with a non-zero rate ('.$fmt($rate).')';
			}
		}

		// ---------------------------------------------------------------
		// Document level allowances (BG-20) and charges (BG-21)
		// ---------------------------------------------------------------
		$sumAllowances = 0.0;
		$sumCharges = 0.0;
		$acNodes = $xp->query('//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeAllowanceCharge');
		if ($acNodes !== false) {
			foreach ($acNodes as $ac) {
				$indicator = $str('.//ram:ChargeIndicator/udt:Indicator', $ac);
				$amount = $num('.//ram:ActualAmount', $ac);
				if ($amount === null) {
					continue;
				}
				if ($indicator === 'true') {
					$sumCharges += $amount;
				} else {
					$sumAllowances += $amount;
				}
			}
		}

		// ---------------------------------------------------------------
		// Header monetary summation (BG-22)
		// ---------------------------------------------------------------
		$ms = '//ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation';
		$msNode = $xp->query($ms);
		if ($msNode === false || $msNode->length === 0) {
			$violations[] = 'BG-22: the document monetary summation block is missing';
			return $violations;
		}

		$lineTotalHdr = $num($ms.'/ram:LineTotalAmount');
		$allowanceTotal = $num($ms.'/ram:AllowanceTotalAmount');
		$chargeTotal = $num($ms.'/ram:ChargeTotalAmount');
		$taxBasisTotal = $num($ms.'/ram:TaxBasisTotalAmount');
		$grandTotal = $num($ms.'/ram:GrandTotalAmount');
		$prepaidTotal = $num($ms.'/ram:TotalPrepaidAmount');
		$duePayable = $num($ms.'/ram:DuePayableAmount');
		$roundingAmount = $num($ms.'/ram:RoundingAmount');

		// TaxTotalAmount may appear once per currency; keep the one in the invoice currency.
		$taxTotal = null;
		$invoiceCurrency = $str('//ram:ApplicableHeaderTradeSettlement/ram:InvoiceCurrencyCode');
		$taxTotalNodes = $xp->query($ms.'/ram:TaxTotalAmount');
		if ($taxTotalNodes !== false && $taxTotalNodes->length > 0) {
			foreach ($taxTotalNodes as $node) {
				/** @var DOMElement $node */
				$cur = $node->getAttribute('currencyID');
				if ($taxTotal === null || $cur === '' || $cur === $invoiceCurrency) {
					$taxTotal = (float) trim($node->textContent);
					if ($cur === $invoiceCurrency) {
						break;
					}
				}
			}
		}

		// BR-CO-10: sum of line net amounts = LineTotalAmount
		if ($lineTotalHdr !== null && !$eq($sumLineTotals, $lineTotalHdr)) {
			$violations[] = 'BR-CO-10: sum of line net amounts ('.$fmt($sumLineTotals).') does not equal LineTotalAmount ('.$fmt($lineTotalHdr).')';
		}
		// BR-CO-11: sum of document allowances = AllowanceTotalAmount
		if ($allowanceTotal !== null && !$eq($sumAllowances, $allowanceTotal)) {
			$violations[] = 'BR-CO-11: sum of document level allowances ('.$fmt($sumAllowances).') does not equal AllowanceTotalAmount ('.$fmt($allowanceTotal).')';
		}
		// BR-CO-12: sum of document charges = ChargeTotalAmount
		if ($chargeTotal !== null && !$eq($sumCharges, $chargeTotal)) {
			$violations[] = 'BR-CO-12: sum of document level charges ('.$fmt($sumCharges).') does not equal ChargeTotalAmount ('.$fmt($chargeTotal).')';
		}
		// BR-CO-13: TaxBasisTotalAmount = LineTotal - AllowanceTotal + ChargeTotal
		if ($taxBasisTotal !== null && $lineTotalHdr !== null) {
			$expected = $lineTotalHdr - (float) $allowanceTotal + (float) $chargeTotal;
			if (!$eq($expected, $taxBasisTotal)) {
				$violations[] = 'BR-CO-13: TaxBasisTotalAmount ('.$fmt($taxBasisTotal).') does not equal LineTotal - Allowances + Charges ('.$fmt($expected).')';
			}
		}

		// ---------------------------------------------------------------
		// VAT breakdown (BG-23): BR-CO-14 and BR-CO-17
		// ---------------------------------------------------------------
		$sumVat = 0.0;
		$vatNodes = $xp->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax');
		if ($vatNodes !== false) {
			$j = 0;
			foreach ($vatNodes as $vat) {
				$j++;
				$calculated = $num('.//ram:CalculatedAmount', $vat);
				$basis = $num('.//ram:BasisAmount', $vat);
				$rate = $num('.//ram:RateApplicablePercent', $vat);
				$cat = $str('.//ram:CategoryCode', $vat);
				if ($calculated !== null) {
					$sumVat += $calculated;
				}
				// BR-CO-17: category tax amount = basis x rate
				if ($calculated !== null && $basis !== null && $rate !== null) {
					$expected = round($basis * $rate / 100, 2);
					if (!$eq($expected, $calculated)) {
						$violations[] = 'BR-CO-17: VAT breakdown '.$j.' ('.$cat.' '.$fmt($rate).'%): CalculatedAmount ('.$fmt($calculated).') does not equal BasisAmount x rate ('.$fmt($expected).')';
					}
				}
				if ($cat === 'S' && $rate !== null && $rate <= 0) {
					$violations[] = 'BR-S-05: VAT breakdown '.$j.' uses category S with a non-positive rate ('.$fmt($rate).')';
				}
				if ($cat !== null && in_array($cat, self::$zeroRateCategories, true) && $rate !== null && abs($rate) > 0) {
					$violations[] = 'BR-'.$cat.'-05: VAT breakdown '.$j.' uses category '.$cat.' with a non-zero rate ('.$fmt($rate).')';
				}
			}
		}
		// BR-CO-14: TaxTotalAmount = sum of VAT category tax amounts
		if ($taxTotal !== null && !$eq($sumVat, $taxTotal)) {
			$violations[] = 'BR-CO-14: sum of VAT category amounts ('.$fmt($sumVat).') does not equal TaxTotalAmount ('.$fmt($taxTotal).')';
		}

		// BR-CO-15: GrandTotalAmount = TaxBasisTotal + TaxTotal (+ RoundingAmount when present)
		if ($grandTotal !== null && $taxBasisTotal !== null && $taxTotal !== null) {
			$expected = $taxBasisTotal + $taxTotal + (float) $roundingAmount;
			if (!$eq($expected, $grandTotal)) {
				$violations[] = 'BR-CO-15: GrandTotalAmount ('.$fmt($grandTotal).') does not equal TaxBasisTotal + TaxTotal ('.$fmt($expected).')';
			}
		}

		// BR-CO-16: DuePayableAmount = GrandTotal - TotalPrepaidAmount
		if ($duePayable !== null && $grandTotal !== null) {
			$expected = $grandTotal - (float) $prepaidTotal;
			if (!$eq($expected, $duePayable)) {
				$violations[] = 'BR-CO-16: DuePayableAmount ('.$fmt($duePayable).') does not equal GrandTotal - TotalPrepaid ('.$fmt($expected).')';
			}
			// A commercial invoice must not claim a negative amount due: it means the prepaid
			// amount recorded exceeds the invoice total (this is how a prepaid double-count bug
			// materializes). Credit notes (381) are emitted with positive amounts and are skipped.
			if ($typeCode !== '381' && $duePayable < -self::TOLERANCE) {
				$violations[] = 'BR-CO-16: DuePayableAmount is negative ('.$fmt($duePayable).'); TotalPrepaidAmount ('.$fmt($prepaidTotal).') exceeds GrandTotal ('.$fmt($grandTotal).')';
			}
		}

		// ---------------------------------------------------------------
		// CTC-FR: BR-FR-10 seller SIREN format (scheme 0002 = 9 digits)
		// ---------------------------------------------------------------
		$sellerLegalIds = $xp->query('//ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:ID');
		if ($sellerLegalIds !== false) {
			foreach ($sellerLegalIds as $node) {
				/** @var DOMElement $node */
				if ($node->getAttribute('schemeID') === '0002' && !preg_match('/^\d{9}$/', trim($node->textContent))) {
					$violations[] = 'BR-FR-10: seller legal registration identifier (scheme 0002) must be a 9 digit SIREN, found "'.trim($node->textContent).'"';
				}
			}
		}

		return $violations;
	}
}
