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
 *      \file       test/phpunit/PrepaidAmountFromCoreTest.php
 *      \ingroup    test
 *      \brief      The document declares as already paid what the core counts as already paid.
 *      \remarks    BT-113 is what the customer has already given and BT-115 what is left to give.
 *                  Dolibarr has one definition of the first, the one getRemainToPay() applies from 18 to 24.
 *                  Each shape a discount can take is built here (excess received, credit note, deposit on
 *                  the remain to pay, deposit as a line) and BT-113/BT-115 must say what the core says.
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
require_once DOL_DOCUMENT_ROOT . '/core/class/discount.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';


/**
 * Class PrepaidAmountFromCoreTest
 *
 * Builds an invoice carrying a discount of each shape and checks the settlement of the generated
 * document against what the core says has been paid and what it says remains.
 */
class PrepaidAmountFromCoreTest extends CommonClassTest
{
	const RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

	/** @var float	Net amount of the single line of the invoice under test */
	const LINE_HT = 200.00;
	/** @var float	VAT rate of every line built here */
	const VAT_RATE = 20.0;
	/** @var float	Gross amount of the invoice under test, before any discount */
	const INVOICE_TTC = 240.00;
	/** @var float	Net amount of the invoice the discount is born from */
	const SOURCE_HT = 25.00;
	/** @var float	Gross amount of the discount, the amount already in the seller's hands */
	const DISCOUNT_TTC = 30.00;

	/**
	 * Pin the seller and the setup the generation needs, for the duration of one build. $mysoc is a
	 * global object: pinning it changes nothing in the database, and the caller undoes it.
	 *
	 * @return	array	The values to hand back to restoreEnvironment()
	 */
	private function pinEnvironment()
	{
		global $conf, $mysoc;

		$saved = array(
			'idprof1' => $mysoc->idprof1,
			'idprof2' => $mysoc->idprof2,
			'tva_intra' => $mysoc->tva_intra,
			'country_id' => $mysoc->country_id,
			'country_code' => $mysoc->country_code,
			'EINVOICING_PDP' => getDolGlobalString('EINVOICING_PDP'),
		);

		$mysoc->idprof1 = '000000001';
		$mysoc->idprof2 = '00000000100010';
		$mysoc->tva_intra = 'FR12000000001';
		$mysoc->country_id = 1;
		$mysoc->country_code = 'FR';
		$conf->global->EINVOICING_PDP = 'SPECIMEN';

		return $saved;
	}

	/**
	 * Undo pinEnvironment().
	 *
	 * @param	array	$saved	What pinEnvironment() returned
	 * @return	void
	 */
	private function restoreEnvironment($saved)
	{
		global $conf, $mysoc;

		$conf->global->EINVOICING_PDP = $saved['EINVOICING_PDP'];
		unset($saved['EINVOICING_PDP']);
		foreach ($saved as $property => $value) {
			$mysoc->$property = $value;
		}
	}

	/**
	 * Create the customer of the case.
	 *
	 * @param	User	$user	The user acting
	 * @return	Societe			The created third party
	 */
	private function createBuyer($user)
	{
		global $db;

		$buyer = new Societe($db);
		$buyer->name = 'EINVOICING TEST PREPAID BUYER';
		$buyer->client = 1;
		// Some numbering modules refuse a third party without a code, and giving one costs nothing
		// where the code is generated instead.
		$buyer->code_client = 'EINVPP' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
		$buyer->address = '2 rue du Test';
		$buyer->zip = '75000';
		$buyer->town = 'Paris';
		$buyer->country_id = 1;			// France
		$buyer->country_code = 'FR';
		$buyer->idprof1 = '000000002';
		$buyer->idprof2 = '00000000200010';
		$buyer->tva_intra = 'FR12000000002';
		$this->assertGreaterThan(0, $buyer->create($user), 'the buyer of the case is created: ' . $buyer->error);

		return $buyer;
	}

