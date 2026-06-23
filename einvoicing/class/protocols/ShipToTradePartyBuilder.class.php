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
 * \file    einvoicing/class/protocols/ShipToTradePartyBuilder.class.php
 * \ingroup einvoicing
 * \brief   Pure (Dolibarr-free) builder for the CII ShipToTradeParty node (BG-15)
 */

/**
 * Builds the CII <ram:ShipToTradeParty> node (deliver-to address, BG-15).
 *
 * This class has NO Dolibarr dependency on purpose: it works only with plain arrays and the
 * native DOMDocument, so it can be unit-tested without bootstrapping a full Dolibarr instance.
 * The Dolibarr-specific extraction of the SHIPPING contact lives in buildinvoicelines.inc.php,
 * which feeds the normalized bill/ship arrays to build() below.
 */
class ShipToTradePartyBuilder
{
	/**
	 * Build a <ram:ShipToTradeParty> element when the shipping address actually differs from the
	 * billing (buyer) address and carries a resolvable country code.
	 *
	 * Returns null when no distinct ship-to party must be emitted, in which case the caller is
	 * expected to fall back to the upstream behaviour (ship-to = buyer), keeping the
	 * ApplicableHeaderTradeDelivery/ShipToTradeParty node always present (intracommunity requirement).
	 *
	 * @param DOMDocument $doc   Document to create nodes in
	 * @param array       $bill  Billing address: keys address, zip, town, country (alpha-2)
	 * @param array       $ship  Shipping address: keys name, address, zip, town, country (alpha-2)
	 * @return DOMElement|null   The ShipToTradeParty node, or null to fall back to the buyer party
	 */
	public static function build(DOMDocument $doc, array $bill, array $ship)
	{
		// BR-57: a postal address present in the XML must carry a non-empty CountryID. Without a
		// resolvable country we cannot emit a valid BG-15, so we skip the contact ship-to entirely.
		if (empty($ship['country'])) {
			return null;
		}

		// Normalized comparison (case / whitespace) to avoid false positives that would emit a
		// redundant BG-15 identical to the buyer address.
		$norm = function ($s) {
			$s = preg_replace('/\s+/', ' ', trim((string) ($s ?? '')));
			return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
		};
		$billKey = array($norm($bill['address'] ?? ''), $norm($bill['zip'] ?? ''), $norm($bill['town'] ?? ''), $norm($bill['country'] ?? ''));
		$shipKey = array($norm($ship['address'] ?? ''), $norm($ship['zip'] ?? ''), $norm($ship['town'] ?? ''), $norm($ship['country'] ?? ''));
		if ($billKey === $shipKey) {
			return null;
		}

		$node = $doc->createElement('ram:ShipToTradeParty');

		// BT-70 Deliver-to party name (optional).
		if (!empty($ship['name'])) {
			$node->appendChild($doc->createElement('ram:Name', htmlspecialchars($ship['name'])));
		}

		// BG-15 Deliver-to address.
		$addr = $doc->createElement('ram:PostalTradeAddress');
		$node->appendChild($addr);

		// CII XSD order for PostalTradeAddress: PostcodeCode BEFORE LineOne (counter-intuitive).
		if (!empty($ship['zip'])) {
			$addr->appendChild($doc->createElement('ram:PostcodeCode', $ship['zip']));
		}
		if (!empty($ship['address'])) {
			$addr->appendChild($doc->createElement('ram:LineOne', htmlspecialchars($ship['address'])));
		}
		if (!empty($ship['town'])) {
			$addr->appendChild($doc->createElement('ram:CityName', htmlspecialchars($ship['town'])));
		}
		// CountryID is mandatory whenever the address block exists (BR-57). Guaranteed non-empty here.
		$addr->appendChild($doc->createElement('ram:CountryID', $ship['country']));

		return $node;
	}
}
