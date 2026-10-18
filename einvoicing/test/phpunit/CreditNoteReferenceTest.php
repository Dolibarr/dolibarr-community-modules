<?php
/* Copyright (C) 2026 ATM Consulting
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
 *      \file       test/phpunit/CreditNoteReferenceTest.php
 *      \ingroup    test
 *      \brief      An unknown BG-3 reference holds an invoice that declares an amount paid, never a credit note.
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
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('einvoicing/class/providers/PDPProviderManager.class.php');
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';


/**
 * Class CreditNoteReferenceTest
 */
class CreditNoteReferenceTest extends CommonClassTest
{
	const RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
	const RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';

	/**
	 * @return array{siren:string,siret:string,vat:string}	A legal identity no other third party carries
	 */
	private function legalIdentity()
	{
		$siren = str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);

		return array(
			'siren' => $siren,
			'siret' => $siren . '00010',
			'vat' => 'FR' . str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT) . $siren,
		);
	}

	/**
	 * @param	string		$name		Name of the third party
	 * @param	int			$supplier	1 for a supplier, 0 for a customer
	 * @param	array{siren:string,siret:string,vat:string}	$identity	Its legal identity
	 * @return	Societe
	 */
	private function createThirdparty($name, $supplier, $identity)
	{
		global $db, $user;

		$thirdparty = new Societe($db);
		$thirdparty->name = $name;
		if ($supplier) {
			$thirdparty->fournisseur = 1;
			$thirdparty->code_fournisseur = 'EINVSRF' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
			$thirdparty->accountancy_code_buy = '401EINVSR';
		} else {
			$thirdparty->client = 1;
			$thirdparty->code_client = 'EINVSRC' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
			$thirdparty->accountancy_code_sell = '411EINVSR';
		}
		$thirdparty->address = '1 rue du Test';
		$thirdparty->zip = '75000';
		$thirdparty->town = 'Paris';
		$thirdparty->country_id = 1;
		$thirdparty->country_code = 'FR';
		$thirdparty->idprof1 = $identity['siren'];
		$thirdparty->idprof2 = $identity['siret'];
		$thirdparty->tva_intra = $identity['vat'];
		$this->assertGreaterThan(0, $thirdparty->create($user), $name . ' is created: ' . $thirdparty->error . ' ' . implode(', ', (array) $thirdparty->errors));

		return $thirdparty;
	}

	/**
	 * Generate a CII document carrying one BG-3 reference.
	 *
	 * @param	string|null	$reference	BT-25 to write, null for the document's own BT-1
	 * @param	string|null	$paidTypeCode	BT-3 to write on a document whose whole amount is already paid (BT-113), null to leave it unpaid
	 * @return	string					The document
	 */
	private function referencingDocument($reference = null, $paidTypeCode = null)
	{
		global $db, $langs, $mysoc, $user;

		$sellerIdentity = $this->legalIdentity();
		$this->createThirdparty('EINVOICING CREDIT NOTE REFERENCE SELLER', 1, $sellerIdentity);
		$buyer = $this->createThirdparty('EINVOICING CREDIT NOTE REFERENCE BUYER', 0, $this->legalIdentity());

		$mysoc->idprof1 = $sellerIdentity['siren'];
		$mysoc->idprof2 = $sellerIdentity['siret'];
		$mysoc->tva_intra = $sellerIdentity['vat'];
		$mysoc->country_id = 1;
		$mysoc->country_code = 'FR';

		$invoice = new Facture($db);
		$invoice->socid = $buyer->id;
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->date = dol_now();
		$this->assertGreaterThan(0, $invoice->create($user), 'the invoice is created: ' . $invoice->error);
		$this->assertGreaterThan(0, $invoice->addline('Parcel', 50.00, 1, 20.0), 'the line is added: ' . $invoice->error);

		$reloaded = new Facture($db);
		$reloaded->fetch($invoice->id);
		$reloaded->fetch_lines();
		$reloaded->fetch_thirdparty();

		$protocol = new CIIProtocol($db);
		$path = $protocol->generateXML($reloaded, $langs);
		$this->assertFileExists((string) $path, 'the document is generated: ' . $protocol->error);

		$dom = new DOMDocument();
		$dom->loadXML((string) file_get_contents((string) $path));
		$xpath = new DOMXPath($dom);
		$xpath->registerNamespace('rsm', self::RSM);
		$xpath->registerNamespace('ram', self::RAM);
		$documentno = $xpath->query('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID')->item(0)->textContent;
		$settlement = $xpath->query('//ram:ApplicableHeaderTradeSettlement')->item(0);

		$node = $dom->createElementNS(self::RAM, 'ram:InvoiceReferencedDocument');
		$node->appendChild($dom->createElementNS(self::RAM, 'ram:IssuerAssignedID', $reference ?? $documentno));
		$settlement->appendChild($node);

		if ($paidTypeCode !== null) {
			$xpath->query('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode')->item(0)->textContent = $paidTypeCode;
			$summation = $xpath->query('//ram:SpecifiedTradeSettlementHeaderMonetarySummation')->item(0);
			$grandTotal = $xpath->query('ram:GrandTotalAmount', $summation)->item(0)->textContent;
			$due = $xpath->query('ram:DuePayableAmount', $summation)->item(0);
			$prepaid = $xpath->query('ram:TotalPrepaidAmount', $summation)->item(0);
			if ($prepaid === null) {
				$prepaid = $dom->createElementNS(self::RAM, 'ram:TotalPrepaidAmount');
				$summation->insertBefore($prepaid, $due);
			}
			$prepaid->textContent = $grandTotal;
			$due->textContent = '0.00';
		}

		return (string) $dom->saveXML();
	}

	/**
	 * @param	string|null	$reference	BT-25 to write, null for the document's own BT-1
	 * @param	string|null	$paidTypeCode	BT-3 of a document whose whole amount is already paid, null to leave it unpaid
	 * @return	array<string,mixed>		What the import answered
	 */
	private function importReferencingDocument($reference, $paidTypeCode = null)
	{
		global $conf, $db, $mysoc, $user;

		$savUser = $user;
		$user = new User($db);
		$user->fetch(1);
		$savPdp = getDolGlobalString('EINVOICING_PDP');
		$conf->global->EINVOICING_PDP = 'SPECIMEN';
		$savFreeLines = getDolGlobalString('EINVOICING_IMPORT_AS_FREE_LINES');
		$conf->global->EINVOICING_IMPORT_AS_FREE_LINES = 1;
		$savSeller = array('idprof1' => $mysoc->idprof1, 'idprof2' => $mysoc->idprof2, 'tva_intra' => $mysoc->tva_intra, 'country_id' => $mysoc->country_id, 'country_code' => $mysoc->country_code);

		try {
			$document = $this->referencingDocument($reference, $paidTypeCode);

			$protocol = new CIIProtocol($db);
			$result = $protocol->createSupplierInvoiceFromSource($document, 'reference.xml');
			$result['message'] = (string) ($result['message'] ?? '') . ' ' . $protocol->error;

			return $result;
		} finally {
			$user = $savUser;
			$conf->global->EINVOICING_PDP = $savPdp;
			$conf->global->EINVOICING_IMPORT_AS_FREE_LINES = $savFreeLines;
			foreach ($savSeller as $property => $value) {
				$mysoc->$property = $value;
			}
		}
	}

	/**
	 * A credit note names the invoice it cancels or corrects, never a deposit: an amount already paid does not make it wait.
	 *
	 * @return void
	 */
	public function testAPaidCreditNoteWithAPlaceholderReferenceIsImported()
	{
		$result = $this->importReferencingDocument('NA', '381');

		$this->assertEmpty($result['postponeflow'] ?? null, 'the flow is not postponed: ' . $result['message']);
		$this->assertGreaterThan(0, (int) ($result['res'] ?? 0), 'the credit note is imported: ' . $result['message']);
		$this->assertStringContainsString('credit note', $result['message']);
	}

	/**
	 * An invoice declaring an amount already paid waits for the deposit it references (#880).
	 *
	 * @return void
	 */
	public function testAPaidInvoiceWaitsForItsMissingDeposit()
	{
		$result = $this->importReferencingDocument('EINV-MISSING-DEPOSIT', '380');

		$this->assertSame(1, (int) ($result['postponeflow'] ?? 0), 'the flow is postponed: ' . $result['message']);
	}
}
