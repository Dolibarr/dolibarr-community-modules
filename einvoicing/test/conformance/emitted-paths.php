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
 * \file    einvoicing/test/conformance/emitted-paths.php
 * \ingroup einvoicing
 * \brief   What the builder writes today, so that what it stops writing is never a discovery.
 *
 * A write that leaves the module produces no invalid document and no failing test: the invoices
 * that needed it simply, quietly, stop carrying it (issue #912 / #955 - the deposit reference was
 * removed from the reprise line and nobody's test went red for two days). Neither a schematron nor
 * the conformance run can report that, since they only ever see the documents that remain valid.
 *
 * So this signs what is actually written. Fixtures that never change, every supported profile, and
 * the baseline is the set of element *paths* the generated documents carry - ram:InvoiceReferencedDocument
 * under a line is not the same capability as the same element under the header. A path may appear
 * freely; a path may only disappear on purpose, with --update-baseline and a word in the pull request.
 *
 * Several invoice cases, not one: some writes exclude each other (an account is identified by its
 * IBAN *or* by its proprietary reference, never both), so one invoice can never reach them all. The
 * paths of the cases are unioned - they exist to reach branches the others cannot. The lifecycle
 * answer (CDAR) is a second document this module writes, and is signed the same way.
 *
 * Because the fixtures are fixed, an absent path means one thing only: nothing wrote it. That is
 * what separates this from measuring on a specimen invoice - there, an absent element may only
 * mean that particular invoice had nothing to put there.
 *
 *   DOLI_ROOT=/var/www/dd23/htdocs php emitted-paths.php [--update-baseline]
 */

$root = getenv('DOLI_ROOT') ?: '';
if ($root === '' || !file_exists($root . '/master.inc.php')) {
	fwrite(STDERR, "DOLI_ROOT must point at the htdocs of a Dolibarr instance\n");
	exit(2);
}
require_once $root . '/master.inc.php';
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');

$updateBaseline = in_array('--update-baseline', array_slice($argv, 1), true);
$baselineFile = __DIR__ . '/emitted-paths.txt';

/**
 * A header exercising every optional branch of the builder at once.
 *
 * Amounts are not meant to add up: this run asks what gets written, not whether the document would
 * pass BR-CO-*. validate-cii.py is the test that asks that, on documents built to be consistent.
 *
 * @return array<string,mixed>	Header data for buildXML()
 */
function einvoicingMaximalHeader()
{
	global $db;

	$date = new DateTime('2026-07-01');
	$later = new DateTime('2026-07-31');

	// BT-11 is written only for a Project instance, never for a plain array (isEn16931Profile() and
	// instanceof, both).
	require_once DOL_DOCUMENT_ROOT . '/projet/class/project.class.php';
	$project = new Project($db);
	$project->ref = 'PROJ-2026';
	$project->title = 'Chantier de test';

	return array(
		'documentno' => 'FA2607-0001',
		'documenttypecode' => '380',
		'documentdate' => $date,
		'invoiceCurrency' => 'EUR',
		'taxCurrency' => 'EUR',
		'documentname' => 'Facture',
		'documentlanguage' => 'fr',
		'effectiveSpecifiedPeriod' => 'NA',
		'documentDeliveryDate' => $date,
		'invoicingPeriodStart' => $date,
		'invoicingPeriodEnd' => $later,
		'businessProcessId' => 'B1',
		'isTestDocument' => true,
		'documentNotePublic' => 'Note publique',
		'documentNotePMT' => 'Penalites de retard',
		'documentNotePMD' => 'Escompte pour paiement anticipe',
		'documentNoteAAB' => 'Conditions generales de vente',
		'documentNoteTXD' => 'Mention de TVA',
		'vatDueDateTypeCode' => '5',
		'documentNotes' => array(array('content' => 'Note libre', 'subjectCode' => 'AAI')),

		'sellername' => 'Brasserie du Test SAS',
		'sellerids' => '892304189',
		'sellerlineone' => '12 rue des Houblons',
		'sellerlinetwo' => 'Batiment B',
		'sellerlinethree' => 'ZA des Brasseurs',
		'sellerpostcode' => '86000',
		'sellercity' => 'Poitiers',
		'sellercountry' => 'FR',
		'sellersubdivision' => 'Nouvelle-Aquitaine',
		'sellercontactpersonname' => 'Service facturation',
		'sellercontactdepartmentname' => 'Comptabilite clients',
		'sellercontactphoneno' => '+33549000000',
		'sellercontactfaxno' => '+33549000001',
		'sellercontactemailaddr' => 'facturation@example.org',
		'sellerCommunicationUriScheme' => '0225',
		'sellerCommunicationUri' => '89230418900020',
		'sellerGlobalIds' => array(array('schemeID' => '0225', 'value' => '89230418900020')),
		'sellerTaxRegistrations' => array(array('schemeID' => 'FC', 'value' => '892304189')),
		'sellervatnumber' => 'FR87892304189',
		'sellerLegalOrgId' => '892304189',
		'sellerLegalOrgScheme' => '0002',
		'sellerTradingName' => 'La Brasserie',

		'buyername' => 'Tricaland SAS',
		'buyerids' => '123456782',
		'buyerlineone' => '5 avenue de la Distribution',
		'buyerlinetwo' => 'Tour Nord',
		'buyerlinethree' => 'CS 90000',
		'buyerpostcode' => '75011',
		'buyercity' => 'Paris',
		'buyercountry' => 'FR',
		'buyersubdivision' => 'Ile-de-France',
		'buyervatnumber' => 'FR32123456782',
		'buyerGlobalIds' => array(array('schemeID' => '0225', 'value' => '12345678200019')),
		'buyerLegalOrgId' => '123456782',
		'buyerLegalOrgScheme' => '0002',
		'buyerTradingName' => 'Tricaland',
		'buyerReference' => 'SERVICE-ACHATS',
		'buyerRoutingCode' => 'CODE-SERVICE',
		'buyerCommunicationUriScheme' => '0225',
		'buyerCommunicationUri' => '12345678200019',
		'buyercontactpersonname' => 'Service achats',
		'buyercontactdepartmentname' => 'Achats generaux',
		'buyercontactemailaddr' => 'achats@example.org',
		'buyercontactphoneno' => '+33100000000',

		'grandTotalAmount' => 120.0,
		'duePayableAmount' => 60.0,
		'lineTotalAmount' => 100.0,
		'chargeTotalAmount' => 10.0,
		'allowanceTotalAmount' => 10.0,
		'taxBasisTotalAmount' => 100.0,
		'taxTotalAmount' => 20.0,
		'roundingAmount' => 0.01,
		'totalPrepaidAmount' => 60.0,

		'paymentMeansCode' => 30,
		'paymentMeansText' => 'Virement bancaire',
		'iban_id' => 1,
		'iban' => 'FR7630003036200002012345652',
		'bic' => 'SOGEFRPP',
		'accountName' => 'Brasserie du Test SAS',
		'accountRef' => 'BQTEST',
		'accountLabel' => 'Compte courant',
		'paymentDueDate' => $later,
		'paymentTermsText' => 'Paiement a 30 jours',
		'invoiceRefDocs' => array(array('ref' => 'FA2606-0009', 'date' => $date, 'type' => '386')),
		'orderReference' => 'CMD-2026-42',
		'contractReference' => 'CONTRAT-2026',
		'despatchAdviceRef' => 'BL-2026-77',
		'taxBreakdown' => array(
			'20' => array('tva_tx' => 20.0, 'vat_src_code' => '', 'categoryVAT' => 'S', 'ExemptionReasonCode' => '', 'ExemptionReason' => '', 'totalHT' => 100.0, 'totalTVA' => 20.0),
			'0' => array('tva_tx' => 0.0, 'vat_src_code' => '', 'categoryVAT' => 'E', 'ExemptionReasonCode' => 'VATEX-EU-AE', 'ExemptionReason' => 'Autoliquidation', 'totalHT' => 0.0, 'totalTVA' => 0.0),
		),
		// true drops the additional order references (BT-18) the fixture is here to reach.
		'_chorus' => false,
		'_depositlines' => array(),
		// BG-20 at document level: the only header allowance the builder reads. headerAllowancesCharges
		// is the key of the reading side, and buildXML() never looks at it.
		'_globalDiscounts' => array(
			array('value' => 10.0, 'reason' => 'Remise commerciale', 'categoryVAT' => 'S', 'taxRate' => 20.0),
		),
		'_customerOrderReferenceList' => array('CMD-2026-42', 'CMD-2026-43'),
		'_project' => $project,
		'_shipFromContactBill' => array(
			'name' => 'Tricaland Entrepot', 'address' => '9 route du Depot', 'lineone' => '9 route du Depot',
			'linetwo' => 'Quai 3', 'linethree' => '', 'zip' => '33000', 'town' => 'Bordeaux', 'country' => 'FR',
		),
		'_shipFromContactShip' => array(
			'name' => 'Tricaland Entrepot', 'address' => '9 route du Depot', 'lineone' => '9 route du Depot',
			'linetwo' => 'Quai 3', 'linethree' => '', 'zip' => '33000', 'town' => 'Bordeaux', 'country' => 'FR',
		),
	);
}

/**
 * The lines, one per shape the builder knows how to write.
 *
 * @return array<int,array<string,mixed>>	Line data for buildXML()
 */
function einvoicingMaximalLines()
{
	$date = new DateTime('2026-07-01');
	$later = new DateTime('2026-07-31');

	$full = array(
		'lineid' => 1,
		'linestatuscode' => '39',
		'linestatusreasoncode' => 'AAB',
		'lineNote' => 'Commentaire de ligne',
		'prodname' => 'Biere blonde 33cl',
		'proddesc' => 'Brassee sur place',
		'prodsellerid' => 'REF-VENDEUR',
		'prodbuyerid' => 'REF-ACHETEUR',
		'prodglobalidtype' => '0160',
		'prodglobalid' => '3401579000015',
		'prodmultilangs' => array(),
		'prodClassificationCode' => '22040000',
		'prodClassificationScheme' => 'TST',
		'prodOriginCountry' => 'FR',
		'netpriceamount' => 10.0,
		'netpricebasisquantity' => 1.0,
		'netpricebasisquantityunitcode' => 'C62',
		'grosspriceamount' => 12.0,
		'grosspricebasisquantity' => 1.0,
		'grosspricebasisquantityunitcode' => 'C62',
		'billedquantity' => 10.0,
		'billedquantityunitcode' => 'C62',
		'chargeFreeQuantity' => 1.0,
		'chargeFreeQuantityunitcode' => 'C62',
		'packageQuantity' => 2.0,
		'packageQuantityunitcode' => 'C62',
		'lineTotalAmount' => 100.0,
		'totalAllowanceChargeAmount' => 2.0,
		'categoryCode' => 'S',
		'typeCode' => 'VAT',
		'rateApplicablePercent' => '20.00',
		'tva_tx' => 20.0,
		'vat_src_code' => '',
		'ExemptionReason' => '',
		'ExemptionReasonCode' => '',
		'calculatedAmount' => 20.0,
		// lineAllowances and lineGrossPriceAllowances are keys of the reading side; BG-27 on a line is
		// written from discountPercent alone.
		'lineAllowances' => array(),
		'lineGrossPriceAllowances' => array(),
		'lineremisepercent' => 10.0,
		'linePeriodStart' => $date,
		'linePeriodEnd' => $later,
		'additionalRefDocs' => array(array('ref' => 'LIGNE-OBJET-1', 'type' => '130')),
		'isDepositLine' => false,
		'depositInvoiceRef' => 'NA',
		'depositInvoiceDate' => 'NA',
		'parentDocumentNo' => null,
		'is_deposit' => 0,
		'fk_remise' => null,
		'discountPercent' => 10.0,
	);

	// The reprise line of a deposit: what #912 moved and #955 put back. It is the reason this file
	// exists, so it is a fixture of its own and not an option on the line above.
	$deposit = array_merge($full, array(
		'lineid' => 2,
		'prodname' => 'Acompte deduit',
		'netpriceamount' => -60.0,
		'billedquantity' => 1.0,
		'lineTotalAmount' => -60.0,
		'additionalRefDocs' => array(),
		'discountPercent' => 0,
		'isDepositLine' => true,
		'depositInvoiceRef' => 'FA2606-0009',
		'depositInvoiceDate' => $date,
		'is_deposit' => 1,
	));

	// A line billing an exempt rate, so the exemption branch of the line tax node is written too.
	$exempt = array_merge($full, array(
		'lineid' => 3,
		'prodname' => 'Prestation exoneree',
		'categoryCode' => 'E',
		'rateApplicablePercent' => '0.00',
		'tva_tx' => 0.0,
		'calculatedAmount' => 0.0,
		'ExemptionReason' => 'Autoliquidation',
		'ExemptionReasonCode' => 'VATEX-EU-AE',
		'additionalRefDocs' => array(),
	));

	return array($full, $deposit, $exempt);
}

/**
 * The lifecycle answer (CDAR), with every optional block it knows how to write.
 *
 * A second document this module emits, and a builder of its own: none of what CdarHandler writes is
 * reachable through buildXML(), so leaving it out would leave a whole family unsigned.
 *
 * @return array<string,mixed>	Data for CdarHandler::generate()
 */
function einvoicingMaximalCdar()
{
	$party = array('GlobalID' => '89230418900020', 'SchemeID' => '0225', 'RoleCode' => 'SE', 'URIID' => '89230418900020', 'URISchemeID' => '0225');

	return array(
		'GuidelineID' => 'urn.cpro.gouv.fr:1p0:ahm',
		'ExchangedDocument' => array(
			'ID' => 'FA2607-0001_210_20260701120000#380_20260701',
			'Name' => 'FA2607-0001_210_20260701120000#380_20260701',
			'IssueDateTime' => '20260701120000',
			'SenderTradeParty' => $party,
			'IssuerTradeParty' => array_merge($party, array('RoleCode' => 'BY')),
			'RecipientTradeParty' => array_merge($party, array('RoleCode' => 'SE')),
		),
		'AcknowledgementDocument' => array(
			'MultipleReferencesIndicator' => false,
			'TypeCode' => 'AB',
			'IssueDateTime' => '20260701120000',
			'ReferenceReferencedDocument' => array(
				'IssuerAssignedID' => 'FA2607-0001',
				'StatusCode' => '210',
				'TypeCode' => '380',
				'ReferenceTypeCode' => 'AWR',
				'FormattedIssueDateTime' => '20260701',
				'ProcessConditionCode' => 'PROCESSED',
				'ProcessCondition' => 'Traitement termine',
				'IssuerTradeParty' => array_merge($party, array('RoleCode' => 'BY')),
				'SpecifiedDocumentStatus' => array(
					'ReasonCode' => 'ARF',
					'Reason' => 'Facture rejetee',
					'SequenceNumeric' => 1,
					// MDG-43, the cashed amount per VAT rate carried by a status 212.
					'SpecifiedDocumentCharacteristic' => array(
						array('TypeCode' => 'AAE', 'ValueAmount' => '120.00', 'CurrencyID' => 'EUR', 'ValueDateTime' => '20260701', 'ValuePercent' => '20.00'),
					),
				),
			),
		),
	);
}

/**
 * Every element path a document carries, as prefix:localName steps from the root.
 *
 * @param	string	$xml	Generated document
 * @return	array<string,bool>	Set of paths
 */
function einvoicingDocumentPaths($xml)
{
	$doc = new DOMDocument();
	if (!@$doc->loadXML($xml)) {
		fwrite(STDERR, "generated document is not well-formed XML\n");
		exit(1);
	}

	$paths = array();
	$walk = function (DOMNode $node, $prefix) use (&$walk, &$paths) {
		foreach ($node->childNodes as $child) {
			if ($child->nodeType !== XML_ELEMENT_NODE) {
				continue;
			}
			$here = $prefix . '/' . $child->nodeName;
			$paths[$here] = true;
			foreach ($child->attributes as $attr) {
				$paths[$here . '@' . $attr->nodeName] = true;
			}
			$walk($child, $here);
		}
	};
	$rootEl = $doc->documentElement;
	$paths['/' . $rootEl->nodeName] = true;
	$walk($rootEl, '/' . $rootEl->nodeName);

	return $paths;
}

global $db;
$protocol = new CIIProtocol($db);
$lines = einvoicingMaximalLines();

// The account is identified by its IBAN (BT-84) or, failing that, by its proprietary reference
// (BT-84-0): the second case is the only way to reach the fallback, which the first one hides.
$withIban = einvoicingMaximalHeader();
$withoutIban = einvoicingMaximalHeader();
$withoutIban['iban'] = '';
$withoutIban['paymentMeansCode'] = 48;

$current = array();
foreach (array($withIban, $withoutIban) as $header) {
	foreach (CIIProtocol::SUPPORTED_XML_PROFILES as $profile) {
		$xml = $protocol->buildXML($header, $lines, $profile);
		foreach (array_keys(einvoicingDocumentPaths($xml)) as $path) {
			$current[$profile . ' ' . $path] = true;
		}
	}
}

dol_include_once('einvoicing/class/utils/CdarHandler.class.php');
$cdar = new CdarHandler($db);
$cdarXml = $cdar->generate(einvoicingMaximalCdar());
if (!is_string($cdarXml)) {
	fwrite(STDERR, "the CDAR builder returned no document\n");
	exit(1);
}
foreach (array_keys(einvoicingDocumentPaths($cdarXml)) as $path) {
	$current['CDAR ' . $path] = true;
}
$current = array_keys($current);
sort($current);

if ($updateBaseline) {
	file_put_contents($baselineFile, implode("\n", $current) . "\n");
	echo count($current) . " path(s) written to " . basename($baselineFile) . "\n";
	exit(0);
}

if (!is_file($baselineFile)) {
	fwrite(STDERR, "no baseline yet: run once with --update-baseline\n");
	exit(2);
}
$baseline = array_values(array_filter(array_map('trim', (array) file($baselineFile)), 'strlen'));
$lost = array_values(array_diff($baseline, $current));
$gained = array_values(array_diff($current, $baseline));

if ($gained) {
	echo count($gained) . " path(s) the builder writes and did not write before:\n";
	foreach ($gained as $path) {
		echo '    ' . $path . "\n";
	}
	echo "\n";
}
if ($lost) {
	echo "The builder no longer writes " . count($lost) . " path(s) it used to:\n";
	foreach ($lost as $path) {
		echo '    ' . $path . "\n";
	}
	echo "\nEvery one of those is a capability leaving the module: no document turns invalid and no\n";
	echo "other test goes red, the invoices that needed it just stop carrying it. If that is what the\n";
	echo "change means to do, say so in the pull request and rerun with --update-baseline.\n";
	exit(1);
}

echo count($current) . " path(s) written, across " . count(CIIProtocol::SUPPORTED_XML_PROFILES) . " invoice profiles and the CDAR; none lost.\n";
exit(0);