	/**
	 * Create a one-line invoice of the given type.
	 *
	 * @param	User	$user		The user acting
	 * @param	int		$socid		Third party of the invoice
	 * @param	int		$type		One of the Facture::TYPE_* constants
	 * @param	float	$amountHt	Net amount of the single line
	 * @return	Facture				The created invoice
	 */
	private function createInvoice($user, $socid, $type, $amountHt)
	{
		global $db;

		$invoice = new Facture($db);
		$invoice->socid = $socid;
		$invoice->type = $type;
		$invoice->date = dol_now();
		$this->assertGreaterThan(0, $invoice->create($user), 'the invoice of type ' . $type . ' is created: ' . $invoice->error);

		$lineid = $invoice->addline('E-invoicing prepaid amount test line', $amountHt, 1, self::VAT_RATE);
		$this->assertGreaterThan(0, $lineid, 'the line of the invoice of type ' . $type . ' is added: ' . $invoice->error);

		return $invoice;
	}

	/**
	 * Turn an invoice into an available discount, with the fields compta/facture/card.php fills in
	 * its "confirm_converttoreduc" action.
	 *
	 * @param	User	$user			The user acting
	 * @param	Facture	$source			The invoice the discount is born from
	 * @param	string	$description	'(EXCESS RECEIVED)', '(CREDIT_NOTE)' or '(DEPOSIT)'
	 * @param	float	$amountHt		Net amount of the discount
	 * @param	float	$amountTva		VAT amount of the discount
	 * @return	DiscountAbsolute		The created discount
	 */
	private function createDiscountFrom($user, $source, $description, $amountHt, $amountTva)
	{
		global $db;

		$discount = new DiscountAbsolute($db);
		$discount->description = $description;
		$discount->fk_soc = $source->socid;
		// The property is named socid from Dolibarr 21 and fk_soc before: card.php sets both, but the
		// one that does not exist yet would be a dynamic property, which PHP 8.2 deprecates.
		if (property_exists($discount, 'socid')) {
			$discount->socid = $source->socid;
		}
		$discount->fk_facture_source = $source->id;
		$discount->amount_ht = $amountHt;
		$discount->amount_tva = $amountTva;
		$discount->amount_ttc = $amountHt + $amountTva;
		$discount->tva_tx = ($amountTva > 0) ? self::VAT_RATE : 0;

		$this->assertGreaterThan(0, $discount->create($user), 'the discount ' . $description . ' is created: ' . $discount->error);

		return $discount;
	}

	/**
	 * Read one amount of the document settlement.
	 *
	 * @param	string	$xml	The generated document
	 * @param	string	$name	Element name, under ram:SpecifiedTradeSettlementHeaderMonetarySummation
	 * @return	float			The amount, 0 when the element is absent
	 */
	private function summation($xml, $name)
	{
		$doc = new DOMDocument();
		$this->assertTrue($doc->loadXML($xml), 'the generated document is well formed XML');

		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('ram', self::RAM);

		$found = $xpath->query('//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:' . $name);

		return ($found->length > 0) ? (float) $found->item(0)->textContent : 0.0;
	}

	/**
	 * Read the references the document carries (BT-25), as a list of IssuerAssignedID.
	 *
	 * @param	string	$xml	The generated document
	 * @return	string[]		The referenced document identifiers
	 */
	private function referencedDocuments($xml)
	{
		$doc = new DOMDocument();
		$this->assertTrue($doc->loadXML($xml), 'the generated document is well formed XML');

		$xpath = new DOMXPath($doc);
		$xpath->registerNamespace('ram', self::RAM);

		$refs = array();
		foreach ($xpath->query('//ram:InvoiceReferencedDocument/ram:IssuerAssignedID') as $node) {
			$refs[] = $node->textContent;
		}

		return $refs;
	}

