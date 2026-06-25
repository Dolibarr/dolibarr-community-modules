<?php
/* Copyright (C) 2026       Pierre Grasswill
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    einvoicing/test/test_shiptotradeparty.php
 * \ingroup einvoicing
 * \brief   Standalone unit test for ShipToTradePartyBuilder (no Dolibarr bootstrap required).
 *
 * Usage: php einvoicing/test/test_shiptotradeparty.php
 */

require_once __DIR__ . '/../class/protocols/ShipToTradePartyBuilder.class.php';

$failures = 0;

/**
 * @param bool   $cond Condition that must hold
 * @param string $msg  Description printed on success/failure
 * @return void
 */
function check($cond, $msg)
{
	global $failures;
	if ($cond) {
		echo "  ok   - $msg\n";
	} else {
		echo "  FAIL - $msg\n";
		$failures++;
	}
}

/**
 * Build the node and return its serialized XML (or '' when null).
 *
 * @param array $bill Billing address
 * @param array $ship Shipping address
 * @return string
 */
function buildXml($bill, $ship)
{
	$doc = new DOMDocument('1.0', 'UTF-8');
	$doc->formatOutput = true;
	$node = ShipToTradePartyBuilder::build($doc, $bill, $ship);
	if ($node === null) {
		return '';
	}
	$doc->appendChild($node);
	return $doc->saveXML($node);
}

// --- Case A: ship != bill -> ShipToTradeParty present, well-formed, elements in CII order -------
echo "Case A - ship != bill (must emit BG-15):\n";
$bill = array('address' => '1 rue de Paris', 'zip' => '75001', 'town' => 'Paris', 'country' => 'FR');
$shipA = array('name' => 'Entrepot Sud', 'address' => '42 avenue du Port', 'zip' => '33000', 'town' => 'Bordeaux', 'country' => 'FR');
$doc = new DOMDocument('1.0', 'UTF-8');
$node = ShipToTradePartyBuilder::build($doc, $bill, $shipA);
check($node !== null, 'ShipToTradeParty node is emitted');
if ($node !== null) {
	$doc->appendChild($node);
	$xml = $doc->saveXML();

	check($node->getElementsByTagName('ram:Name')->item(0) && $node->getElementsByTagName('ram:Name')->item(0)->textContent === 'Entrepot Sud', 'Name = Entrepot Sud');
	$addr = $node->getElementsByTagName('ram:PostalTradeAddress')->item(0);
	check($addr !== null, 'PostalTradeAddress present');

	// Order of children inside PostalTradeAddress must be: PostcodeCode, LineOne, CityName, CountryID.
	$order = array();
	foreach ($addr->childNodes as $child) {
		if ($child->nodeType === XML_ELEMENT_NODE) {
			$order[] = $child->nodeName;
		}
	}
	check($order === array('ram:PostcodeCode', 'ram:LineOne', 'ram:CityName', 'ram:CountryID'), 'PostalTradeAddress order = [PostcodeCode, LineOne, CityName, CountryID] (got: ' . implode(',', $order) . ')');

	check($addr->getElementsByTagName('ram:PostcodeCode')->item(0)->textContent === '33000', 'PostcodeCode = 33000');
	check($addr->getElementsByTagName('ram:LineOne')->item(0)->textContent === '42 avenue du Port', 'LineOne = 42 avenue du Port');
	check($addr->getElementsByTagName('ram:CityName')->item(0)->textContent === 'Bordeaux', 'CityName = Bordeaux');
	check($addr->getElementsByTagName('ram:CountryID')->item(0)->textContent === 'FR', 'CountryID = FR (BR-57 satisfied)');
}

// --- Case B: ship == bill -> no node (avoid redundant BG-15) -------------------------------------
echo "Case B - ship == bill (must NOT emit):\n";
$shipB = array('name' => 'Same Co', 'address' => '1 rue de Paris', 'zip' => '75001', 'town' => 'Paris', 'country' => 'FR');
check(buildXml($bill, $shipB) === '', 'ShipToTradeParty absent when addresses are identical');

// Same address with cosmetic differences (case, extra spaces) must still be treated as equal.
$shipBnorm = array('name' => 'Same Co', 'address' => '1   RUE   de paris', 'zip' => '75001', 'town' => 'PARIS', 'country' => 'fr');
check(buildXml($bill, $shipBnorm) === '', 'Normalized comparison ignores case/whitespace (no emit)');

// --- Case C: SHIPPING without country_code -> no node (BR-57 guard) -------------------------------
echo "Case C - shipping without country (BR-57 guard, must NOT emit):\n";
$shipC = array('name' => 'No Country', 'address' => '42 avenue du Port', 'zip' => '33000', 'town' => 'Bordeaux', 'country' => '');
check(buildXml($bill, $shipC) === '', 'ShipToTradeParty absent when country code is empty');

// --- Case D: no SHIPPING contact -> no node (non-regression) -------------------------------------
// At the builder level, "no SHIPPING contact" is modelled as an empty ship array.
echo "Case D - no shipping contact (non-regression, must NOT emit):\n";
check(buildXml($bill, array()) === '', 'ShipToTradeParty absent when no shipping data provided');

echo "\n";
if ($failures === 0) {
	echo "ALL TESTS PASSED\n";
	exit(0);
}
echo "$failures TEST(S) FAILED\n";
exit(1);
