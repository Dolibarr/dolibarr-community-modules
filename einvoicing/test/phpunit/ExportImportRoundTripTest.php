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
 *      \file       test/phpunit/ExportImportRoundTripTest.php
 *      \ingroup    test
 *      \brief      What the module writes, the module reads back the same.
 *      \remarks    Generation and import are tested apart, and the gap between them is where a whole
 *                  family of defects lived (#726, #731, #742). This generates an invoice, imports the
 *                  document it produced as a supplier invoice and requires the two to state the same
 *                  lines, quantities, prices, rates and totals. The seller of a generated document is
 *                  $mysoc, so the supplier created here carries the identifiers pinned on it.
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
 * Class ExportImportRoundTripTest
 *
 * Generates an invoice, imports the document back, and compares the two.
 */
class ExportImportRoundTripTest extends CommonClassTest
{
	/**
	 * The lines of the invoice: one per shape a document has to survive.
	 * Each is array(description, unit price, quantity, VAT rate, discount percent, type).
	 * @var array<int,array{0:string,1:float,2:float,3:float,4:float,5:int}>
	 */
	const LINES = array(
		array('Widget', 12.50, 3, 20.0, 10.0, 0),			// a discounted product line
		array('Service', 100.00, 1, 5.5, 0.0, 1),			// a service, at another rate
		array('Nothing done yet', 42.00, 0, 20.0, 0.0, 0),	// a line without a quantity (#726)
		array('Ampersand & quote " and accents cafe', 10.00, 1, 20.0, 0.0, 0),
	);

	/**
	 * The invoice and the supplier invoice read back from its document, built once for the class.
	 * @var array{invoice:Facture,imported:FactureFournisseur}|null
	 */
	private static $roundTrip = null;

	/**
	 * A code the numbering module of the instance accepts: some of them refuse a third party without
	 * one, and refuse a short one.
	 *
	 * @param	string	$prefix		Three letters telling the two parties apart in a failure message
	 * @return	string				A code no other third party carries
	 */
	private function thirdpartyCode($prefix)
	{
		return 'EINV' . $prefix . strtoupper(substr(md5(uniqid('', true)), 0, 6));
	}

	/**
	 * A legal identity no other third party of the instance carries.
	 *
	 * The import attaches a received document to a third party by structured identifier, and it
	 * refuses - rightly - to choose between two that carry the same one. The round trip is built
	 * more than once in a run, so each build gets its own identity rather than leaving the second
	 * one to fail on a duplicate this file created itself.
	 *
	 * @return array{siren:string,siret:string,vat:string}	SIREN, SIRET and VAT number of one party
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
	 * Generate an invoice, import the document it produces, and return both.
	 *
	 * @return array{invoice:Facture,imported:FactureFournisseur}
	 */
	private function roundTrip()
	{
		global $conf, $db, $langs, $mysoc, $user;

		if (self::$roundTrip !== null) {
			return self::$roundTrip;
		}

		// The import writes as the user who is logged in, and reads that user from the global. A test
		// process has none: the invoice is then written with an author of 0, which the foreign key of
		// llx_facture_fourn refuses on the older cores and which makes the update of the third party
		// fail on others. So the global is the one this acts as, and it is put back at the end.
		$savUser = $user;
		$user = new User($db);
		$this->assertGreaterThan(0, $user->fetch(1), 'the instance has a user to act as');

		$savPdp = getDolGlobalString('EINVOICING_PDP');
		$conf->global->EINVOICING_PDP = 'SPECIMEN';
		// The lines sent here are free text, as most lines of a real invoice are: the import is pinned
		// to take them as they come rather than matching or creating a product. Restored below.
		$savFreeLines = getDolGlobalString('EINVOICING_IMPORT_AS_FREE_LINES');
		$conf->global->EINVOICING_IMPORT_AS_FREE_LINES = 1;

		// The identifiers of the seller decide which third party the import attaches the document
		// to, and a demo company whose SIREN is "123456" stops the generation before that. $mysoc is
		// a global object: pinning it changes nothing in the database, and it is restored below.
		$savSeller = array(
			'idprof1' => $mysoc->idprof1,
			'idprof2' => $mysoc->idprof2,
			'tva_intra' => $mysoc->tva_intra,
			'country_id' => $mysoc->country_id,
			'country_code' => $mysoc->country_code,
		);
		$sellerIdentity = $this->legalIdentity();
		$buyerIdentity = $this->legalIdentity();
		$mysoc->idprof1 = $sellerIdentity['siren'];
		$mysoc->idprof2 = $sellerIdentity['siret'];
		$mysoc->tva_intra = $sellerIdentity['vat'];
		$mysoc->country_id = 1;
		$mysoc->country_code = 'FR';

		try {
			// The company of the instance, seen from the other side: this is what the import resolves
			// the seller of the document to, by structured identifier.
			$supplier = new Societe($db);
			$supplier->name = 'EINVOICING ROUND TRIP SELLER';
			$supplier->fournisseur = 1;
			$supplier->code_fournisseur = $this->thirdpartyCode('RTF');
			$supplier->address = '1 rue du Test';
			$supplier->zip = '75000';
			$supplier->town = 'Paris';
			$supplier->country_id = 1;
			$supplier->country_code = 'FR';
			// Some instances - the demo of Dolibarr 22 among them - make the accountancy code of a
			// supplier mandatory, and the import updates the third party it attaches the document to.
			$supplier->accountancy_code_buy = '401EINVCI';
			$supplier->idprof1 = $sellerIdentity['siren'];
			$supplier->idprof2 = $sellerIdentity['siret'];
			$supplier->tva_intra = $sellerIdentity['vat'];
			$this->assertGreaterThan(0, $supplier->create($user), 'the seller third party is created: ' . $supplier->error . ' ' . implode(', ', (array) $supplier->errors));

			$buyer = new Societe($db);
			$buyer->name = 'EINVOICING ROUND TRIP BUYER';
			$buyer->client = 1;
			$buyer->code_client = $this->thirdpartyCode('RTC');
			$buyer->address = '2 rue du Test';
			$buyer->zip = '75000';
			$buyer->town = 'Paris';
			$buyer->country_id = 1;
			$buyer->country_code = 'FR';
			$buyer->accountancy_code_sell = '411EINVCI';
			$buyer->idprof1 = $buyerIdentity['siren'];
			$buyer->idprof2 = $buyerIdentity['siret'];
			$buyer->tva_intra = $buyerIdentity['vat'];
			$this->assertGreaterThan(0, $buyer->create($user), 'the buyer third party is created: ' . $buyer->error . ' ' . implode(', ', (array) $buyer->errors));

			$invoice = new Facture($db);
			$invoice->socid = $buyer->id;
			$invoice->type = Facture::TYPE_STANDARD;
			$invoice->date = dol_now();
			$this->assertGreaterThan(0, $invoice->create($user), 'the invoice is created: ' . $invoice->error);

			foreach (self::LINES as $index => $line) {
				list($description, $price, $qty, $rate, $discount, $type) = $line;
				$added = $invoice->addline($description, $price, $qty, $rate, 0, 0, 0, $discount, '', '', 0, 0, 0, 'HT', 0, $type);
				$this->assertGreaterThan(0, $added, 'line ' . ($index + 1) . ' is added: ' . $invoice->error);
			}

			$reloaded = new Facture($db);
			$this->assertGreaterThan(0, $reloaded->fetch($invoice->id), 'the invoice is read back');
			$reloaded->fetch_lines();
			$reloaded->fetch_thirdparty();

			$protocol = new CIIProtocol($db);
			$path = $protocol->generateXML($reloaded, $langs);
			$this->assertNotEmpty($path, 'the document is generated: ' . $protocol->error . ' ' . implode(', ', (array) $protocol->errors));
			$this->assertFileExists((string) $path, 'the generated document is written');

			$result = $protocol->createSupplierInvoiceFromSource((string) file_get_contents((string) $path), basename((string) $path));
			$importedId = is_array($result) ? (int) ($result['res'] ?? 0) : (int) $result;
			$answer = is_array($result) ? trim(strip_tags((string) ($result['message'] ?? ''))) : '';
			$this->assertGreaterThan(
				0,
				$importedId,
				'the document the module wrote is read back by the module: ' . $answer . ' ' . $protocol->error . ' ' . implode(', ', (array) $protocol->errors)
			);

			$imported = new FactureFournisseur($db);
			$this->assertGreaterThan(0, $imported->fetch($importedId), 'the imported invoice is read back');
			$imported->fetch_lines();

			self::$roundTrip = array('invoice' => $reloaded, 'imported' => $imported);

			return self::$roundTrip;
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
	 * Every line of the invoice is a line of the document, and comes back.
	 *
	 * A line that carries no quantity is the case of #726: it is a line of the invoice like any
	 * other, and dropping it on the way in makes the two documents disagree without anything saying
	 * so.
	 *
	 * @return void
	 */
	public function testEveryLineComesBack()
	{
		$roundTrip = $this->roundTrip();

		$this->assertCount(
			count($roundTrip['invoice']->lines),
			$roundTrip['imported']->lines,
			'the imported invoice has as many lines as the one that was sent'
		);
	}

	/**
	 * A line comes back with the quantity, the unit price, the rate and the net amount it left with.
	 *
	 * @return void
	 */
	public function testALineKeepsItsAmounts()
	{
		$roundTrip = $this->roundTrip();
		$sent = $roundTrip['invoice']->lines;
		$read = $roundTrip['imported']->lines;

		foreach ($sent as $index => $line) {
			$this->assertArrayHasKey($index, $read, 'line ' . ($index + 1) . ' is in the imported invoice');
			$where = 'line ' . ($index + 1) . ' (' . dol_trunc((string) $line->desc, 30) . ')';

			$this->assertEqualsWithDelta((float) $line->qty, (float) $read[$index]->qty, 0.0011, $where . ': the quantity');
			$this->assertEqualsWithDelta((float) $line->tva_tx, (float) $read[$index]->tva_tx, 0.0011, $where . ': the VAT rate');
			$this->assertEqualsWithDelta((float) $line->total_ht, (float) $read[$index]->total_ht, 0.011, $where . ': the net amount');
		}
	}

	/**
	 * The totals of the imported invoice are the totals of the invoice that was sent.
	 *
	 * @return void
	 */
	public function testTheTotalsComeBack()
	{
		$roundTrip = $this->roundTrip();
		$sent = $roundTrip['invoice'];
		$read = $roundTrip['imported'];

		$this->assertEqualsWithDelta((float) $sent->total_ht, (float) $read->total_ht, 0.011, 'the net total');
		$this->assertEqualsWithDelta((float) $sent->total_tva, (float) $read->total_tva, 0.011, 'the VAT total');
		$this->assertEqualsWithDelta((float) $sent->total_ttc, (float) $read->total_ttc, 0.011, 'the gross total');
	}
}
