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
 *      \file       test/phpunit/CreditNoteSourceLinkTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the link (BT-25, fk_facture_source) of a received credit note to the
 *                  invoice it corrects, whichever of the two documents is imported first (#1234).
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// DOLIBARR_HTDOCS is honoured first so the module can be tested against any of the supported cores
// without moving the working copy.
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
dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('einvoicing/class/providers/PDPProviderManager.class.php');
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class CreditNoteSourceLinkTest
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class CreditNoteSourceLinkTest extends CommonClassTest
{
	const RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
	const RSM = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';

	/**
	 * @var	array{siren:string,siret:string,vat:string}	Legal identity of the seller of every document of a test
	 */
	private $seller;

	/**
	 * @var	Societe	Customer the documents are generated for
	 */
	private $buyer;

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
			$thirdparty->code_fournisseur = 'EINVCNF' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
			$thirdparty->accountancy_code_buy = '401EINVCN';
		} else {
			$thirdparty->client = 1;
			$thirdparty->code_client = 'EINVCNC' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
			$thirdparty->accountancy_code_sell = '411EINVCN';
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
	 * One seller and one buyer for the whole test, so its documents all come from the same vendor.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$this->seller = $this->legalIdentity();
		$this->createThirdparty('EINVOICING CREDIT NOTE SOURCE SELLER', 1, $this->seller);
		$this->buyer = $this->createThirdparty('EINVOICING CREDIT NOTE SOURCE BUYER', 0, $this->legalIdentity());
	}

	/**
	 * Generate a CII document of the seller of the test.
	 *
	 * @param	string		$typeCode	BT-3: 380 invoice, 381 credit note
	 * @param	string|null	$reference	BT-25 to write, null for none
	 * @return	array{xml:string,documentno:string}	The document and its BT-1
	 */
	private function document($typeCode, $reference = null)
	{
		global $db, $langs, $mysoc, $user;

		$mysoc->idprof1 = $this->seller['siren'];
		$mysoc->idprof2 = $this->seller['siret'];
		$mysoc->tva_intra = $this->seller['vat'];
		$mysoc->country_id = 1;
		$mysoc->country_code = 'FR';

		$invoice = new Facture($db);
		$invoice->socid = $this->buyer->id;
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
		$xpath->query('/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode')->item(0)->textContent = $typeCode;

		if ($reference !== null) {
			$settlement = $xpath->query('//ram:ApplicableHeaderTradeSettlement')->item(0);
			$node = $dom->createElementNS(self::RAM, 'ram:InvoiceReferencedDocument');
			$node->appendChild($dom->createElementNS(self::RAM, 'ram:IssuerAssignedID', $reference));
			$settlement->appendChild($node);
		}

		return array('xml' => (string) $dom->saveXML(), 'documentno' => $documentno);
	}

	/**
	 * Import a received document, as the synchronization does.
	 *
	 * @param	string	$xml	The document
	 * @return	int				Id of the supplier invoice the import created or found
	 */
	private function import($xml)
	{
		global $conf, $db, $mysoc, $user;

		$savUser = $user;
		$user = new User($db);
		$user->fetch(1);
		$savPdp = getDolGlobalString('EINVOICING_PDP');
		$conf->global->EINVOICING_PDP = 'SPECIMEN';
		$savFreeLines = getDolGlobalString('EINVOICING_IMPORT_AS_FREE_LINES');
		$conf->global->EINVOICING_IMPORT_AS_FREE_LINES = 1;
		$savSeller = array('idprof1' => $mysoc->idprof1, 'idprof2' => $mysoc->idprof2, 'tva_intra' => $mysoc->tva_intra);

		try {
			$protocol = new CIIProtocol($db);
			$result = $protocol->createSupplierInvoiceFromSource($xml, 'source-link.xml');
			$this->assertGreaterThan(0, (int) ($result['res'] ?? 0), 'the document is imported: ' . ($result['message'] ?? '') . ' ' . $protocol->error);

			return (int) $result['res'];
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
	 * @param	int	$id		Supplier invoice
	 * @return	int			Its fk_facture_source, read from the database
	 */
	private function sourceOf($id)
	{
		global $db;

		$invoice = new FactureFournisseur($db);
		$this->assertGreaterThan(0, $invoice->fetch($id));

		return (int) $invoice->fk_facture_source;
	}

	/**
	 * Scenario B of #1234: the credit note arrives before the invoice it corrects. It is imported
	 * without a link, and gets it when that invoice is imported.
	 *
	 * @return void
	 */
	public function testACreditNoteImportedFirstIsLinkedWhenItsInvoiceArrives()
	{
		$invoice = $this->document('380');
		$creditNote = $this->document('381', $invoice['documentno']);

		$creditNoteId = $this->import($creditNote['xml']);
		$this->assertSame(0, $this->sourceOf($creditNoteId), 'no source yet: the invoice is not there');

		$invoiceId = $this->import($invoice['xml']);
		$this->assertSame($invoiceId, $this->sourceOf($creditNoteId), 'the credit note now corrects the invoice that arrived');
	}

	/**
	 * Scenario A of #1234: the credit note is already recorded without a link while its invoice exists.
	 * Importing it again sets the link instead of leaving it empty.
	 *
	 * @return void
	 */
	public function testACreditNoteImportedAgainGetsTheLinkItLacks()
	{
		global $db;

		$invoice = $this->document('380');
		$creditNote = $this->document('381', $invoice['documentno']);

		$creditNoteId = $this->import($creditNote['xml']);
		$invoiceId = $this->import($invoice['xml']);

		// As recorded by a version that did not link afterwards, or keyed in by hand.
		$db->query("UPDATE " . $db->prefix() . "facture_fourn SET fk_facture_source = NULL WHERE rowid = " . ((int) $creditNoteId));
		$this->assertSame(0, $this->sourceOf($creditNoteId));

		$this->assertSame($creditNoteId, $this->import($creditNote['xml']), 'the import finds the credit note it already recorded');
		$this->assertSame($invoiceId, $this->sourceOf($creditNoteId), 'and gives it its source');
	}

	/**
	 * An invoice of another vendor carrying the same number is not the one the credit note corrects.
	 *
	 * @return void
	 */
	public function testTheInvoiceOfAnotherVendorWithTheSameNumberIsNotLinked()
	{
		$invoice = $this->document('380');
		$creditNote = $this->document('381', $invoice['documentno']);
		$creditNoteId = $this->import($creditNote['xml']);

		// Same number, other seller
		$this->seller = $this->legalIdentity();
		$this->createThirdparty('EINVOICING CREDIT NOTE SOURCE OTHER SELLER', 1, $this->seller);
		$other = $this->document('380');
		$otherXml = str_replace('<ram:ID>' . $other['documentno'] . '</ram:ID>', '<ram:ID>' . $invoice['documentno'] . '</ram:ID>', $other['xml']);
		$this->import($otherXml);

		$this->assertSame(0, $this->sourceOf($creditNoteId), 'the credit note keeps waiting for its own vendor\'s invoice');
	}
}
