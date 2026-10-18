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
 * \file    einvoicing/test/conformance/damage-vat-rate.php
 * \ingroup einvoicing
 * \brief   Builds the negative control of a conformance run: the same CII documents, with the rate
 *          of every taxing VAT breakdown set to 0.00.
 * \remarks This is the shape #709 shipped - BT-119 at 0.00 against a non-zero BT-117 - which the
 *          rules must refuse on BR-CO-17. Breakdowns that tax nothing are left alone: a 0.00 rate
 *          on a 0.00 tax amount is what an exempt document legitimately carries. A document made
 *          only of those is skipped, the defect being unreachable in it; the run still fails when
 *          no control at all could be built, a green validation over nothing proving nothing.
 *
 *          Usage: php damage-vat-rate.php <output directory> <document> [<document> ...]
 */

if (PHP_SAPI !== 'cli') {
	echo "Error: this script must be run from the command line.\n";
	exit(1);
}

$args = array_slice($argv, 1);
if (count($args) < 2) {
	fwrite(STDERR, "Usage: php damage-vat-rate.php <output directory> <document> [<document> ...]\n");
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

	$damaged = 0;
	foreach ($xpath->query('//ram:ApplicableHeaderTradeSettlement/ram:ApplicableTradeTax') as $tax) {
		$taxes = false;
		foreach ($xpath->query('ram:CalculatedAmount', $tax) as $amount) {
			if (abs((float) $amount->textContent) >= 0.005) {
				$taxes = true;
			}
		}
		if (!$taxes) {
			continue;
		}
		foreach ($xpath->query('ram:RateApplicablePercent', $tax) as $rate) {
			$rate->nodeValue = '0.00';
			$damaged++;
		}
	}

	if (!$damaged) {
		// A document whose every breakdown taxes nothing cannot carry this defect: BR-CO-17 compares
		// a rate to a tax amount, and both are already 0.00 there legitimately. It is skipped, not a
		// failure - an entirely exempt specimen is a case the run is meant to cover.
		fwrite(STDERR, 'skipped ' . basename($file) . ": no VAT breakdown that taxes, the defect cannot exist in it\n");
		continue;
	}

	$doc->save($outdir . '/' . basename($file));
	$built++;
}

if (!$built) {
	// Nothing to validate means nothing proven: the caller compares the documents the rules refused
	// to the ones built here, and both being zero would pass in silence.
	fwrite(STDERR, "no control could be built from the " . count($args) . " documents given\n");
	exit(2);
}

echo $built . " damaged documents built in " . $outdir . "\n";
