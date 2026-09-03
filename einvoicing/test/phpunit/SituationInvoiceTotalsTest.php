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
 *      \file       test/phpunit/SituationInvoiceTotalsTest.php
 *      \ingroup    test
 *      \brief      A generated document states the amount Dolibarr bills, not one of its own.
 *      \remarks    No validator can catch this. A document that overstates its amounts is
 *                  arithmetically consistent with itself - the lines, the breakdown and the totals
 *                  all agree - so the CEN, Factur-X and CTC-FR schematrons answer VALID on it. The
 *                  only thing it contradicts is the invoice it came from, and that is outside every
 *                  one of them.
 *
 *                  #709 did exactly that: it filed the deduction of the previous situations (#674)
 *                  under one key shape and read it back under another, so the document level
 *                  allowance (BT-107) was silently dropped. A second situation of 70 % on a 1000.00
 *                  line announced 840.00 where Dolibarr bills 480.00 - 360.00 too much, on a
 *                  document that goes to the platform and to the customer, and every validator said
 *                  the document was fine.
 *
 *                  This builds that very cycle - two situations, in the database, in the transaction
 *                  the test class rolls back - and requires the totals of the document to be the
 *                  totals of the invoice.
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
 * Class SituationInvoiceTotalsTest
 *
 * Builds a situation cycle of two invoices and checks the document generated for the second one
 * against what the core says the customer owes.
 */
class SituationInvoiceTotalsTest extends CommonClassTest
{
	const RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

	/** @var float	Net amount of the single line of the cycle */
	const LINE_HT = 1000.00;
	/** @var float	VAT rate of that line */
	const VAT_RATE = 20.0;
	/** @var float	Progress of the first situation, in percent */
	const FIRST_PROGRESS = 30.0;
	/** @var float	Cumulative progress stated by the second situation, in percent */
	const SECOND_PROGRESS = 70.0;

