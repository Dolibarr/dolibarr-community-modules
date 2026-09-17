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
 */

/**
 * \file    einvoicing/test/conformance/damage-line-exemption.php
 * \ingroup einvoicing
 * \brief   Builds the negative control of a conformance run: the same CII documents, with a VAT
 *          exemption reason added to the tax block of every invoice line.
 * \remarks This is the shape #974 shipped, which the platform refused on REJ_COH. Below EXTENDED the
 *          profile Schematron marks ram:ExemptionReason and ram:ExemptionReasonCode as not used in
 *          that context - as a <report>, never as a failed assertion, which is exactly why this
 *          control exists: a validator that reads only the failed assertions calls these documents
 *          valid. Documents that declare an EXTENDED profile are left alone, since there the two
 *          elements are legitimate, and BR-FXEXT-E-08 needs them.
 *
 *          Usage: php damage-line-exemption.php <output directory> <document> [<document> ...]
 */

if (PHP_SAPI !== 'cli') {
	echo "Error: this script must be run from the command line.\n";
	exit(1);
}

$args = array_slice($argv, 1);
if (count($args) < 2) {
	fwrite(STDERR, "Usage: php damage-line-exemption.php <output directory> <document> [<document> ...]\n");
	exit(2);
}

$outdir = rtrim(array_shift($args), '/');
if (!is_dir($outdir) && !mkdir($outdir, 0755, true)) {
	fwrite(STDERR, 'cannot create the output directory ' . $outdir . "\n");
	exit(2);
}

$ram = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
$built = 0;

foreach ($args as $file) {
	$doc = new DOMDocument();
	if (!$doc->load($file)) {
		fwrite(STDERR, 'cannot read ' . $file . "\n");
		exit(2);
	}

	$xpath = new DOMXPath($doc);
	$xpath->registerNamespace('ram', $ram);

	$urn = (string) $xpath->evaluate('string(//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID)');
	if (strpos($urn, 'extended') !== false) {
		fwrite(STDERR, 'the control cannot be built from ' . basename($file) . ': it declares ' . $urn . ", where the two elements belong\n");
		exit(2);
	}

	$damaged = 0;
	$lineTaxes = '//ram:IncludedSupplyChainTradeLineItem/ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax';
	foreach ($xpath->query($lineTaxes) as $tax) {
		// The CII D22B sequence of TradeTaxType puts ExemptionReason before CategoryCode and
		// ExemptionReasonCode after it. A document that does not respect it fails the XSD, and the
		// control has to fail on the profile stage, not on the one before.
		$category = $xpath->query('ram:CategoryCode', $tax)->item(0);
		if ($category === null) {
			continue;
		}
		$reason = $doc->createElementNS($ram, 'ram:ExemptionReason', 'Tax exempted - TVA en franchise');
		$tax->insertBefore($reason, $category);
		$code = $doc->createElementNS($ram, 'ram:ExemptionReasonCode', 'VATEX-FR-FRANCHISE');
		$tax->insertBefore($code, $category->nextSibling);
		$damaged++;
	}

	if (!$damaged) {
		fwrite(STDERR, 'the control cannot be built from ' . basename($file) . ": no invoice line carries a tax block\n");
		exit(2);
	}

	$doc->save($outdir . '/' . basename($file));
	$built++;
}

echo $built . " damaged documents built in " . $outdir . "\n";
