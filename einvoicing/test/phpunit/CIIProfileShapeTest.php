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
 *      \file       test/phpunit/CIIProfileShapeTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the groups a profile is allowed to carry.
 *                  MINIMUM and BASIC WL are header-only Factur-X profiles: neither
 *                  ram:IncludedSupplyChainTradeLineItem (BG-25) nor ram:DefinedTradeContact
 *                  (BG-6 / BG-9) is declared in their XSD, so emitting either makes every
 *                  generated document fail the profile schema. Every profile from BASIC upwards
 *                  expects both.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// This module is deployed by symlinking this repository into htdocs/custom/einvoicing of one or
// several Dolibarr instances. Some test runners resolve the real (non-symlinked) path of this
// file before including it, which breaks a fixed "../../htdocs/master.inc.php" relative path.
// DOLIBARR_HTDOCS let's the developer/CI point explicitly at the Dolibarr instance to test
// against; otherwise we fall back to the standard relative path (valid when this file is reached
// through the htdocs/custom/einvoicing/test/phpunit symlink without realpath resolution).
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
class CIIProfileShapeTest extends CommonClassTest
{
	/**
	 * Header data covering every key buildXML() reads, with a single-rate, no-discount,
	 * no-reference baseline. Amounts are consistent so the document would also stand a
	 * business-rule check, which is not what this test is about.
	 *
	 * @return array
	 */
	private function baseInvoiceData(): array
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
			'documentNotePublic' => '',
			'documentNotePMT' => '',
			'documentNotePMD' => '',
			'documentNoteAAB' => '',
			'documentNoteTXD' => '',
			'vatDueDateTypeCode' => '5',
			'documentNotes' => [],

			'sellername' => 'Brasserie du Test SAS',
			'sellerids' => '892304189',
			'sellerlineone' => '12 rue des Houblons',
			'sellerlinetwo' => '',
			'sellerlinethree' => '',
			'sellerpostcode' => '86000',
			'sellercity' => 'Poitiers',
			'sellercountry' => 'FR',
			'sellersubdivision' => null,
			'sellercontactpersonname' => 'Service facturation',
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
			'sellerTradingName' => 'Brasserie du Test SAS',

			'buyername' => 'Tricaland SAS',
			'buyerids' => '123456782',
			'buyerlineone' => '5 avenue de la Distribution',
			'buyerlinetwo' => '',
			'buyerlinethree' => '',
			'buyerpostcode' => '75011',
			'buyercity' => 'Paris',
			'buyercountry' => 'FR',
			'buyersubdivision' => null,
			'buyervatnumber' => 'FR32123456782',
			'buyerGlobalIds' => [['schemeID' => '0225', 'value' => '12345678200019']],
			'buyerLegalOrgId' => '123456782',
			'buyerLegalOrgScheme' => '0002',
			'buyerTradingName' => 'Tricaland SAS',
			'buyerReference' => null,
			'buyerCommunicationUriScheme' => '0225',
			'buyerCommunicationUri' => '12345678200019',
			'buyercontactpersonname' => 'Service achats',
			'buyercontactemailaddr' => 'achats@example.org',
			'buyercontactphoneno' => '+33100000000',

			'grandTotalAmount' => 120.0,
			'duePayableAmount' => 120.0,
			'lineTotalAmount' => 100.0,
			'chargeTotalAmount' => 0.0,
			'allowanceTotalAmount' => 0.0,
			'taxBasisTotalAmount' => 100.0,
			'taxTotalAmount' => 20.0,
			'roundingAmount' => null,
			'totalPrepaidAmount' => 0.0,

