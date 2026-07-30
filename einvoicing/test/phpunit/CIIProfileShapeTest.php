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

	/** @var string[] Elements the MINIMUM and BASIC WL schemas do not declare at all */
	private static $headerOnlyForbidden = [
		'ram:IncludedSupplyChainTradeLineItem',					// BG-25
		'ram:DefinedTradeContact',								// BG-6 / BG-9
		'ram:Information',										// BT-82
		'ram:AccountName',										// BT-85
		'ram:PayeeSpecifiedCreditorFinancialInstitution',		// BT-86
	];

	/**
	 * The header-only profiles must carry none of the groups their XSD does not declare.
	 *
	 * @return void
	 */
	public function testHeaderOnlyProfilesCarryNoneOfTheForbiddenGroups()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		foreach (['MINIMUM', 'BASICWL'] as $profile) {
			$xml = $protocol->buildXML($this->baseInvoiceData(), $this->baseLinesData(), $profile);

			foreach (self::$headerOnlyForbidden as $tag) {
				$this->assertSame(0, $this->countTag($xml, $tag), $profile . ' must not carry ' . $tag);
			}
			// BT-84 does exist there and must survive
			$this->assertSame(1, $this->countTag($xml, 'ram:IBANID'), $profile . ' must keep the payment account IBAN');
		}
	}

	/**
	 * Every profile from BASIC upwards keeps those groups: the fix for the header-only profiles
	 * must not strip them everywhere.
	 *
	 * @return void
	 */
	public function testOtherProfilesKeepTheFullGroups()
	{
		global $db;

		$protocol = new CIIProtocol($db);

		foreach (['BASIC', 'EN16931', 'EXTENDED', 'EXTENDEDFR'] as $profile) {
			$xml = $protocol->buildXML($this->baseInvoiceData(), $this->baseLinesData(), $profile);

			$this->assertSame(1, $this->countTag($xml, 'ram:IncludedSupplyChainTradeLineItem'), $profile . ' must carry the invoice line');
			foreach (['ram:DefinedTradeContact', 'ram:Information', 'ram:AccountName', 'ram:PayeeSpecifiedCreditorFinancialInstitution'] as $tag) {
				$this->assertGreaterThan(0, $this->countTag($xml, $tag), $profile . ' must carry ' . $tag);
			}
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
}
