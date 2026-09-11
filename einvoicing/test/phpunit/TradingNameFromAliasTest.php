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
 *      \file       test/phpunit/TradingNameFromAliasTest.php
 *      \ingroup    test
 *      \brief      The trading name of a party is its commercial name, or nothing at all.
 *      \remarks    BT-45 carried the legal name of the customer, repeating BT-44, and the commercial
 *                  name recorded on the third party card never reached the document (#847). The
 *                  document is built here from a real invoice, because the value comes from the
 *                  assembly of the invoice data, not from the writer that puts it in the XML.
 */


// This script must only be run from the command line.
if (PHP_SAPI !== 'cli') {
	echo "Error: this script must be run from the command line (CLI), not through a web server.\n";
	exit(1);
}

global $conf, $user, $langs, $db;

// Load Dolibarr environment. Same resolution as the other test files of the module.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}
require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';


/**
 * Class TradingNameFromAliasTest
 *
 * Invoices three customers - one with a commercial name, one without, one whose commercial name
 * merely repeats its legal name - and reads BT-28 and BT-45 back from the generated document.
 */
class TradingNameFromAliasTest extends CommonClassTest
{
	const RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

	/** @var string	Legal name of the customer, the one BT-44 states */
	const BUYER_NAME = 'EINVOICING TEST ALIAS BUYER';
	/** @var string	Commercial name of the customer, the one BT-45 is for */
	const BUYER_ALIAS = 'Alias & Commerce';

	/**
	 * Build one invoice for a customer of the given shape and generate its document.
	 *
	 * @param	string	$alias	Commercial name of the customer, empty for a customer without one
	 * @return	string			The generated document
	 */
	private function documentForAlias($alias)
	{
		global $conf, $db, $langs, $mysoc;

		$user = new User($db);
		$this->assertGreaterThan(0, $user->fetch(1), 'the instance has a user to act as');

		// The seller is the company of the instance, whose identifiers a demo database does not
		// necessarily fill: the generation stops before the first name is written without them.
		// $mysoc is a global object, so pinning it changes nothing in the database and is undone below.
		$savSeller = array(
			'idprof1' => $mysoc->idprof1,
			'idprof2' => $mysoc->idprof2,
			'tva_intra' => $mysoc->tva_intra,
			'country_id' => $mysoc->country_id,
			'country_code' => $mysoc->country_code,
			'name_alias' => $mysoc->name_alias,
		);
		$mysoc->idprof1 = '000000001';
		$mysoc->idprof2 = '00000000100010';
		$mysoc->tva_intra = 'FR12000000001';
		$mysoc->country_id = 1;
		$mysoc->country_code = 'FR';

		$savPdp = getDolGlobalString('EINVOICING_PDP');
		$conf->global->EINVOICING_PDP = 'SPECIMEN';

		try {
			$buyer = new Societe($db);
			$buyer->name = self::BUYER_NAME;
			$buyer->name_alias = $alias;
			$buyer->client = 1;
			// Some instances - the demo database among them - number their customers with a module
			// that refuses a third party without a code.
			$buyer->code_client = 'EINVAL' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
			$buyer->address = '2 rue du Test';
			$buyer->zip = '75000';
			$buyer->town = 'Paris';
			$buyer->country_id = 1;			// France
			$buyer->country_code = 'FR';
			$buyer->idprof1 = '000000002';
			$buyer->idprof2 = '00000000200010';
			$buyer->tva_intra = 'FR12000000002';
			$this->assertGreaterThan(0, $buyer->create($user), 'the customer is created: ' . $buyer->error);

			$invoice = new Facture($db);
			$invoice->socid = $buyer->id;
			$invoice->type = Facture::TYPE_STANDARD;
			$invoice->date = dol_now();
			$this->assertGreaterThan(0, $invoice->create($user), 'the invoice is created: ' . $invoice->error);

			$lineId = $invoice->addline('Alias line', 100.0, 1, 20.0);
			$this->assertGreaterThan(0, $lineId, 'the line of the invoice is added: ' . $invoice->error);

			$reloaded = new Facture($db);
			$this->assertGreaterThan(0, $reloaded->fetch($invoice->id), 'the invoice is read back');
			$reloaded->fetch_lines();
			$reloaded->fetch_thirdparty();

			$protocol = new CIIProtocol($db);
			$path = $protocol->generateXML($reloaded, $langs);
			$this->assertNotEmpty($path, 'the document is generated: ' . $protocol->error . ' ' . implode(', ', (array) $protocol->errors));
			$this->assertFileExists((string) $path, 'the generated document is written');

			return (string) file_get_contents((string) $path);
		} finally {
			$conf->global->EINVOICING_PDP = $savPdp;
			foreach ($savSeller as $property => $value) {
				$mysoc->$property = $value;
			}
		}
	}

	/**
	 * Read one term of a party out of the document.
	 *
	 * @param	string	$xml	The generated document
	 * @param	string	$party	ram:SellerTradeParty or ram:BuyerTradeParty
	 * @param	string	$term	Element to read, relative to the party
	 * @return	?string			Its text, null when the element is absent
	 */
	private function partyTerm($xml, $party, $term)
	{
		$doc = new DOMDocument();
		$this->assertTrue($doc->loadXML($xml), 'the generated document is well formed XML');
		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('ram', self::RAM);

		$found = $xpath->query('//ram:' . $party . '/' . $term);

		return ($found !== false && $found->length > 0) ? $found->item(0)->textContent : null;
	}

	/**
	 * The commercial name recorded on the customer is the one the document states as BT-45.
	 *
	 * On the code of #847 this reads the legal name of the customer instead.
	 *
	 * @return void
	 */
	public function testTheCommercialNameOfTheCustomerIsItsTradingName()
	{
		$xml = $this->documentForAlias(self::BUYER_ALIAS);

		$this->assertSame(
			self::BUYER_NAME,
			$this->partyTerm($xml, 'BuyerTradeParty', 'ram:Name'),
			'BT-44 states the legal name of the customer'
		);
		$this->assertSame(
			self::BUYER_ALIAS,
			$this->partyTerm($xml, 'BuyerTradeParty', 'ram:SpecifiedLegalOrganization/ram:TradingBusinessName'),
			'BT-45 states the commercial name of the customer'
		);
	}

	/**
	 * A customer without a commercial name has no BT-45, and an absent term is an absent element:
	 * an empty one is refused by PEPPOL-EN16931-R008 (#695).
	 *
	 * @return void
	 */
	public function testACustomerWithoutACommercialNameHasNoTradingName()
	{
		$xml = $this->documentForAlias('');

		$this->assertNull(
			$this->partyTerm($xml, 'BuyerTradeParty', 'ram:SpecifiedLegalOrganization/ram:TradingBusinessName'),
			'BT-45 is left out when the customer has no commercial name'
		);
		$this->assertStringNotContainsString(
			'<ram:TradingBusinessName/>',
			$xml,
			'no empty element is written'
		);
	}

	/**
	 * A commercial name that merely repeats the legal name says nothing, and the norm asks for the
	 * term only when it differs from the name of the party.
	 *
	 * @return void
	 */
	public function testACommercialNameThatRepeatsTheLegalNameIsNotStated()
	{
		$xml = $this->documentForAlias(self::BUYER_NAME);

		$this->assertNull(
			$this->partyTerm($xml, 'BuyerTradeParty', 'ram:SpecifiedLegalOrganization/ram:TradingBusinessName'),
			'BT-45 is left out when it would repeat BT-44'
		);
	}

	/**
	 * The company setup of the core has no commercial name, so the seller has no BT-28 to declare.
	 *
	 * @return void
	 */
	public function testTheSellerStatesNoTradingNameItDoesNotHave()
	{
		$xml = $this->documentForAlias(self::BUYER_ALIAS);

		// Whatever the instance is called, its name is stated: without that the absence below would
		// only prove that the seller party was not built at all.
		$this->assertNotEmpty(
			(string) $this->partyTerm($xml, 'SellerTradeParty', 'ram:Name'),
			'BT-27 states the name of the company of the instance'
		);
		$this->assertNull(
			$this->partyTerm($xml, 'SellerTradeParty', 'ram:SpecifiedLegalOrganization/ram:TradingBusinessName'),
			'BT-28 is left out: the core has no commercial name for the company of the instance'
		);
	}
}