	/**
	 * Build one case and generate its document.
	 *
	 * Each call builds its own invoices: the base class backs static attributes up, and the objects
	 * held here carry a database handler.
	 *
	 * @param	string	$case	'excess', 'creditnote', 'depositonremaintopay' or 'depositasline'
	 * @return	array{invoice:Facture,sourceref:string,xml:string}	The invoice, read back, the invoice the discount comes from, and the document
	 */
	private function buildCase($case)
	{
		global $db, $langs;

		$user = new User($db);
		$this->assertGreaterThan(0, $user->fetch(1), 'the instance has a user to act as');

		$saved = $this->pinEnvironment();

		try {
			$buyer = $this->createBuyer($user);

			// The invoice under test: 200.00 + 20 % = 240.00, exactly the bench measurement.
			$invoice = $this->createInvoice($user, $buyer->id, Facture::TYPE_STANDARD, self::LINE_HT);

			// The invoice the discount is born from: only its TYPE matters, the core joins on it. An
			// excess received is written in gross amount with no VAT, a credit note and a deposit with.
			if ($case == 'excess') {
				$source = $this->createInvoice($user, $buyer->id, Facture::TYPE_STANDARD, self::LINE_HT);
				$discount = $this->createDiscountFrom($user, $source, '(EXCESS RECEIVED)', self::DISCOUNT_TTC, 0);
			} elseif ($case == 'creditnote') {
				$source = $this->createInvoice($user, $buyer->id, Facture::TYPE_CREDIT_NOTE, self::SOURCE_HT);
				$discount = $this->createDiscountFrom($user, $source, '(CREDIT_NOTE)', self::SOURCE_HT, self::DISCOUNT_TTC - self::SOURCE_HT);
			} else {
				$source = $this->createInvoice($user, $buyer->id, Facture::TYPE_DEPOSIT, self::SOURCE_HT);
				$discount = $this->createDiscountFrom($user, $source, '(DEPOSIT)', self::SOURCE_HT, self::DISCOUNT_TTC - self::SOURCE_HT);
			}

			if ($case == 'depositasline') {
				// The ordinary way: insert_discount() ends with link_to_invoice($lineid, 0), which fills
				// fk_facture_line - the core counts nothing as paid, the invoice total is lower instead.
				$this->assertGreaterThan(0, $invoice->insert_discount($discount->id), 'the deposit is consumed as a line of the invoice: ' . $invoice->error);
			} else {
				// The other way: link_to_invoice(0, $id) fills fk_facture and creates no line at all.
				$this->assertGreaterThan(0, $discount->link_to_invoice(0, $invoice->id), 'the discount is applied to the remain to pay: ' . $discount->error);
			}

			$reloaded = new Facture($db);
			$this->assertGreaterThan(0, $reloaded->fetch($invoice->id), 'the invoice under test is read back');
			$reloaded->fetch_lines();
			$reloaded->fetch_thirdparty();

			$protocol = new CIIProtocol($db);
			$path = $protocol->generateXML($reloaded, $langs);
			$this->assertNotEmpty($path, 'the document of the invoice is generated: ' . $protocol->error . ' ' . implode(', ', (array) $protocol->errors));
			$this->assertFileExists((string) $path, 'the generated document is written');

			// Generation caches amounts into the object it was handed: read the reference values from a
			// fresh one, so the core is asked and not a leftover.
			$reference = new Facture($db);
			$this->assertGreaterThan(0, $reference->fetch($invoice->id), 'the invoice is read back to ask the core what is paid');

			return array(
				'invoice' => $reference,
				'sourceref' => $source->ref,
				'xml' => (string) file_get_contents((string) $path),
			);
		} finally {
			$this->restoreEnvironment($saved);
		}
	}

	/**
	 * What the core counts as already paid: what the invoice bills less what getRemainToPay() leaves.
	 *
	 * @param	Facture	$invoice	The invoice, freshly read
	 * @return	float				The amount already in the seller's hands
	 */
	private function alreadyPaidAccordingToTheCore($invoice)
	{
		return (float) $invoice->total_ttc - (float) $invoice->getRemainToPay();
	}

