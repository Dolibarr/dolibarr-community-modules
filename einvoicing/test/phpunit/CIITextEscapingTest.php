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
 *      \file       test/phpunit/CIITextEscapingTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the XML escaping of the values written in a CII document.
 *                  DOMDocument::createElement() PARSES its second argument: a value holding an
 *                  ampersand ("ETHIQUE & TACT", an ordinary company name) raises an "unterminated
 *                  entity reference" warning and produces an EMPTY element, so the business term is
 *                  silently lost and the document is refused by PEPPOL-EN16931-R008, which forbids
 *                  empty elements (issue #695). Every value must therefore be escaped on its way in.
 *                  The same question is then asked of the whole generation path, on an invoice of
 *                  the base carrying the shapes a description really holds - a control character
 *                  pasted from a PDF among them.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

// This script must only be run from the command line.
if (PHP_SAPI !== 'cli') {
	echo "Error: this script must be run from the command line (CLI), not through a web server.\n";
	exit(1);
}

global $conf, $user, $langs, $db;

// Same bootstrap as the other test files of this module, see CIIProfileShapeTest.php.
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
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class CIITextEscapingTest extends CommonClassTest
{
	/** @var string Appended to every text value the document carries, so each one is a test case */
	const AMP = ' & Co';

	/**
	 * Header data whose every text term holds an ampersand. Amounts are consistent, and the
	 * document carries the optional groups an escaping mistake could hide in: payment means and
	 * account (BT-82, BT-85), payment terms (BT-20), a document level allowance (BT-104), a VAT
	 * exemption reason (BT-120) and a preceding invoice reference (BT-25).
	 *
	 * @return array
	 */
	private function poisonedInvoiceData(): array
	{
		$date = new DateTime('2026-07-01');

		return [
			'documentno' => 'FA2607-0001',
			'documenttypecode' => '380',
			'documentdate' => $date,
			'invoiceCurrency' => 'EUR',
			'taxCurrency' => null,
			'documentname' => null,
			'documentlanguage' => 'fr',
			'effectiveSpecifiedPeriod' => 'NA',
			'documentDeliveryDate' => $date,
			'invoicingPeriodStart' => null,
			'invoicingPeriodEnd' => null,
			'businessProcessId' => 'B1',
			'isTestDocument' => false,
			'documentNotePublic' => 'Note publique' . self::AMP,
			'documentNotePMT' => '',
			'documentNotePMD' => '',
			'documentNoteAAB' => '',
			'documentNoteTXD' => '',
			'vatDueDateTypeCode' => '5',
			'documentNotes' => [],

			'sellername' => 'ETHIQUE' . self::AMP,
			'sellerids' => '892304189',
			'sellerlineone' => '12 rue des Houblons' . self::AMP,
			'sellerlinetwo' => '',
			'sellerlinethree' => '',
			'sellerpostcode' => '86000',
			'sellercity' => 'Poitiers' . self::AMP,
			'sellercountry' => 'FR',
			'sellersubdivision' => null,
			'sellercontactpersonname' => 'Service facturation' . self::AMP,
			'sellercontactdepartmentname' => null,
			'sellercontactphoneno' => '+33549000000',
			'sellercontactfaxno' => '',
			'sellercontactemailaddr' => 'facturation@example.org',
			'sellerCommunicationUriScheme' => '0225',
			'sellerCommunicationUri' => '89230418900020',
			'sellerGlobalIds' => [['schemeID' => '0225', 'value' => '89230418900020']],
			'sellerTaxRegistrations' => [],
			'sellervatnumber' => 'FR87892304189',
			'sellerLegalOrgId' => '892304189',
			'sellerLegalOrgScheme' => '0002',
			'sellerTradingName' => 'ETHIQUE' . self::AMP,

			'buyername' => 'Tricaland' . self::AMP,
			'buyerids' => '123456782',
			'buyerlineone' => '5 avenue de la Distribution' . self::AMP,
			'buyerlinetwo' => '',
			'buyerlinethree' => '',
			'buyerpostcode' => '75011',
			'buyercity' => 'Paris' . self::AMP,
			'buyercountry' => 'FR',
			'buyersubdivision' => null,
			'buyervatnumber' => 'FR32123456782',
			'buyerGlobalIds' => [['schemeID' => '0225', 'value' => '12345678200019']],
			'buyerLegalOrgId' => '123456782',
			'buyerLegalOrgScheme' => '0002',
			'buyerTradingName' => 'Tricaland' . self::AMP,
			'buyerReference' => 'SERVICE ACHATS' . self::AMP,
			'buyerCommunicationUriScheme' => '0225',
			'buyerCommunicationUri' => '12345678200019',
			'buyercontactpersonname' => 'Service achats' . self::AMP,
			'buyercontactemailaddr' => 'achats@example.org',
			'buyercontactphoneno' => '+33100000000',

			'grandTotalAmount' => 108.0,
			'duePayableAmount' => 108.0,
			'lineTotalAmount' => 100.0,
			'chargeTotalAmount' => 0.0,
			'allowanceTotalAmount' => 10.0,
			'taxBasisTotalAmount' => 90.0,
			'taxTotalAmount' => 18.0,
			'roundingAmount' => null,
			'totalPrepaidAmount' => 0.0,

			'paymentMeansCode' => 30,
			'paymentMeansText' => 'Virement bancaire' . self::AMP,
			'iban_id' => 1,
			'iban' => 'FR7630003036200002012345652',
			'bic' => 'SOGEFRPP',
			'accountName' => 'ETHIQUE' . self::AMP,
			'accountRef' => 'BQTEST',
			'accountLabel' => 'Compte courant',
			'paymentDueDate' => $date,
			'paymentTermsText' => 'Conditions de reglement' . self::AMP,
			'headerAllowancesCharges' => [],
			'invoiceRefDocs' => [['ref' => 'FA2606-0009' . self::AMP, 'date' => $date, 'type' => '380']],
			'orderReference' => 'CMD-2026' . self::AMP,
			'contractReference' => 'CONTRAT-2026' . self::AMP,
			'despatchAdviceRef' => null,
			'taxBreakdown' => [
				'20' => [
					'tva_tx' => 20.0,
					'vat_src_code' => '',
					'categoryVAT' => 'S',
					'ExemptionReasonCode' => '',
					'ExemptionReason' => 'Motif exoneration' . self::AMP,
					'totalHT' => 90.0,
					'totalTVA' => 18.0,
				],
			],
			'_chorus' => false,
			'_depositlines' => [],
			'_globalDiscounts' => [['value' => 10.0, 'reason' => 'Geste commercial R' . self::AMP, 'taxRate' => 20.0, 'categoryVAT' => 'S']],
			'_customerOrderReferenceList' => [],
			'_project' => null,
		];
	}

	/**
	 * One invoice line whose product identifier, name and description all hold an ampersand.
	 *
	 * @return array
	 */
	private function poisonedLinesData(): array
	{
		return [
			[
				'lineid' => 1,
				'prodsellerid' => 'REF-B' . self::AMP,
				'prodname' => 'Biere blonde 33cl' . self::AMP,
				'proddesc' => 'Houblon' . self::AMP,
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
			],
		];
	}

	/**
	 * Load a generated document, failing the test when it is not well-formed.
	 *
	 * @param	string	$xml	Generated XML
	 * @return	DOMDocument		Loaded document
	 */
	private function load(string $xml): DOMDocument
	{
		$doc = new DOMDocument();
		$this->assertTrue($doc->loadXML($xml), 'generated document is not well-formed XML');

		return $doc;
	}

	/**
	 * XPath engine over a generated document, with the prefixes the document itself declares.
	 *
	 * @param	DOMDocument	$doc	Loaded document
	 * @return	DOMXPath			Engine ready for the paths of CIIProtocol::getFieldPaths()
	 */
	private function xpathOf(DOMDocument $doc): DOMXPath
	{
		$xpath = new DOMXPath($doc);
		foreach (['rsm', 'ram', 'udt', 'qdt'] as $prefix) {
			$uri = $doc->documentElement->lookupNamespaceURI($prefix);
			if ($uri) {
				$xpath->registerNamespace($prefix, $uri);
			}
		}

		return $xpath;
	}

	/**
	 * Text carried by the first node a path matches.
	 *
	 * @param	DOMXPath	$xpath	Engine built by xpathOf()
	 * @param	string		$path	Absolute path, e.g. '/rsm:CrossIndustryInvoice/...'
	 * @return	string|null			Text content, or null when the path matches nothing
	 */
	private function textAt(DOMXPath $xpath, string $path)
	{
		$nodes = $xpath->query($path);

		return ($nodes && $nodes->length) ? $nodes->item(0)->textContent : null;
	}

	/**
	 * An ampersand in a value must reach the document instead of emptying the element that carries
	 * it. This is issue #695, seen on the seller trading name of a company named "ETHIQUE & TACT".
	 *
	 * @return void
	 */
	public function testAmpersandSurvivesInEveryTextTerm()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$doc = $this->load($protocol->buildXML($this->poisonedInvoiceData(), $this->poisonedLinesData(), 'EN16931'));
		$xpath = $this->xpathOf($doc);

		$root = '/rsm:CrossIndustryInvoice';
		$agreement = $root . '/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement';
		$settlement = $root . '/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement';
		$line = $root . '/rsm:SupplyChainTradeTransaction/ram:IncludedSupplyChainTradeLineItem';

		$expected = [
			// BT-27, and BT-28 right below it: the pair of issue #695, where the first one was
			// written escaped and the second one was not.
			$agreement . '/ram:SellerTradeParty/ram:Name' => 'ETHIQUE' . self::AMP,
			$agreement . '/ram:SellerTradeParty/ram:SpecifiedLegalOrganization/ram:TradingBusinessName' => 'ETHIQUE' . self::AMP,
			$agreement . '/ram:BuyerTradeParty/ram:Name' => 'Tricaland' . self::AMP,				// BT-44
			$agreement . '/ram:BuyerTradeParty/ram:SpecifiedLegalOrganization/ram:TradingBusinessName' => 'Tricaland' . self::AMP,	// BT-45
			$agreement . '/ram:BuyerReference' => 'SERVICE ACHATS' . self::AMP,					// BT-10
			$agreement . '/ram:BuyerOrderReferencedDocument/ram:IssuerAssignedID' => 'CMD-2026' . self::AMP,	// BT-13
			$root . '/rsm:ExchangedDocument/ram:IncludedNote[1]/ram:Content' => 'Note publique' . self::AMP,	// BT-22
			$settlement . '/ram:SpecifiedTradeSettlementPaymentMeans/ram:Information' => 'Virement bancaire' . self::AMP,	// BT-82
			$settlement . '/ram:SpecifiedTradeSettlementPaymentMeans/ram:PayeePartyCreditorFinancialAccount/ram:AccountName' => 'ETHIQUE' . self::AMP,	// BT-85
			$settlement . '/ram:SpecifiedTradePaymentTerms/ram:Description' => 'Conditions de reglement' . self::AMP,	// BT-20
			$settlement . '/ram:SpecifiedTradeAllowanceCharge/ram:Reason' => 'Geste commercial R' . self::AMP,	// BT-104
			$settlement . '/ram:ApplicableTradeTax/ram:ExemptionReason' => 'Motif exoneration' . self::AMP,		// BT-120
			$line . '/ram:SpecifiedTradeProduct/ram:SellerAssignedID' => 'REF-B' . self::AMP,	// BT-155
			$line . '/ram:SpecifiedTradeProduct/ram:Name' => 'Biere blonde 33cl' . self::AMP,	// BT-153
			$line . '/ram:SpecifiedTradeProduct/ram:Description' => 'Houblon' . self::AMP,		// BT-154
			$settlement . '/ram:InvoiceReferencedDocument/ram:IssuerAssignedID' => 'FA2606-0009' . self::AMP,	// BT-25
		];

		foreach ($expected as $path => $value) {
			$this->assertSame($value, $this->textAt($xpath, $path), $path . ' lost its value');
		}
	}

	/**
	 * PEPPOL-EN16931-R008 forbids empty elements, and the Access Point validators apply it. A value
	 * dropped on the way in shows up exactly like that, so the whole document is checked, not only
	 * the terms listed above.
	 *
	 * @return void
	 */
	public function testGeneratedDocumentHasNoEmptyElement()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		foreach (CIIProtocol::SUPPORTED_XML_PROFILES as $profile) {
			$doc = $this->load($protocol->buildXML($this->poisonedInvoiceData(), $this->poisonedLinesData(), $profile));
			$xpath = new DOMXPath($doc);
			$empty = [];
			foreach ($xpath->query('//*[not(node())]') as $node) {
				// The MINIMUM schema declares an empty HeaderTradeDelivery type: that group carries
				// nothing by design, it is not a value that went missing.
				if ($profile === 'MINIMUM' && $node->nodeName === 'ram:ApplicableHeaderTradeDelivery') {
					continue;
				}
				$empty[] = $node->nodeName;
			}

			$this->assertSame([], $empty, $profile . ' carries empty elements: ' . implode(', ', $empty));
		}
	}

	/**
	 * The trading name (BT-28 / BT-45), the payment means text (BT-82) and the account name (BT-85)
	 * are optional: with nothing to say, the element is not written at all.
	 *
	 * @return void
	 */
	public function testOptionalTermsAreOmittedWhenTheyHaveNoValue()
	{
		global $db;

		$data = $this->poisonedInvoiceData();
		$data['sellerTradingName'] = '';
		$data['buyerTradingName'] = '';
		$data['paymentMeansText'] = '';
		$data['accountName'] = '';

		$protocol = new CIIProtocol($db);
		$doc = $this->load($protocol->buildXML($data, $this->poisonedLinesData(), 'EN16931'));

		foreach (['ram:TradingBusinessName', 'ram:Information', 'ram:AccountName'] as $tag) {
			$this->assertSame(0, $doc->getElementsByTagName(explode(':', $tag)[1])->length, $tag . ' must be omitted when it has no value');
		}
	}

	/** @var Societe|null	The buyer every invoice of this class is made for */
	private static $buyer = null;

	/**
	 * The texts an invoice line is made to carry, and what has to survive of each.
	 *
	 * @return array<string,array{0:string,1:string}>	Case name => text typed, fragment that must remain
	 */
	public function hostileTexts()
	{
		return array(
			'a vertical tab pasted from a PDF' => array("Assembly\x0Bkit, ref 4711", 'Assembly'),
			'a unit separator' => array("Ref\x1F0042 delivered", 'delivered'),
			'a null byte' => array("Serial\x00number", 'Serial'),
			'an ampersand' => array('Nuts & bolts', 'Nuts & bolts'),
			'quotes' => array('The "special" article', 'special'),
			'accents' => array('Café crème, thé, à l\'unité', 'Café crème'),
			'several lines' => array("First line\nSecond line", 'Second line'),
			'a long text' => array(str_repeat('A very long description. ', 40), 'A very long description.'),
		);
	}

	/**
	 * The buyer of every invoice built here, created once.
	 *
	 * @return Societe	A customer third party
	 */
	private function buyer()
	{
		global $db;

		if (self::$buyer !== null) {
			return self::$buyer;
		}

		$user = new User($db);
		$this->assertGreaterThan(0, $user->fetch(1), 'the instance has a user to act as');

		$buyer = new Societe($db);
		$buyer->name = 'EINVOICING TEXT BUYER';
		$buyer->client = 1;
		// Some instances - the demo database among them - number their customers with a module that
		// refuses a third party without a code, and refuses a short one.
		$buyer->code_client = 'EINVTX' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
		$buyer->address = '2 rue du Test';
		$buyer->zip = '75000';
		$buyer->town = 'Paris';
		$buyer->country_id = 1;
		$buyer->country_code = 'FR';
		$buyer->idprof1 = '000000002';
		$buyer->idprof2 = '00000000200010';
		$buyer->tva_intra = 'FR12000000002';
		$this->assertGreaterThan(0, $buyer->create($user), 'the buyer is created: ' . $buyer->error . ' ' . implode(', ', (array) $buyer->errors));

		self::$buyer = $buyer;

		return self::$buyer;
	}

	/**
	 * Build a one-line invoice carrying the given text and generate its document.
	 *
	 * @param	string	$text	The description of the line
	 * @return	string			The document produced
	 */
	private function generateWith($text)
	{
		global $conf, $db, $langs, $mysoc;

		$user = new User($db);
		$user->fetch(1);

		$savPdp = getDolGlobalString('EINVOICING_PDP');
		$conf->global->EINVOICING_PDP = 'SPECIMEN';
		// A demo company whose SIREN is "123456" stops the generation before the text is reached.
		$savSeller = array(
			'idprof1' => $mysoc->idprof1,
			'idprof2' => $mysoc->idprof2,
			'tva_intra' => $mysoc->tva_intra,
			'country_id' => $mysoc->country_id,
			'country_code' => $mysoc->country_code,
		);
		$mysoc->idprof1 = '000000001';
		$mysoc->idprof2 = '00000000100010';
		$mysoc->tva_intra = 'FR12000000001';
		$mysoc->country_id = 1;
		$mysoc->country_code = 'FR';

		try {
			$invoice = new Facture($db);
			$invoice->socid = $this->buyer()->id;
			$invoice->type = Facture::TYPE_STANDARD;
			$invoice->date = dol_now();
			$this->assertGreaterThan(0, $invoice->create($user), 'the invoice is created: ' . $invoice->error);
			$this->assertGreaterThan(0, $invoice->addline($text, 10.00, 1, 20), 'the line is added: ' . $invoice->error);

			$reloaded = new Facture($db);
			$reloaded->fetch($invoice->id);
			$reloaded->fetch_lines();
			$reloaded->fetch_thirdparty();

			$protocol = new CIIProtocol($db);
			$path = $protocol->generateXML($reloaded, $langs);
			$this->assertNotEmpty($path, 'the document is generated: ' . $protocol->error);
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
	 * Whatever the text, the document produced is XML.
	 *
	 * @dataProvider hostileTexts
	 * @param	string	$text		The text typed on the invoice line
	 * @return	void
	 */
	public function testTheDocumentIsWellFormed($text)
	{
		$xml = $this->generateWith($text);

		$document = new DOMDocument();
		$parsed = @$document->loadXML($xml);

		$this->assertTrue(
			$parsed,
			'the document generated for this text is not XML: ' . trim((string) (($error = libxml_get_last_error()) ? $error->message : ''))
		);
	}

	/**
	 * And the text is still in it. A document that drops what was typed is as wrong as one that
	 * cannot be parsed - #695 produced empty elements, which are well formed.
	 *
	 * @dataProvider hostileTexts
	 * @param	string	$text		The text typed on the invoice line
	 * @param	string	$fragment	What has to remain readable in the document
	 * @return	void
	 */
	public function testTheTextIsStillThere($text, $fragment)
	{
		$xml = $this->generateWith($text);

		$document = new DOMDocument();
		$this->assertTrue(@$document->loadXML($xml), 'the document generated for this text is XML');

		$this->assertStringContainsString(
			$fragment,
			(string) $document->textContent,
			'the document no longer carries "' . $fragment . '"'
		);
	}
}
