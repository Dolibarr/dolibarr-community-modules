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
 *      \file       test/phpunit/ImportVatCalculationModeTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the totals an imported invoice carries.
 *                  The VAT calculation mode first (issue #781): BT-110 and BT-112 are the ones the
 *                  issuer computed, so the imported invoice has to carry them whatever the instance is
 *                  set to - "total of round" (mode 1, the default, rounding line by line) or "round of
 *                  total" (mode 2). Then BT-114 (issue #994): the rounding of the amount due travels on
 *                  a line of its own, so the invoice totals BT-115, what the issuer debits.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('einvoicing/class/providers/AbstractPDPProvider.class.php');
dol_include_once('einvoicing/class/providers/PDPProviderManager.class.php');
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
dol_include_once('einvoicing/class/utils/SupplierInvoiceHelper.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

if (empty($user->id)) {
	$user->fetch(1);
	// User::loadRights() only exists from Dolibarr 19 on, older versions name it getrights()
	if (method_exists($user, 'loadRights')) {
		$user->loadRights();
	} else {
		$user->getrights();
	}
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class ImportVatCalculationModeTest extends CommonClassTest
{
	/**
	 * The line net amounts of the fixture invoice, at 20 %: 2.10 of VAT line by line, 2.09 on the total.
	 */
	const LINE_AMOUNTS = array(1.09, 7.79, 1.59);

	/**
	 * The rate every line of the fixture carries, and the one its BG-23 announces.
	 *
	 * @var float
	 */
	const VAT_RATE = 20.0;

	/**
	 * The single line of the document of issue #994: 21.23 at 20 %, a total of 25.48 and 25.47 to pay.
	 */
	const ROUNDING_LINE_AMOUNT = 21.23;

	/**
	 * The identifiers the seller of the fixture of issue #994 carries, the ones the import resolves it by.
	 */
	const SELLER_SIREN = '999888779';
	const SELLER_SIRET = '99988877900017';
	const SELLER_VAT = 'FR34999888779';

	/**
	 * Ids of the invoices created by the tests, deleted at the end.
	 *
	 * @var int[]
	 */
	private $createdInvoiceIds = array();

	/**
	 * Ids of the third parties created by the tests, deleted at the end.
	 *
	 * @var int[]
	 */
	private $createdThirdpartyIds = array();

	/**
	 * The two names the core gives that setting: the _SUPPLIER variant only exists from Dolibarr 20 on,
	 * before that the generic one drives supplier documents as well.
	 *
	 * @var array<string,string|null>
	 */
	private $savedConstants = array();

	/**
	 * Remember the rounding setting of the instance, whichever of the two names carries it.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();

		$saved = array(
			'MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND_SUPPLIER',
			'MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND',
			// Whether the import creates a product for a line is another subject: the lines of the
			// fixture are imported as free ones, which every instance can do.
			'EINVOICING_IMPORT_AS_FREE_LINES',
			'EINVOICING_PRODUCTS_AUTO_GENERATION',
		);
		foreach ($saved as $name) {
			$this->savedConstants[$name] = isset($conf->global->$name) ? $conf->global->$name : null;
		}
		$conf->global->EINVOICING_IMPORT_AS_FREE_LINES = 1;
		$conf->global->EINVOICING_PRODUCTS_AUTO_GENERATION = 0;
	}

	/**
	 * Put the setting back and remove the invoices created by the test.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf, $db, $user;

		foreach ($this->savedConstants as $name => $value) {
			if ($value === null) {
				unset($conf->global->$name);
			} else {
				$conf->global->$name = $value;
			}
		}
		$this->savedConstants = array();

		foreach ($this->createdInvoiceIds as $id) {
			$invoice = new FactureFournisseur($db);
			if ($invoice->fetch($id) > 0) {
				$invoice->delete($user);
			}
		}
		$this->createdInvoiceIds = array();

		foreach ($this->createdThirdpartyIds as $id) {
			$thirdparty = new Societe($db);
			if ($thirdparty->fetch($id) > 0) {
				$thirdparty->delete($id, $user);
			}
		}
		$this->createdThirdpartyIds = array();

		parent::tearDown();
	}

	/**
	 * Set the VAT calculation mode of the instance, under both names so the test says the same thing
	 * on every supported version.
	 *
	 * @param	int		$mode	1 for "total of round", 2 for "round of total"
	 * @return	void
	 */
	private function setInstanceVatMode($mode)
	{
		global $conf;

		$value = ($mode == 2 ? '1' : '0');
		$conf->global->MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND_SUPPLIER = $value;
		$conf->global->MAIN_ROUNDOFTOTAL_NOT_TOTALOFROUND = $value;
	}

	/**
	 * A draft supplier invoice carrying the three lines of the fixture, built the way the import does:
	 * addline() ends on update_price(1, 'auto'), so the invoice comes out in the mode of the instance.
	 *
	 * @return	FactureFournisseur	The created invoice, freshly fetched
	 */
	private function createFixtureInvoice()
	{
		global $db, $user;

		$invoice = new FactureFournisseur($db);
		$invoice->initAsSpecimen();
		$invoice->lines = array();
		$invoice->ref_supplier = 'PR781' . strtoupper(bin2hex(random_bytes(5)));
		// addline() reads $this->special_code, a property the class does not declare on Dolibarr 18
		$invoice->special_code = 0;
		$id = $invoice->create($user);
		$this->assertGreaterThan(0, $id, $invoice->errorsToString());
		$this->createdInvoiceIds[] = $id;

		foreach (self::LINE_AMOUNTS as $amount) {
			// The first six arguments of addline() are the same from Dolibarr 18 to 24
			$res = $invoice->addline('Line of ' . $amount, $amount, self::VAT_RATE, 0, 0, 1);
			$this->assertGreaterThan(0, $res, $invoice->errorsToString());
		}

		$invoice->fetch($id);

		return $invoice;
	}

	/**
	 * Run the realignment on an invoice, as the import does once every line exists.
	 *
	 * @param	FactureFournisseur	$invoice		The invoice to confront with the document
	 * @param	float				$announcedTva	BT-110 of the document
	 * @param	float				$announcedTtc	BT-112 of the document
	 * @param	string[]			$messages		Messages of the import, completed by the call
	 * @return	FactureFournisseur					The invoice, re-read from the database
	 */
	private function alignWith(FactureFournisseur $invoice, $announcedTva, $announcedTtc, array &$messages)
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, 'alignInvoiceTotalsWithDocument');
		$method->setAccessible(true);
		$method->invokeArgs(
			new CIIProtocol($db),
			array(
				$invoice->id,
				array(
					'taxTotalAmount' => $announcedTva,
					'grandTotalAmount' => $announcedTtc,
					// BG-23 is mandatory in EN 16931, so a header without it is a shape no received
					// document has - and the import reads the VAT it announces from there.
					'taxBreakdown' => array(array(
						'typeCode' => 'VAT',
						'rateApplicablePercent' => self::VAT_RATE,
						'basisAmount' => round(array_sum(self::LINE_AMOUNTS), 2),
						'calculatedAmount' => $announcedTva,
					)),
				),
				&$messages
			)
		);

		$reread = new FactureFournisseur($db);
		$reread->fetch($invoice->id);

		return $reread;
	}

	/**
	 * The reported case: an instance left on mode 1, a document whose VAT was rounded on the total.
	 * The invoice must end up on the totals of the document, and say so.
	 *
	 * @return void
	 */
	public function testTheInvoiceCarriesTheTotalsTheDocumentAnnounces()
	{
		$this->setInstanceVatMode(1);

		$invoice = $this->createFixtureInvoice();
		$this->assertEquals(10.47, (float) $invoice->total_ht, 'the net amount is the same under both conventions');
		$this->assertEquals(2.10, (float) $invoice->total_tva, 'mode 1 rounds the VAT line by line');
		$this->assertEquals(12.57, (float) $invoice->total_ttc);

		$messages = array();
		$realigned = $this->alignWith($invoice, 2.09, 12.56, $messages);

		$this->assertEquals(10.47, (float) $realigned->total_ht, 'the net amount is untouched');
		$this->assertEquals(2.09, (float) $realigned->total_tva, 'the VAT is the one of the document');
		$this->assertEquals(12.56, (float) $realigned->total_ttc);
		$this->assertCount(1, $messages, 'the import says where the amounts come from');
		$this->assertStringContainsString('12.56', $messages[0], 'the total the document announces');
		$this->assertStringContainsString('the document', $messages[0], 'and where the amounts come from');
	}

	/**
	 * The net amount of every line stays what the document put on it: the core only ever moves a
	 * rounding difference onto the VAT of a line, so BT-131 and their sum BT-106 do not move.
	 *
	 * @return void
	 */
	public function testTheLineNetAmountsAreNotTouched()
	{
		$this->setInstanceVatMode(1);

		$invoice = $this->createFixtureInvoice();
		$before = array();
		foreach ($invoice->lines as $line) {
			$before[] = (float) $line->total_ht;
		}
		sort($before);

		$messages = array();
		$realigned = $this->alignWith($invoice, 2.09, 12.56, $messages);

		$after = array();
		foreach ($realigned->lines as $line) {
			$after[] = (float) $line->total_ht;
		}
		sort($after);

		$expected = self::LINE_AMOUNTS;
		sort($expected);

		$this->assertEquals($before, $after);
		$this->assertEquals($expected, $after, 'the line net amounts are the ones of the document');
	}

	/**
	 * An invoice that already totals what the document announces is left alone - no recalculation, no
	 * message.
	 *
	 * @return void
	 */
	public function testAnInvoiceThatAlreadyAgreesIsLeftAlone()
	{
		$this->setInstanceVatMode(1);

		$invoice = $this->createFixtureInvoice();

		$messages = array();
		$untouched = $this->alignWith($invoice, 2.10, 12.57, $messages);

		$this->assertEquals(2.10, (float) $untouched->total_tva);
		$this->assertEquals(12.57, (float) $untouched->total_ttc);
		$this->assertCount(0, $messages, 'nothing to say when the invoice already matches');
	}

	/**
	 * A difference neither convention explains is a document the import cannot reproduce. The invoice
	 * is left exactly as the import built it - nothing else carries what the vendor sent - but it is
	 * said, and the invoice is marked so it cannot be validated or approved (issue #861).
	 *
	 * @return void
	 */
	public function testADifferenceThatIsNotARoundingConventionIsReportedAndBlocks()
	{
		$this->setInstanceVatMode(1);

		$invoice = $this->createFixtureInvoice();

		$messages = array();
		$untouched = $this->alignWith($invoice, 3.00, 13.47, $messages);

		$this->assertEquals(10.47, (float) $untouched->total_ht);
		$this->assertEquals(2.10, (float) $untouched->total_tva, 'the invoice keeps the mode of the instance');
		$this->assertEquals(12.57, (float) $untouched->total_ttc);

		$this->assertCount(2, $messages, 'what the document announces, and what it means for that invoice');
		$this->assertStringContainsString('13.47', $messages[0], 'the total the document announces');
		$this->assertStringContainsString('12.57', $messages[0], 'against the one the invoice carries');

		$announced = SupplierInvoiceHelper::totalsMismatch((int) $invoice->id);
		$this->assertIsArray($announced, 'the invoice is marked');
		$this->assertEquals(13.47, $announced['ttc']);
		$this->assertTrue(SupplierInvoiceHelper::totalsMismatchBlocks((int) $invoice->id));

		// And the mark goes as soon as an import makes the invoice total the document again.
		$this->alignWith($invoice, 2.10, 12.57, $messages);
		$this->assertNull(SupplierInvoiceHelper::totalsMismatch((int) $invoice->id), 'nothing left to block');
	}

	/**
	 * The other direction, which is the reason the mode is not read from the setting: an instance on
	 * mode 2 receiving a document whose VAT was rounded line by line.
	 *
	 * @return void
	 */
	public function testAnInstanceOnMode2ReceivingADocumentRoundedLineByLine()
	{
		$this->setInstanceVatMode(2);

		$invoice = $this->createFixtureInvoice();
		$this->assertEquals(2.09, (float) $invoice->total_tva, 'mode 2 rounds the VAT on the total');

		$messages = array();
		$realigned = $this->alignWith($invoice, 2.10, 12.57, $messages);

		$this->assertEquals(10.47, (float) $realigned->total_ht);
		$this->assertEquals(2.10, (float) $realigned->total_tva);
		$this->assertEquals(12.57, (float) $realigned->total_ttc);
		$this->assertCount(1, $messages);
		$this->assertStringContainsString('12.57', $messages[0], 'the total the document announces');
		$this->assertStringContainsString('the document', $messages[0], 'and where the amounts come from');
	}

	/**
	 * A credit note is stored negative by Dolibarr while BT-110 and BT-112 are announced positive, the
	 * document type being what carries the sign. The comparison is therefore made on absolute values.
	 *
	 * @return void
	 */
	public function testTheComparisonIsMadeOnAbsoluteValues()
	{
		global $db;

		$creditNote = new FactureFournisseur($db);
		$creditNote->total_tva = -2.09;
		$creditNote->total_ttc = -12.56;
		$this->assertTrue(SupplierInvoiceHelper::totalsAgreeWithDocument($creditNote, 2.09, 12.56));

		$off = new FactureFournisseur($db);
		$off->total_tva = -2.10;
		$off->total_ttc = -12.57;
		$this->assertFalse(SupplierInvoiceHelper::totalsAgreeWithDocument($off, 2.09, 12.56));
	}

	/**
	 * The header of the reported document, with the parts every guard reads.
	 *
	 * @param	float	$rounding	BT-114 of the document
	 * @return	array<string,mixed>	The parsed header
	 */
	private function roundingHeader($rounding = -0.01)
	{
		return array(
			'lineTotalAmount' => self::ROUNDING_LINE_AMOUNT,
			'taxBasisTotalAmount' => self::ROUNDING_LINE_AMOUNT,
			'taxTotalAmount' => 4.25,
			'roundingAmount' => $rounding,
			'grandTotalAmount' => 25.48,
			'duePayableAmount' => round(25.48 + $rounding, 2),
			'taxBreakdown' => array(array(
				'typeCode' => 'VAT',
				'rateApplicablePercent' => self::VAT_RATE,
				'basisAmount' => self::ROUNDING_LINE_AMOUNT,
				'calculatedAmount' => 4.25,
			)),
		);
	}

	/**
	 * The anonymised document of the report, with a reference of its own so that a second run of the
	 * suite does not land on the invoice the first one created.
	 *
	 * @return string	The XML of the fixture
	 */
	private function fixtureXml()
	{
		$path = __DIR__ . '/fixtures/received_documents/cii_rounding_amount.xml';
		$xml = file_get_contents($path);
		$this->assertNotFalse($xml, 'the fixture is readable: ' . $path);

		return str_replace('EINV994-0001', 'EINV994-' . strtoupper(bin2hex(random_bytes(4))), (string) $xml);
	}

	/**
	 * The seller of the fixture, as an instance receiving that document already holds it.
	 *
	 * An instance that does not is a third party creation, which is another subject and another test:
	 * what is played here is what the import does with BT-114.
	 *
	 * @return	int		Id of the created third party
	 */
	private function createFixtureSupplier()
	{
		global $db, $user;

		$supplier = new Societe($db);
		$supplier->name = 'EINVOICING ROUNDING SELLER';
		$supplier->fournisseur = 1;
		$supplier->code_fournisseur = 'SU994' . strtoupper(bin2hex(random_bytes(4)));
		$supplier->address = '1 rue du Test';
		$supplier->zip = '75001';
		$supplier->town = 'Paris';
		$supplier->country_id = 1;
		$supplier->country_code = 'FR';
		// Some instances make the accountancy code of a supplier mandatory, and the import updates
		// the third party it attaches the document to.
		$supplier->accountancy_code_buy = '401EINV994';
		$supplier->idprof1 = self::SELLER_SIREN;
		$supplier->idprof2 = self::SELLER_SIRET;
		$supplier->tva_intra = self::SELLER_VAT;

		$id = $supplier->create($user);
		$this->assertGreaterThan(0, $id, 'the seller third party is created: ' . $supplier->error . ' ' . implode(', ', (array) $supplier->errors));
		$this->createdThirdpartyIds[] = (int) $id;

		return (int) $id;
	}

	/**
	 * A draft supplier invoice carrying the single line of the document, as the import builds it.
	 *
	 * @return	FactureFournisseur	The created invoice, freshly fetched
	 */
	private function createRoundingFixtureInvoice()
	{
		global $db, $user;

		$invoice = new FactureFournisseur($db);
		$invoice->initAsSpecimen();
		$invoice->lines = array();
		$invoice->ref_supplier = 'PR994' . strtoupper(bin2hex(random_bytes(5)));
		// addline() reads $this->special_code, a property the class does not declare on Dolibarr 18
		$invoice->special_code = 0;
		$id = $invoice->create($user);
		$this->assertGreaterThan(0, $id, $invoice->errorsToString());
		$this->createdInvoiceIds[] = $id;

		// The first six arguments of addline() are the same from Dolibarr 18 to 24
		$res = $invoice->addline('Subscriptions', self::ROUNDING_LINE_AMOUNT, self::VAT_RATE, 0, 0, 1);
		$this->assertGreaterThan(0, $res, $invoice->errorsToString());

		$invoice->fetch($id);

		return $invoice;
	}

	/**
	 * Add the rounding line to an invoice, the way the import does once every other line exists.
	 *
	 * @param	FactureFournisseur	$invoice		The invoice being imported
	 * @param	array<string,mixed>	$parsedHeader	Header of the received document
	 * @param	string[]			$messages		Messages of the import, completed by the call
	 * @return	FactureFournisseur					The invoice, re-read from the database
	 */
	private function addRoundingLine(FactureFournisseur $invoice, array $parsedHeader, array &$messages)
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, 'createRoundingLine');
		$method->setAccessible(true);
		$result = $method->invokeArgs(new CIIProtocol($db), array($invoice->id, $parsedHeader, &$messages));
		$this->assertEquals(1, $result['res'], isset($result['message']) ? $result['message'] : '');

		$reread = new FactureFournisseur($db);
		$reread->fetch($invoice->id);
		$reread->fetch_lines();

		return $reread;
	}

	/**
	 * Run the guard of the import on an invoice, the way the import does once every line exists.
	 *
	 * @param	FactureFournisseur	$invoice		The invoice to confront with its document
	 * @param	array<string,mixed>	$parsedHeader	Header of the received document
	 * @param	string[]			$messages		Messages of the import, completed by the call
	 * @return	void
	 */
	private function alignWithHeader(FactureFournisseur $invoice, array $parsedHeader, array &$messages)
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, 'alignInvoiceTotalsWithDocument');
		$method->setAccessible(true);
		$method->invokeArgs(new CIIProtocol($db), array($invoice->id, $parsedHeader, &$messages));
	}

	/**
	 * The invoice must total what the supplier debits, BT-115, and not BT-112.
	 *
	 * @return void
	 */
	public function testTheInvoiceTotalsTheAmountDue()
	{
		$invoice = $this->createRoundingFixtureInvoice();
		$this->assertEquals(25.48, (float) $invoice->total_ttc, 'the lines alone total BT-112');

		$messages = array();
		$withRounding = $this->addRoundingLine($invoice, $this->roundingHeader(), $messages);

		$this->assertEquals(21.22, (float) $withRounding->total_ht, 'the rounding is part of the net amount');
		$this->assertEquals(4.25, (float) $withRounding->total_tva, 'the VAT of the document is untouched');
		$this->assertEquals(25.47, (float) $withRounding->total_ttc, 'which is BT-115, what is debited');
		$this->assertCount(1, $messages, 'the import says it carried the rounding');
	}

	/**
	 * The line is marked, and the mark keeps it out of every taxable base: BT-116 and BT-117 are
	 * announced without it, so the rate of the document must still find its basis untouched.
	 *
	 * @return void
	 */
	public function testTheRoundingLineIsOutOfEveryTaxableBase()
	{
		$invoice = $this->createRoundingFixtureInvoice();
		$messages = array();
		$withRounding = $this->addRoundingLine($invoice, $this->roundingHeader(), $messages);

		$this->assertCount(2, $withRounding->lines, 'the rounding travels on a line of its own');
		$roundingLines = array_filter($withRounding->lines, function ($line) {
			return SupplierInvoiceHelper::isRoundingLine($line);
		});
		$this->assertCount(1, $roundingLines, 'and it is the only marked line');
		$rounding = reset($roundingLines);
		$this->assertEquals(-0.01, (float) $rounding->total_ht);
		$this->assertEquals(0, (float) $rounding->tva_tx, 'it bears no VAT of its own');

		$vatByRate = SupplierInvoiceHelper::getVatDetails($withRounding);
		$this->assertArrayHasKey('20', $vatByRate);
		$this->assertEquals(self::ROUNDING_LINE_AMOUNT, (float) $vatByRate['20']['vat_basis_amount'], 'BT-116 does not move');
		$this->assertArrayNotHasKey('0', $vatByRate, 'and the rounding opens no rate of its own');
	}

	/**
	 * The guard of the import (#863) has to agree with an invoice that totals BT-115.
	 *
	 * @return void
	 */
	public function testTheImportGuardAgreesWithTheRoundedTotal()
	{
		$invoice = $this->createRoundingFixtureInvoice();
		$messages = array();
		$withRounding = $this->addRoundingLine($invoice, $this->roundingHeader(), $messages);

		$header = $this->roundingHeader();
		$this->assertTrue(
			SupplierInvoiceHelper::totalsAgreeWithDocument($withRounding, 4.25, (float) SupplierInvoiceHelper::announcedTotalTtc($header)),
			'the invoice totals what the document says is due'
		);
		$this->assertFalse(
			SupplierInvoiceHelper::totalsAgreeWithDocument($withRounding, 4.25, (float) $header['grandTotalAmount']),
			'and no longer BT-112, which is the whole point'
		);
	}

	/**
	 * A document that rounds nothing is imported exactly as before: no line, no mark, no message.
	 *
	 * @return void
	 */
	public function testADocumentWithoutRoundingIsUntouched()
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, 'buildRoundingLine');
		$method->setAccessible(true);
		$protocol = new CIIProtocol($db);

		$header = $this->roundingHeader(0.0);
		$this->assertNull($method->invokeArgs($protocol, array($header)), 'a rounding amount of zero is none');

		unset($header['roundingAmount']);
		$this->assertNull($method->invokeArgs($protocol, array($header)), 'and an absent one even less');

		$invoice = $this->createRoundingFixtureInvoice();
		$messages = array();
		$untouched = $this->addRoundingLine($invoice, $header, $messages);
		$this->assertCount(1, $untouched->lines);
		$this->assertEquals(25.48, (float) $untouched->total_ttc);
		$this->assertCount(0, $messages);
	}

	/**
	 * What the invoice is expected to total, on the four shapes the documents take.
	 *
	 * @return void
	 */
	public function testTheExpectedTotalsFollowTheDocument()
	{
		$header = $this->roundingHeader();
		$this->assertEquals(-0.01, SupplierInvoiceHelper::documentRoundingAmount($header));
		$this->assertEquals(25.47, SupplierInvoiceHelper::announcedTotalTtc($header), 'BT-115 is what the invoice totals');
		$this->assertEquals(21.22, SupplierInvoiceHelper::announcedTotalHt($header), 'BT-109 plus the rounding');

		// BT-113 is deducted beside the invoice and not from its total, so it is added back
		$prepaid = $this->roundingHeader();
		$prepaid['totalPrepaidAmount'] = 10.0;
		$prepaid['duePayableAmount'] = 15.47;
		$this->assertEquals(25.47, SupplierInvoiceHelper::announcedTotalTtc($prepaid), 'the deposit does not lower the invoice');

		// A payable of the issuer's own invention must not move the guards
		$inconsistent = $this->roundingHeader(0.0);
		$inconsistent['duePayableAmount'] = 0.0;
		$this->assertEquals(25.48, SupplierInvoiceHelper::announcedTotalTtc($inconsistent), 'BR-CO-16 is what makes BT-115 readable');

		// A document announcing no total at all answers nothing rather than zero
		$this->assertNull(SupplierInvoiceHelper::announcedTotalTtc(array()));
		$this->assertNull(SupplierInvoiceHelper::announcedTotalHt(array()));
	}

	/**
	 * The document of the report, anonymised and kept as a fixture, read end to end.
	 *
	 * Parsing first: what the guards read has to be what the document carries, or the rest of this
	 * file proves nothing about a real document.
	 *
	 * @return void
	 */
	public function testTheReportedDocumentIsReadAsItWasSent()
	{
		global $db;

		$protocol = new CIIProtocol($db);
		$parsed = $protocol->parseInvoiceHeader($this->fixtureXml());

		$this->assertEquals(21.23, (float) $parsed['taxBasisTotalAmount'], 'BT-109');
		$this->assertEquals(4.25, (float) $parsed['taxTotalAmount'], 'BT-110');
		$this->assertEquals(-0.01, (float) $parsed['roundingAmount'], 'BT-114');
		$this->assertEquals(25.48, (float) $parsed['grandTotalAmount'], 'BT-112');
		$this->assertEquals(25.47, (float) $parsed['duePayableAmount'], 'BT-115');
		$this->assertEquals(25.47, SupplierInvoiceHelper::announcedTotalTtc($parsed), 'what the invoice must total');
		$this->assertEquals(21.22, SupplierInvoiceHelper::announcedTotalHt($parsed));
	}

	/**
	 * The same document, imported the way a synchronisation imports it: the invoice totals the 25.47
	 * its issuer debits, and nothing marks it as one the import could not reproduce.
	 *
	 * @return void
	 */
	public function testTheReportedDocumentImportsToTheAmountDue()
	{
		global $db;

		$this->createFixtureSupplier();

		$protocol = new CIIProtocol($db);
		$result = $protocol->createSupplierInvoiceFromSource($this->fixtureXml(), null, 'test994');
		$id = (int) (is_array($result) ? ($result['res'] ?? 0) : $result);
		$answer = is_array($result) ? trim(strip_tags((string) ($result['message'] ?? ''))) : '';
		$this->assertGreaterThan(0, $id, 'the document is imported: ' . $answer);
		$this->createdInvoiceIds[] = $id;

		$invoice = new FactureFournisseur($db);
		$this->assertGreaterThan(0, $invoice->fetch($id));
		$invoice->fetch_lines();

		$this->assertCount(2, $invoice->lines, 'the billed line and the rounding');
		$this->assertEquals(21.22, (float) $invoice->total_ht);
		$this->assertEquals(4.25, (float) $invoice->total_tva, 'the VAT of the document is untouched');
		$this->assertEquals(25.47, (float) $invoice->total_ttc, 'BT-115, what the issuer debits');
		$this->assertNull(SupplierInvoiceHelper::totalsMismatch($id), 'nothing holds its validation back');
	}

	/**
	 * A document whose BT-115 does not answer BR-CO-16 says two different things about what has to be
	 * paid. Neither can be trusted, so the invoice is marked and kept out of validation.
	 *
	 * @return void
	 */
	public function testADocumentContradictingItsPayableIsHeldBack()
	{
		$invoice = $this->createRoundingFixtureInvoice();
		$messages = array();
		$withRounding = $this->addRoundingLine($invoice, $this->roundingHeader(), $messages);

		$contradicting = $this->roundingHeader();
		$contradicting['duePayableAmount'] = 30.00;		// neither BT-112 nor BT-112 + BT-114
		$contradicting['documentno'] = 'EINV994-CONTRADICTION';

		$messages = array();
		$this->alignWithHeader($withRounding, $contradicting, $messages);

		$mark = SupplierInvoiceHelper::totalsMismatch((int) $withRounding->id);
		$this->assertNotNull($mark, 'the invoice is marked as one the import could not reproduce');
		$this->assertEquals(30.00, (float) $mark['ttc'], 'the mark carries the amount the document says is due');
		$this->assertFalse(
			SupplierInvoiceHelper::totalsAgreeWithDocument($withRounding, 4.25, (float) $mark['ttc']),
			'which the invoice does not total, so the mark holds instead of lifting itself'
		);

		$this->assertNotEmpty($messages, 'and the operator is told');
		$this->assertStringContainsString('30', $messages[0], 'the amount the document says is due');
		$this->assertStringContainsString('25.47', $messages[0], 'against what its own totals add up to');

		// The same invoice, confronted with the document as it really is, is released again
		$messages = array();
		$this->alignWithHeader($withRounding, $this->roundingHeader(), $messages);
		$this->assertNull(SupplierInvoiceHelper::totalsMismatch((int) $withRounding->id), 'a document that adds up lifts the mark');
	}

	/**
	 * The same document imported before the rounding line existed is the same invoice: the lookup of an
	 * already imported document must still find it, rather than reporting a duplicate with a bad amount.
	 *
	 * @return void
	 */
	public function testAnInvoiceImportedBeforeTheRoundingLineIsStillFound()
	{
		$invoice = $this->createRoundingFixtureInvoice();
		$header = $this->roundingHeader();

		$found = SupplierInvoiceHelper::findIdByRef(
			$invoice->ref_supplier,
			(int) $invoice->socid,
			(float) SupplierInvoiceHelper::announcedTotalTtc($header),
			abs(SupplierInvoiceHelper::documentRoundingAmount($header))
		);
		$this->assertEquals((int) $invoice->id, $found, 'the invoice totals 25.48 and the document now expects 25.47');

		$this->assertEquals(
			-3,
			SupplierInvoiceHelper::findIdByRef($invoice->ref_supplier, (int) $invoice->socid, 30.0, 0.01),
			'a total that is not the one of the document is still reported'
		);
	}
}