	/**
	 * The settlement of the document says what the core says, whichever shape the discount takes.
	 * Before the fix 'excess' and 'depositonremaintopay' read 0.00 prepaid against a core saying 30.00,
	 * while 'creditnote' (30.00) and 'depositasline' (0.00, carried by the lines) must not move.
	 *
	 * @return void
	 */
	public function testTheDocumentDeclaresWhatTheCoreCountsAsPaid()
	{
		foreach (array('excess', 'creditnote', 'depositonremaintopay', 'depositasline') as $case) {
			$built = $this->buildCase($case);
			$invoice = $built['invoice'];
			$xml = $built['xml'];

			$this->assertEqualsWithDelta(
				$this->alreadyPaidAccordingToTheCore($invoice),
				$this->summation($xml, 'TotalPrepaidAmount'),
				0.011,
				'case ' . $case . ': BT-113 states what the core counts as already paid'
			);
			$this->assertEqualsWithDelta(
				(float) $invoice->getRemainToPay(),
				$this->summation($xml, 'DuePayableAmount'),
				0.011,
				'case ' . $case . ': BT-115 asks for what the core says remains to pay'
			);
			$this->assertEqualsWithDelta(
				(float) $invoice->total_ttc,
				$this->summation($xml, 'GrandTotalAmount'),
				0.011,
				'case ' . $case . ': BT-112 states the gross amount the invoice bills'
			);
		}
	}

	/**
	 * The bench measurement in figures, so that a core counting something else one day cannot make
	 * this file agree with it silently.
	 *
	 * @return void
	 */
	public function testTheExcessReceivedIsWorthItsAmount()
	{
		$built = $this->buildCase('excess');
		$invoice = $built['invoice'];
		$xml = $built['xml'];

		$this->assertEqualsWithDelta(
			self::INVOICE_TTC,
			(float) $invoice->total_ttc,
			0.011,
			'the invoice under test bills 240.00, as on the bench'
		);
		$this->assertEqualsWithDelta(
			self::INVOICE_TTC - self::DISCOUNT_TTC,
			(float) $invoice->getRemainToPay(),
			0.011,
			'the core says 210.00 remain once the 30.00 excess received is applied'
		);
		$this->assertEqualsWithDelta(
			self::DISCOUNT_TTC,
			$this->summation($xml, 'TotalPrepaidAmount'),
			0.011,
			'BT-113 declares the 30.00 already received - it declared 0.00 before the fix'
		);
		$this->assertEqualsWithDelta(
			self::INVOICE_TTC - self::DISCOUNT_TTC,
			$this->summation($xml, 'DuePayableAmount'),
			0.011,
			'BT-115 asks for 210.00 - it asked for 240.00 before the fix'
		);
	}

	/**
	 * A deposit consumed as a line is carried by the lines, not by BT-113: counting it here as well
	 * would deduct it twice.
	 *
	 * @return void
	 */
	public function testTheDepositOfALineIsNotCountedTwice()
	{
		$built = $this->buildCase('depositasline');
		$invoice = $built['invoice'];
		$xml = $built['xml'];

		$this->assertEqualsWithDelta(
			self::INVOICE_TTC - self::DISCOUNT_TTC,
			(float) $invoice->total_ttc,
			0.011,
			'the deposit line already brought the invoice down to 210.00'
		);
		$this->assertEqualsWithDelta(
			0.0,
			$this->summation($xml, 'TotalPrepaidAmount'),
			0.011,
			'BT-113 stays at 0.00: the deposit is in the lines, it is not a prepayment of this document'
		);
		$this->assertEqualsWithDelta(
			self::INVOICE_TTC - self::DISCOUNT_TTC,
			$this->summation($xml, 'DuePayableAmount'),
			0.011,
			'BT-115 asks for 210.00 and not for 180.00'
		);
	}

	/**
	 * The invoice a discount comes from is referenced (BT-25), an excess received included.
	 *
	 * @return void
	 */
	public function testTheInvoiceTheDiscountComesFromIsReferenced()
	{
		foreach (array('excess', 'creditnote', 'depositonremaintopay') as $case) {
			$built = $this->buildCase($case);

			$this->assertContains(
				$built['sourceref'],
				$this->referencedDocuments($built['xml']),
				'case ' . $case . ': the document refers to the invoice the discount was born from'
			);
		}
	}
}
