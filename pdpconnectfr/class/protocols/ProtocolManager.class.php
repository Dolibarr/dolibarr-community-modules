<?php
/* Copyright (C) 2025       Laurent Destailleur         <eldy@users.sourceforge.net>
 * Copyright (C) 2025       Mohamed DAOUD               <mdaoud@dolicloud.com>
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
 * \file    pdpconnectfr/class/protocols/ProtocolManager.class.php
 * \ingroup pdpconnectfr
 * \brief   Manage multiple protocols for PPD exchange (Factur-X, CII, UBL ...)
 */
class ProtocolManager
{
	/**
	 * @var DoliDB db
	 */
	public $db;

	/**
	 * @var mixed protocolsList
	 */
	private $protocolsList;


	/**
	 * Initialize available protocols.
	 * @param DoliDB $db db
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$facturexIsOk = 1;	// TODO Check version of PHP To allow or not
		$ciiIsOk = 1;
		$ublIsOk = 0;

		$this->protocolsList = array(
			'FACTURX' => array(
				'protocol_name' => 'FACTURX',
				'description' => 'Factur-X is a French-German hybrid e-invoicing format combining a readable PDF invoice with embedded XML data for seamless automated processing.',
				'is_enabled' => $facturexIsOk
			),
			'CII' => array(
				'protocol_name' => 'CII',
				'description' => 'CII (Cross Industry Invoice) is an international XML-based standard developed by UN/CEFACT to enable structured electronic invoicing and data exchange between businesses.',
				'is_enabled' => $ciiIsOk
			),
			'UBL' => array(
				'protocol_name' => 'UBL',
				'description' => 'UBL (Universal Business Language) is an open XML standard designed to facilitate electronic business documents exchange, including invoices, purchase orders, and more.',
				'is_enabled' => $ublIsOk
			)
		);
	}

	/**
	 * Retrieve the list of supported e-invoicing protocols.
	 *
	 * @return array Returns an associative array of available protocols with their names, descriptions, and status.
	 */
	public function getProtocolsList()
	{
		return $this->protocolsList;
	}

	/**
	 * Get protocol instance by name.
	 *
	 * @param string 	$name 			Name of the protocol to retrieve ('FACTURX', 'CII', 'UBL').
	 * @return AbstractProtocol|null
	 */
	public function getProtocol($name)
	{
		// Check if protocol exists and is enabled in protocolsList
		if (!isset($this->protocolsList[$name]) || !$this->protocolsList[$name]['is_enabled']) {
			return null;
		}

		$codename = strtoupper(str_replace('-', '', $name));

		// Initialize protocol based on name
		switch ($codename) {
			case 'FACTURX':
				dol_include_once('/pdpconnectfr/class/protocols/FacturXProtocol.class.php');

				$protocol = new FacturXProtocol($this->db);
				break;
			case 'CII':
				dol_include_once('/pdpconnectfr/class/protocols/CIIProtocol.class.php');
				$protocol = new CIIProtocol($this->db);
				break;
			case 'UBL':
				//$protocol = new UBLProtocol($this->db);
				break;
			default:
				return null;
		}

		return $protocol;
	}

	/**
	 * Detect the e-invoicing protocol used in the given content.
	 *
	 * This function analyzes the provided string to identify
	 * which e-invoicing protocol it conforms to (e.g., FACTURX, CII, UBL).
	 *
	 * @param 	string 		$content 	XML content of the invoice.
	 * @return 	string|null 			Returns the name of the detected protocol or null if unknown.
	 */
	public function detectProtocolFromContent($content)
	{
		// Simple detection based on XML namespaces or root elements
		if (preg_match('/^%PDF\-/', $content) === 1) { // We can also check for embedded XML in PDF for Factur-X
			return 'FACTURX';
		} elseif (strpos($content, 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice') !== false) {
			return 'CII';
		} elseif (strpos($content, 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2') !== false) {
			return 'UBL';
		}
		return null; // Unknown protocol
	}
}