			'paymentMeansCode' => 30,
			'paymentMeansText' => 'Virement bancaire',
			'iban_id' => 1,
			'iban' => 'FR7630003036200002012345652',
			'bic' => 'SOGEFRPP',
			'accountName' => 'Brasserie du Test SAS',
			'accountRef' => 'BQTEST',
			'accountLabel' => 'Compte courant',
			'paymentDueDate' => $date,
			'paymentTermsText' => '',
			'headerAllowancesCharges' => [],
			'invoiceRefDocs' => [],
			'orderReference' => '',
			'contractReference' => null,
			'despatchAdviceRef' => null,
			'taxBreakdown' => [
				'20' => [
					'tva_tx' => 20.0,
					'vat_src_code' => '',
					'categoryVAT' => 'S',
					'ExemptionReasonCode' => '',
					'ExemptionReason' => '',
					'totalHT' => 100.0,
					'totalTVA' => 20.0,
				],
			],
			'_chorus' => false,
			'_depositlines' => [],
			'_globalDiscounts' => [],
			'_customerOrderReferenceList' => [],
			'_project' => null,
		];
	}

	/**
	 * One neutral invoice line.
	 *
	 * @return array
	 */
	private function baseLinesData(): array
	{
		return [
			[
				'lineid' => 1,
				'prodsellerid' => '',
				'prodname' => 'Biere blonde 33cl',
				'proddesc' => '',
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
	 * Count the occurrences of an element in a generated document.
	 *
	 * @param	string	$xml	Generated XML
	 * @param	string	$tag	Qualified tag name, e.g. 'ram:DefinedTradeContact'
	 * @return	int				Number of occurrences
	 */
	private function countTag(string $xml, string $tag): int
	{
		$doc = new DOMDocument();
		$this->assertTrue($doc->loadXML($xml), 'generated document is not well-formed XML');

		return $doc->getElementsByTagName(explode(':', $tag)[1])->length;
	}

	/** @var string[] Elements only the EN16931 schema and its extensions declare */
	private static $en16931Only = [
		'ram:DefinedTradeContact',								// BG-6 / BG-9
		'ram:Information',										// BT-82
		'ram:AccountName',										// BT-85
		'ram:PayeeSpecifiedCreditorFinancialInstitution',		// BT-86
	];

	/**
	 * The invoice lines (BG-25) exist from BASIC up: MINIMUM and BASIC WL ("without lines") do not
	 * declare ram:IncludedSupplyChainTradeLineItem at all.
	 *
	 * @return void
	 */
	public function testLinesFollowTheProfileSchema()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		foreach (CIIProtocol::SUPPORTED_XML_PROFILES as $profile) {
			$xml = $protocol->buildXML($this->baseInvoiceData(), $this->baseLinesData(), $profile);
			$count = $this->countTag($xml, 'ram:IncludedSupplyChainTradeLineItem');

			if (in_array($profile, ['MINIMUM', 'BASICWL'], true)) {
				$this->assertSame(0, $count, $profile . ' does not declare the invoice lines');
			} else {
				$this->assertSame(1, $count, $profile . ' must carry the invoice line');
			}
		}
	}

	/**
	 * The party contact and the payment detail exist only from EN16931 up. BASIC declares the invoice
	 * lines but reduces those, so it cannot be lumped with the profiles that carry everything.
	 *
	 * @return void
	 */
	public function testEn16931OnlyGroupsFollowTheProfileSchema()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		foreach (CIIProtocol::SUPPORTED_XML_PROFILES as $profile) {
			$xml = $protocol->buildXML($this->baseInvoiceData(), $this->baseLinesData(), $profile);
			$full = in_array($profile, ['EN16931', 'EXTENDED', 'EXTENDEDFR'], true);

			foreach (self::$en16931Only as $tag) {
				if ($full) {
					$this->assertGreaterThan(0, $this->countTag($xml, $tag), $profile . ' must carry ' . $tag);
				} else {
					$this->assertSame(0, $this->countTag($xml, $tag), $profile . ' does not declare ' . $tag);
				}
			}
		}
	}

	/**
	 * MINIMUM is not a reduced EN 16931 but a much smaller document: no note, no identifier on the
	 * parties, an address down to its country, an empty delivery group, and a settlement holding only
	 * the currency and four amounts.
	 *
	 * @return void
	 */
	public function testMinimumCarriesOnlyWhatItsSchemaDeclares()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$xml = $protocol->buildXML($this->baseInvoiceData(), $this->baseLinesData(), 'MINIMUM');

		foreach ([
			'ram:IncludedNote',								// ExchangedDocumentType stops at IssueDateTime
			'ram:GlobalID',									// TradePartyType starts at ram:Name
			'ram:TradingBusinessName',						// LegalOrganizationType is reduced to ram:ID
			'ram:URIUniversalCommunication',				// BT-34 / BT-49
			'ram:PostcodeCode',								// TradeAddressType keeps only the country
			'ram:CityName',
			'ram:SpecifiedTradeSettlementPaymentMeans',		// BG-16
			'ram:ApplicableTradeTax',						// BG-23
			'ram:SpecifiedTradePaymentTerms',				// BT-20
			'ram:LineTotalAmount',							// BT-106
			'ram:ChargeTotalAmount',						// BT-108
			'ram:AllowanceTotalAmount',						// BT-107
			'ram:TotalPrepaidAmount',						// BT-113
		] as $tag) {
			$this->assertSame(0, $this->countTag($xml, $tag), 'MINIMUM does not declare ' . $tag);
		}

		// What it does declare must still be there
		foreach (['ram:CountryID', 'ram:InvoiceCurrencyCode', 'ram:TaxBasisTotalAmount', 'ram:GrandTotalAmount', 'ram:DuePayableAmount'] as $tag) {
			$this->assertGreaterThan(0, $this->countTag($xml, $tag), 'MINIMUM must carry ' . $tag);
		}

		// HeaderTradeDeliveryType is declared empty: the element must have no child at all
		$doc = new DOMDocument();
		$doc->loadXML($xml);
		$delivery = $doc->getElementsByTagName('ApplicableHeaderTradeDelivery')->item(0);
		$this->assertNotNull($delivery, 'the delivery group stays present');
		$this->assertFalse($delivery->hasChildNodes(), 'MINIMUM declares an empty HeaderTradeDeliveryType');
	}

	/**
	 * Every profile must produce a document its own Factur-X schema accepts. The schemas are the ones
	 * shipped with horstoeko/zugferd, which are the FNFE-MPE ones verbatim (compared on the profiles
	 * France_RFE publishes: only the schemaLocation file names differ).
	 *
	 * @return void
	 */
	public function testEveryProfileValidatesAgainstItsSchema()
	{
		global $db;

		$schemaDir = dol_buildpath('/einvoicing/vendor/horstoeko/zugferd/src/schema', 0);
		$xsd = [
			'MINIMUM' => 'FACTUR-X_MINIMUM.xsd',
			'BASICWL' => 'FACTUR-X_BASIC-WL.xsd',
			'BASIC' => 'FACTUR-X_BASIC.xsd',
			'EN16931' => 'FACTUR-X_EN16931.xsd',
			'EXTENDED' => 'FACTUR-X_EXTENDED.xsd',
			'EXTENDEDFR' => 'FACTUR-X_EXTENDED.xsd',	// conformant extension of EXTENDED
		];

		$protocol = new CIIProtocol($db);

		foreach (CIIProtocol::SUPPORTED_XML_PROFILES as $profile) {
			$this->assertArrayHasKey($profile, $xsd, 'no schema mapped for profile ' . $profile);
			$path = $schemaDir . '/' . $xsd[$profile];
			$this->assertFileExists($path);

			$doc = new DOMDocument();
			$this->assertTrue($doc->loadXML($protocol->buildXML($this->invoiceDataWithReferences(), $this->baseLinesData(), $profile)));

			$previous = libxml_use_internal_errors(true);
			libxml_clear_errors();
			$valid = $doc->schemaValidate($path);
			$errors = libxml_get_errors();
			libxml_use_internal_errors($previous);

			$detail = [];
			foreach (array_slice($errors, 0, 5) as $error) {
				$detail[] = trim($error->message);
			}
			$this->assertTrue($valid, $profile . ' must validate against ' . $xsd[$profile] . ":\n" . implode("\n", $detail));
		}
	}

	/**
	 * The header totals do not depend on the line nodes, so dropping them must leave the
	 * monetary summation untouched.
	 *
	 * @return void
	 */
	public function testHeaderTotalsAreUnchangedWithoutLines()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$withLines = $protocol->buildXML($this->baseInvoiceData(), $this->baseLinesData(), 'BASIC');
		$without   = $protocol->buildXML($this->baseInvoiceData(), $this->baseLinesData(), 'BASICWL');

		foreach (['LineTotalAmount', 'TaxBasisTotalAmount', 'GrandTotalAmount', 'DuePayableAmount'] as $tag) {
			$a = new DOMDocument();
			$a->loadXML($withLines);
			$b = new DOMDocument();
			$b->loadXML($without);

			$va = $a->getElementsByTagName($tag)->item(0);
			$vb = $b->getElementsByTagName($tag)->item(0);

			$this->assertNotNull($va, $tag . ' missing from the BASIC document');
			$this->assertNotNull($vb, $tag . ' missing from the BASICWL document');
			$this->assertSame($va->nodeValue, $vb->nodeValue, $tag . ' must not depend on the line nodes');
		}
	}

	/**
	 * Invoice data carrying the three header references, on top of the base fixture.
	 *
	 * @return	array<string,mixed>
	 */
	private function invoiceDataWithReferences()
	{
		require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';

		global $db;

		$project = new Project($db);
		$project->ref = 'PJ2607-0042';
		$project->title = 'Refonte du quai de brassage';

		$data = $this->baseInvoiceData();
		$data['buyerReference'] = 'SERVICE-EXEC-01';		// BT-10
		$data['contractReference'] = 'CTR-2026-118';		// BT-12
		$data['_project'] = $project;						// BT-11

		return $data;
	}

	/**
	 * Read the text of the first occurrence of an element.
	 *
	 * Only ever called on elements that carry text directly: the document is generated with
	 * formatOutput, so the nodeValue of a wrapper would also hold the indentation of its children.
	 *
	 * @param	string	$xml	Generated XML
	 * @param	string	$tag	Qualified tag name, e.g. 'ram:BuyerReference'
	 * @return	?string			Its text, null when the element is absent
	 */
	private function tagValue(string $xml, string $tag)
	{
		$doc = new DOMDocument();
		$this->assertTrue($doc->loadXML($xml), 'generated document is not well-formed XML');
		$node = $doc->getElementsByTagName(explode(':', $tag)[1])->item(0);

		return $node === null ? null : $node->nodeValue;
	}

	/**
	 * The buyer reference (BT-10) is declared by every profile, MINIMUM included.
	 *
	 * @return void
	 */
	public function testBuyerReferenceIsEmittedOnEveryProfile()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		foreach (CIIProtocol::SUPPORTED_XML_PROFILES as $profile) {
			$xml = $protocol->buildXML($this->invoiceDataWithReferences(), $this->baseLinesData(), $profile);

			$this->assertSame('SERVICE-EXEC-01', $this->tagValue($xml, 'ram:BuyerReference'), $profile . ' must carry BT-10');
		}
	}

	/**
	 * The buyer routing code travels as a second ram:GlobalID of the buyer party, and only the
	 * EXTENDED profiles may carry it.
	 *
	 * Scheme 0224 is where BR-FR-CPRO-11 and BR-FR-CPRO-13 read the Chorus Pro service code, but it
	 * is a second identifier for the party, and the Factur-X EN16931 Schematron caps that element at
	 * one occurrence (FX-SCH-A-000164): a document below EXTENDED that carries both is refused. The
	 * Annexe B examples of XP Z12-012 agree - the routing code is in the EXTENDED and EXTENDED-CTC-FR
	 * files of an invoice, absent from its EN16931 twin (issue #678).
	 *
	 * @return void
	 */
	public function testBuyerRoutingCodeIsOnlyEmittedByTheExtendedProfiles()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		$data = $this->baseInvoiceData();
		$data['buyerRoutingCode'] = 'CDROUT1';

		foreach (CIIProtocol::SUPPORTED_XML_PROFILES as $profile) {
			$xml = $protocol->buildXML($data, $this->baseLinesData(), $profile);
			$found = $this->partyGlobalIds($xml, 'BuyerTradeParty');

			if ($profile === 'MINIMUM') {
				$this->assertSame([], $found, 'MINIMUM declares no identifier for the buyer party');
			} elseif (in_array($profile, ['EXTENDED', 'EXTENDEDFR'], true)) {
				$this->assertSame(
					[['0225', '12345678200019'], ['0224', 'CDROUT1']],
					$found,
					$profile . ' must carry the legal identifier and the routing code'
				);
			} else {
				$this->assertSame(
					[['0225', '12345678200019']],
					$found,
					$profile . ' allows a single buyer identifier, the routing code must be left out'
				);
			}
		}
	}

	/**
	 * The routing code of the buyer never reaches the deliver-to party.
	 *
	 * That party is built from the same data in minimal mode, and its own identifier is BT-71, the
	 * identifier of a location. Writing the buyer routing code there means the wrong term, and a
	 * second ram:GlobalID its Schematron rule caps at one as well (FX-SCH-A-000452).
	 *
	 * @return void
	 */
	public function testBuyerRoutingCodeNeverReachesTheDeliverToParty()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		// No _shipFromContactShip on purpose: that is the path where the deliver-to party is built as
		// a stripped-down copy of the buyer, hence the one that could carry the routing code along.
		$data = $this->baseInvoiceData();
		$data['buyerRoutingCode'] = 'CDROUT1';

		foreach (['EXTENDED', 'EXTENDEDFR'] as $profile) {
			$xml = $protocol->buildXML($data, $this->baseLinesData(), $profile);

			$this->assertSame(
				[['0225', '12345678200019'], ['0224', 'CDROUT1']],
				$this->partyGlobalIds($xml, 'BuyerTradeParty'),
				$profile . ' must carry the routing code on the buyer'
			);
			foreach ($this->partyGlobalIds($xml, 'ShipToTradeParty') as $globalId) {
				$this->assertNotSame('0224', $globalId[0], $profile . ' must not route-code the deliver-to party');
			}
		}
	}

	/**
	 * Read the (schemeID, value) pairs of the ram:GlobalID of a party.
	 *
	 * @param	string				$xml	Generated XML
	 * @param	string				$tag	Local name of the party element
	 * @return	array<array<string>>		One [schemeID, value] pair per identifier, in document order
	 */
	private function partyGlobalIds(string $xml, string $tag)
	{
		$doc = new DOMDocument();
		$this->assertTrue($doc->loadXML($xml), 'generated document is not well-formed XML');

		$found = [];
		$parties = $doc->getElementsByTagName($tag);
		if ($parties->length > 0) {
			foreach ($parties->item(0)->childNodes as $child) {
				if ($child instanceof DOMElement && $child->localName === 'GlobalID') {
					$found[] = [$child->getAttribute('schemeID'), $child->nodeValue];
				}
			}
		}

		return $found;
	}

	/**
	 * The contract reference (BT-12) is absent from the MINIMUM schema and declared everywhere else.
	 *
	 * @return void
	 */
	public function testContractReferenceFollowsTheProfileSchema()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		foreach (CIIProtocol::SUPPORTED_XML_PROFILES as $profile) {
			$xml = $protocol->buildXML($this->invoiceDataWithReferences(), $this->baseLinesData(), $profile);
			$count = $this->countTag($xml, 'ram:ContractReferencedDocument');

			if ($profile === 'MINIMUM') {
				$this->assertSame(0, $count, 'MINIMUM does not declare ram:ContractReferencedDocument');
			} else {
				$this->assertSame(1, $count, $profile . ' must carry BT-12');
				// ram:ContractReferencedDocument is a wrapper: the reference sits on its IssuerAssignedID
				$doc = new DOMDocument();
				$doc->loadXML($xml);
				$node = $doc->getElementsByTagName('ContractReferencedDocument')->item(0);
				$this->assertNotNull($node);
				$this->assertSame('CTR-2026-118', $node->getElementsByTagName('IssuerAssignedID')->item(0)->nodeValue, $profile . ' BT-12 value');
			}
		}
	}

	/**
	 * The project reference (BT-11) only exists from EN16931 up, and its type makes both ram:ID and
	 * ram:Name mandatory.
	 *
	 * @return void
	 */
	public function testProjectReferenceFollowsTheProfileSchema()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		foreach (CIIProtocol::SUPPORTED_XML_PROFILES as $profile) {
			$xml = $protocol->buildXML($this->invoiceDataWithReferences(), $this->baseLinesData(), $profile);
			$count = $this->countTag($xml, 'ram:SpecifiedProcuringProject');

			if (in_array($profile, ['EN16931', 'EXTENDED', 'EXTENDEDFR'], true)) {
				$this->assertSame(1, $count, $profile . ' must carry BT-11');

				$doc = new DOMDocument();
				$doc->loadXML($xml);
				$node = $doc->getElementsByTagName('SpecifiedProcuringProject')->item(0);
				$this->assertNotNull($node);
				$this->assertSame('PJ2607-0042', $node->getElementsByTagName('ID')->item(0)->nodeValue);
				// ram:Name is mandatory in ProcuringProjectType, an empty one would break the schema
				$this->assertSame('Refonte du quai de brassage', $node->getElementsByTagName('Name')->item(0)->nodeValue);
			} else {
				$this->assertSame(0, $count, $profile . ' does not declare ram:SpecifiedProcuringProject');
			}
		}
	}

	/**
	 * A project with no title still yields a schema-valid BT-11: ram:Name falls back on the reference.
	 *
	 * @return void
	 */
	public function testProjectWithoutTitleStillCarriesAName()
	{
		global $db;

		$data = $this->invoiceDataWithReferences();
		$data['_project']->title = '';

		$protocol = new CIIProtocol($db);
		$xml = $protocol->buildXML($data, $this->baseLinesData(), 'EN16931');

		$doc = new DOMDocument();
		$doc->loadXML($xml);
		$node = $doc->getElementsByTagName('SpecifiedProcuringProject')->item(0);
		$this->assertNotNull($node);
		$this->assertSame('PJ2607-0042', $node->getElementsByTagName('Name')->item(0)->nodeValue);
	}

	/**
	 * Nothing is emitted when the invoice carries none of the three: no empty element is left behind.
	 *
	 * @return void
	 */
	public function testNoReferenceEmittedWhenTheInvoiceHasNone()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		foreach (CIIProtocol::SUPPORTED_XML_PROFILES as $profile) {
			$xml = $protocol->buildXML($this->baseInvoiceData(), $this->baseLinesData(), $profile);

			$this->assertSame(0, $this->countTag($xml, 'ram:BuyerReference'), $profile . ' must not carry an empty BT-10');
			$this->assertSame(0, $this->countTag($xml, 'ram:ContractReferencedDocument'), $profile . ' must not carry an empty BT-12');
			$this->assertSame(0, $this->countTag($xml, 'ram:SpecifiedProcuringProject'), $profile . ' must not carry an empty BT-11');
		}
	}

	/**
	 * Invoice data carrying an invoicing period (BG-14), and lines carrying their own (BG-26).
	 *
	 * @return	array{0: array<string,mixed>, 1: array<int,array<string,mixed>>}
	 */
	private function invoiceDataWithPeriod()
	{
		$data = $this->invoiceDataWithReferences();
		$data['invoicingPeriodStart'] = new DateTime('2026-06-01');		// BT-73
		$data['invoicingPeriodEnd'] = new DateTime('2026-06-30');		// BT-74

		$lines = $this->baseLinesData();
		$lines[0]['linePeriodStart'] = new DateTime('2026-06-01');		// BT-134
		$lines[0]['linePeriodEnd'] = new DateTime('2026-06-30');			// BT-135

		return [$data, $lines];
	}

	/**
	 * The invoicing period of the document (BG-14) exists from BASIC WL up: MINIMUM declares no
	 * ram:BillingSpecifiedPeriod under its HeaderTradeSettlementType, which holds ram:InvoiceCurrencyCode
	 * and the monetary summation and nothing else (issue #572).
	 *
	 * @return void
	 */
	public function testInvoicingPeriodFollowsTheProfileSchema()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		list($data, $lines) = $this->invoiceDataWithPeriod();

		foreach (CIIProtocol::SUPPORTED_XML_PROFILES as $profile) {
			$doc = new DOMDocument();
			$this->assertTrue($doc->loadXML($protocol->buildXML($data, $lines, $profile)));

			$settlement = $doc->getElementsByTagName('ApplicableHeaderTradeSettlement')->item(0);
			$this->assertNotNull($settlement, $profile . ' must carry the settlement group');

			$periods = [];
			foreach ($settlement->childNodes as $child) {
				if ($child instanceof DOMElement && $child->localName === 'BillingSpecifiedPeriod') {
					$periods[] = $child;
				}
			}

			if ($profile === 'MINIMUM') {
				$this->assertSame(0, count($periods), 'MINIMUM does not declare ram:BillingSpecifiedPeriod');
				continue;
			}

			$this->assertSame(1, count($periods), $profile . ' must carry BG-14 once');
			$this->assertSame('20260601', $periods[0]->getElementsByTagName('DateTimeString')->item(0)->nodeValue, $profile . ' BT-73');
			$this->assertSame('102', $periods[0]->getElementsByTagName('DateTimeString')->item(0)->getAttribute('format'));
			$this->assertSame('20260630', $periods[0]->getElementsByTagName('DateTimeString')->item(1)->nodeValue, $profile . ' BT-74');
		}
	}

	/**
	 * Where BG-14 sits in the settlement group is not decoration: HeaderTradeSettlementType is a
	 * sequence, and a ram:BillingSpecifiedPeriod written before the ram:ApplicableTradeTax nodes or
	 * after the payment terms fails schema validation whatever it contains.
	 *
	 * @return void
	 */
	public function testInvoicingPeriodSitsWhereTheSchemaSequenceExpectsIt()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		list($data, $lines) = $this->invoiceDataWithPeriod();

		$doc = new DOMDocument();
		$this->assertTrue($doc->loadXML($protocol->buildXML($data, $lines, 'EN16931')));

		$settlement = $doc->getElementsByTagName('ApplicableHeaderTradeSettlement')->item(0);
		$order = [];
		foreach ($settlement->childNodes as $child) {
			if ($child instanceof DOMElement) {
				$order[] = $child->localName;
			}
		}

		$period = array_search('BillingSpecifiedPeriod', $order, true);
		$this->assertNotFalse($period, 'BG-14 missing from the settlement group');

		$lastTax = array_keys($order, 'ApplicableTradeTax', true);
		$this->assertNotEmpty($lastTax, 'no VAT breakdown to place BG-14 after');
		$this->assertGreaterThan(end($lastTax), $period, 'BG-14 comes after every ram:ApplicableTradeTax');

		$terms = array_search('SpecifiedTradePaymentTerms', $order, true);
		$this->assertNotFalse($terms, 'no payment terms to place BG-14 before');
		$this->assertLessThan($terms, $period, 'BG-14 comes before ram:SpecifiedTradePaymentTerms');
	}

	/**
	 * A document with a period must still validate against the schema of each profile, which is what
	 * proves the placement above rather than the assertion on the order of the siblings alone.
	 *
	 * @return void
	 */
	public function testAProfileWithAnInvoicingPeriodValidatesAgainstItsSchema()
	{
		global $db;

		$schemaDir = dol_buildpath('/einvoicing/vendor/horstoeko/zugferd/src/schema', 0);
		$xsd = [
			'MINIMUM' => 'FACTUR-X_MINIMUM.xsd',
			'BASICWL' => 'FACTUR-X_BASIC-WL.xsd',
			'BASIC' => 'FACTUR-X_BASIC.xsd',
			'EN16931' => 'FACTUR-X_EN16931.xsd',
			'EXTENDED' => 'FACTUR-X_EXTENDED.xsd',
			'EXTENDEDFR' => 'FACTUR-X_EXTENDED.xsd',	// conformant extension of EXTENDED
		];

		$protocol = new CIIProtocol($db);
		list($data, $lines) = $this->invoiceDataWithPeriod();

		foreach (CIIProtocol::SUPPORTED_XML_PROFILES as $profile) {
			$doc = new DOMDocument();
			$this->assertTrue($doc->loadXML($protocol->buildXML($data, $lines, $profile)));

			$previous = libxml_use_internal_errors(true);
			libxml_clear_errors();
			$valid = $doc->schemaValidate($schemaDir . '/' . $xsd[$profile]);
			$errors = libxml_get_errors();
			libxml_use_internal_errors($previous);

			$detail = [];
			foreach (array_slice($errors, 0, 5) as $error) {
				$detail[] = trim($error->message);
			}
			$this->assertTrue($valid, $profile . ' with a period must validate against ' . $xsd[$profile] . ":\n" . implode("\n", $detail));
		}
	}

	/**
	 * One side of the period alone is what the norm accepts (BR-CO-19 asks for the start date or the
	 * end date), and the missing side leaves no empty element behind.
	 *
	 * @return void
	 */
	public function testOneSideOfTheInvoicingPeriodIsEnough()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		list($data, $lines) = $this->invoiceDataWithPeriod();
		$data['invoicingPeriodEnd'] = null;

		$doc = new DOMDocument();
		$this->assertTrue($doc->loadXML($protocol->buildXML($data, $lines, 'EN16931')));

		$settlement = $doc->getElementsByTagName('ApplicableHeaderTradeSettlement')->item(0);
		$period = null;
		foreach ($settlement->childNodes as $child) {
			if ($child instanceof DOMElement && $child->localName === 'BillingSpecifiedPeriod') {
				$period = $child;
			}
		}

		$this->assertNotNull($period, 'a start date alone is still a period');
		$this->assertSame(1, $period->getElementsByTagName('StartDateTime')->length);
		$this->assertSame(0, $period->getElementsByTagName('EndDateTime')->length, 'no empty BT-74');
	}

	/**
	 * An invoice with no period on any line carries no header period either, and in particular no
	 * empty group - which is also what every document generated before this existed looked like.
	 *
	 * @return void
	 */
	public function testNoInvoicingPeriodEmittedWhenTheInvoiceHasNone()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		foreach (CIIProtocol::SUPPORTED_XML_PROFILES as $profile) {
			$xml = $protocol->buildXML($this->baseInvoiceData(), $this->baseLinesData(), $profile);

			$this->assertSame(0, $this->countTag($xml, 'ram:BillingSpecifiedPeriod'), $profile . ' must not carry an empty BG-14');
		}
	}
}