	/**
	 * Build the cycle once for the whole class: the transaction opened by CommonClassTest is rolled
	 * back at the end, so nothing of this reaches the database of a real instance.
	 *
	 * @return array{invoice:Facture,xml:string}		The second situation and the document generated for it
	 */
	private function buildSecondSituation()
	{
		global $conf, $db, $langs, $mysoc;

		$user = new User($db);
		$this->assertGreaterThan(0, $user->fetch(1), 'the instance has a user to act as');

		// The seller of the document is the company of the instance, and this file is about amounts,
		// not about whose identifiers the instance carries: a demo database whose SIREN is "123456"
		// would stop the generation before the first total is read. $mysoc is a global object, so
		// pinning it here changes nothing in the database and is undone below.
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

		// Mode 1 is the one the interface writes: the lines carry the cumulative progress and the
		// core deducts what the previous situations already invoiced. Mode 2 has nothing to deduct,
		// so the case this file is about does not exist there.
		$savUseSituation = getDolGlobalString('INVOICE_USE_SITUATION');
		$savPdp = getDolGlobalString('EINVOICING_PDP');
		$conf->global->INVOICE_USE_SITUATION = 1;
		$conf->global->EINVOICING_PDP = 'SPECIMEN';

		try {
			$buyer = new Societe($db);
			$buyer->name = 'EINVOICING TEST SITUATION BUYER';
			$buyer->client = 1;
			// Some instances - the demo database among them - number their customers with a module
			// that refuses a third party without a code. Giving one costs nothing where the code is
			// generated instead, and it makes the test independent of that setting.
			$buyer->code_client = 'EINVCI' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
			$buyer->address = '2 rue du Test';
			$buyer->zip = '75000';
			$buyer->town = 'Paris';
			$buyer->country_id = 1;			// France
			$buyer->country_code = 'FR';
			$buyer->idprof1 = '000000002';
			$buyer->idprof2 = '00000000200010';
			$buyer->tva_intra = 'FR12000000002';
			$this->assertGreaterThan(0, $buyer->create($user), 'the buyer of the cycle is created: ' . $buyer->error);

			// First situation: 30 % of the line.
			$first = new Facture($db);
			$first->socid = $buyer->id;
			$first->type = Facture::TYPE_SITUATION;
			$first->date = dol_now();
			$first->situation_counter = 1;
			$first->situation_final = 0;
			$this->assertGreaterThan(0, $first->create($user), 'the first situation is created: ' . $first->error);

			// The cycle is named after its first invoice, the way the core names it. It has to be in
			// the database and not only on the object: get_prev_sits() reads it back with a query.
			$this->assertGreaterThan(
				0,
				$db->query('UPDATE ' . MAIN_DB_PREFIX . 'facture SET situation_cycle_ref = ' . ((int) $first->id) . ' WHERE rowid = ' . ((int) $first->id)) ? 1 : 0,
				'the cycle reference is stored on the first situation'
			);
			$first->situation_cycle_ref = $first->id;

			$firstLineId = $first->addline(
				'Situation line',
				self::LINE_HT,		// unit price
				1,					// quantity
				self::VAT_RATE,
				0,					// localtax1
				0,					// localtax2
				0,					// fk_product
				0,					// discount
				'',					// date start
				'',					// date end
				0,					// ventilation
				0,					// info bits
				0,					// fk_remise_except
				'HT',
				0,					// pu_ttc
				0,					// type: product
				-1,					// rank
				0,					// special code
				'',					// origin
				0,					// origin id
				0,					// parent line
				null,				// fk_fournprice
				0,					// pa_ht
				'',					// label
				array(),			// extrafields
				self::FIRST_PROGRESS,
				0					// fk_prev_id: nothing before the first situation
			);
			$this->assertGreaterThan(0, $firstLineId, 'the line of the first situation is added: ' . $first->error);

			// Second situation: 70 % of the same line, which is what its line states, while the core
			// bills the difference.
			$second = new Facture($db);
			$second->socid = $buyer->id;
			$second->type = Facture::TYPE_SITUATION;
			$second->date = dol_now();
			$second->situation_counter = 2;
			$second->situation_cycle_ref = $first->id;
			$second->situation_final = 0;
			$this->assertGreaterThan(0, $second->create($user), 'the second situation is created: ' . $second->error);

			$secondLineId = $second->addline(
				'Situation line',
				self::LINE_HT,
				1,
				self::VAT_RATE,
				0,
				0,
				0,
				0,
				'',
				'',
				0,
				0,
				0,
				'HT',
				0,
				0,
				-1,
				0,
				'',
				0,
				0,
				null,
				0,
				'',
				array(),
				self::SECOND_PROGRESS,
				$firstLineId		// the line this one continues
			);
			$this->assertGreaterThan(0, $secondLineId, 'the line of the second situation is added: ' . $second->error);

			$reloaded = new Facture($db);
			$this->assertGreaterThan(0, $reloaded->fetch($second->id), 'the second situation is read back');
			$reloaded->fetch_lines();
			$reloaded->fetch_thirdparty();

			$protocol = new CIIProtocol($db);
			$path = $protocol->generateXML($reloaded, $langs);
			$this->assertNotEmpty($path, 'the document of the second situation is generated: ' . $protocol->error . ' ' . implode(', ', (array) $protocol->errors));
			$this->assertFileExists((string) $path, 'the generated document is written');

			return array('invoice' => $reloaded, 'xml' => (string) file_get_contents((string) $path));
		} finally {
			$conf->global->INVOICE_USE_SITUATION = $savUseSituation;
			$conf->global->EINVOICING_PDP = $savPdp;
			foreach ($savSeller as $property => $value) {
				$mysoc->$property = $value;
			}
		}
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
	 * The core deducts the first situation, so the second one bills the difference. If this fails the
	 * premise of the whole file is gone and the assertions below would be checking nothing.
	 *
	 * @return void
	 */
	public function testTheCoreBillsTheInstalment()
	{
		$built = $this->buildSecondSituation();
		$invoice = $built['invoice'];

		$expectedHt = self::LINE_HT * (self::SECOND_PROGRESS - self::FIRST_PROGRESS) / 100;

		$this->assertEqualsWithDelta(
			$expectedHt,
			(float) $invoice->total_ht,
			0.011,
			'the second situation of ' . self::SECOND_PROGRESS . ' % after one of ' . self::FIRST_PROGRESS
				. ' % bills the difference'
		);
	}

	/**
	 * The totals of the document are the totals of the invoice.
	 *
	 * On the code of #709 this reads 700.00 / 840.00 against an invoice of 400.00 / 480.00.
	 *
	 * @return void
	 */
	public function testTheDocumentStatesWhatDolibarrBills()
	{
		$built = $this->buildSecondSituation();
		$invoice = $built['invoice'];
		$xml = $built['xml'];

		$this->assertEqualsWithDelta(
			(float) $invoice->total_ht,
			$this->summation($xml, 'TaxBasisTotalAmount'),
			0.011,
			'BT-109 states the net amount the invoice bills'
		);
		$this->assertEqualsWithDelta(
			(float) $invoice->total_tva,
			$this->summation($xml, 'TaxTotalAmount'),
			0.011,
			'BT-110 states the VAT the invoice bills'
		);
		$this->assertEqualsWithDelta(
			(float) $invoice->total_ttc,
			$this->summation($xml, 'GrandTotalAmount'),
			0.011,
			'BT-112 states the gross amount the invoice bills'
		);
		$this->assertEqualsWithDelta(
			(float) $invoice->total_ttc,
			$this->summation($xml, 'DuePayableAmount'),
			0.011,
			'BT-115 asks for what the invoice asks for'
		);
	}

	/**
	 * What the previous situations already invoiced is carried as a document level allowance, which
	 * is what brings the lines - which state the cumulative work - back onto the instalment (#674).
	 *
	 * @return void
	 */
	public function testWhatWasAlreadyInvoicedIsDeductedInTheDocument()
	{
		$built = $this->buildSecondSituation();
		$xml = $built['xml'];

		$expectedDeduction = self::LINE_HT * self::FIRST_PROGRESS / 100;

		$this->assertEqualsWithDelta(
			self::LINE_HT * self::SECOND_PROGRESS / 100,
			$this->summation($xml, 'LineTotalAmount'),
			0.011,
			'BT-106 sums the lines, which state the work done since the beginning of the cycle'
		);
		$this->assertEqualsWithDelta(
			$expectedDeduction,
			$this->summation($xml, 'AllowanceTotalAmount'),
			0.011,
			'BT-107 deducts what the previous situations already invoiced'
		);
	}
}
