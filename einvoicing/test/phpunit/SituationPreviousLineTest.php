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
 *      \file       test/phpunit/SituationPreviousLineTest.php
 *      \ingroup    test
 *      \brief      The deduction of the previous situations reads the line the core recorded.
 *      \remarks    buildinvoicelines.inc.php used to read the line a situation line continues with a
 *                  query of its own on llx_facturedet. It now reads it through FactureLigne::fetch(),
 *                  the method of the core that owns the shape of that table.
 *
 *                  That is a refactoring, so the document must not move by a cent. This file pins
 *                  both halves of that statement:
 *                  - the two ways of reading the previous line - the query that was there and
 *                    FactureLigne::fetch() - answer the same total_ht and the same total_tva, on a
 *                    cycle whose amounts do not fall on round cents;
 *                  - the document generated for the second situation deducts exactly the amount that
 *                    reading gives, and still states what Dolibarr bills.
 *
 *                  It also pins what the new code branches on: fetch() answers 0, not -1, for a line
 *                  that is not there. The old code told those two apart by the query failing or
 *                  returning nothing, and the difference decides whether a missing predecessor is
 *                  logged as an error or skipped in silence.
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
// FactureLigne comes with this file: it is declared in it up to Dolibarr 20, and required by it
// from 21 on, where it moved to compta/facture/class/factureligne.class.php.
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
 * Class SituationPreviousLineTest
 *
 * Builds a situation cycle of two invoices whose amounts do not fall on round cents, and checks how
 * the previous line of the cycle is read and what the generated document deducts because of it.
 */
class SituationPreviousLineTest extends CommonClassTest
{
	const RAM = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';

	/** @var float	Net amount of the single line of the cycle. Chosen with the progress below so
	 *				that the amounts do not fall on round cents: what the core rounded and wrote is
	 *				then the only source of truth for the deduction. */
	const LINE_HT = 333.33;
	/** @var float	VAT rate of that line */
	const VAT_RATE = 20.0;
	/** @var float	Progress of the first situation, in percent */
	const FIRST_PROGRESS = 33.33;
	/** @var float	Cumulative progress stated by the second situation, in percent */
	const SECOND_PROGRESS = 66.66;

	/**
	 * Build the cycle once for the whole class: the transaction opened by CommonClassTest is rolled
	 * back at the end, so nothing of this reaches the database of a real instance.
	 *
	 * @return array{invoice:Facture,xml:string,previousLineId:int}	The second situation, the document
	 *															generated for it, and the id of the
	 *															line the second situation continues
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
		// so the block this file is about is not even entered there.
		$savUseSituation = getDolGlobalString('INVOICE_USE_SITUATION');
		$savPdp = getDolGlobalString('EINVOICING_PDP');
		$conf->global->INVOICE_USE_SITUATION = 1;
		$conf->global->EINVOICING_PDP = 'SPECIMEN';

		try {
			$buyer = new Societe($db);
			$buyer->name = 'EINVOICING TEST PREVIOUS LINE BUYER';
			$buyer->client = 1;
			// Some instances - the demo database among them - number their customers with a module
			// that refuses a third party without a code. Giving one costs nothing where the code is
			// generated instead, and it makes the test independent of that setting.
			$buyer->code_client = 'EINVPL' . strtoupper(substr(md5(uniqid('', true)), 0, 6));
			$buyer->address = '2 rue du Test';
			$buyer->zip = '75000';
			$buyer->town = 'Paris';
			$buyer->country_id = 1;			// France
			$buyer->country_code = 'FR';
			$buyer->idprof1 = '000000002';
			$buyer->idprof2 = '00000000200010';
			$buyer->tva_intra = 'FR12000000002';
			$this->assertGreaterThan(0, $buyer->create($user), 'the buyer of the cycle is created: ' . $buyer->error);

			// First situation: FIRST_PROGRESS % of the line.
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

			// Second situation: SECOND_PROGRESS % of the same line, which is what its line states,
			// while the core bills the difference.
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

			return array(
				'invoice' => $reloaded,
				'xml' => (string) file_get_contents((string) $path),
				'previousLineId' => (int) $firstLineId,
			);
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
	 * The two ways of reading the line a situation line continues answer the same thing.
	 *
	 * This is the equivalence the refactoring rests on: the block only changed how it gets
	 * total_ht and total_tva of the previous line, so as long as both readings agree, the amounts
	 * that go into the document are the same ones and the document cannot move.
	 *
	 * @return void
	 */
	public function testTheCoreClassReadsTheSameLineAsTheQueryItReplaces()
	{
		global $db;

		$built = $this->buildSecondSituation();
		$previousLineId = $built['previousLineId'];

		// The reading that was in buildinvoicelines.inc.php before the refactoring.
		$sql = 'SELECT total_ht, total_tva FROM ' . MAIN_DB_PREFIX . 'facturedet WHERE rowid = ' . ((int) $previousLineId);
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, 'the previous line is readable: ' . $db->lasterror());
		$fromquery = $db->fetch_object($resql);
		$db->free($resql);
		$this->assertNotEmpty($fromquery, 'the previous line is there');

		// The reading that is there now.
		$prevline = new FactureLigne($db);
		$this->assertSame(1, (int) $prevline->fetch($previousLineId), 'FactureLigne::fetch() loads the previous line: ' . $prevline->error);

		$this->assertEqualsWithDelta(
			(float) $fromquery->total_ht,
			(float) $prevline->total_ht,
			0.0001,
			'FactureLigne::fetch() gives the net amount the query gave'
		);
		$this->assertEqualsWithDelta(
			(float) $fromquery->total_tva,
			(float) $prevline->total_tva,
			0.0001,
			'FactureLigne::fetch() gives the VAT amount the query gave'
		);

		// The premise of this file: there is something to deduct. An empty predecessor would make
		// the comparison above hold on two zeros and prove nothing.
		$this->assertGreaterThan(0, (float) $prevline->total_ht, 'the previous line carries a net amount');
		$this->assertGreaterThan(0, (float) $prevline->total_tva, 'the previous line carries a VAT amount');
	}

	/**
	 * A line that is not there is answered with 0, not with an error.
	 *
	 * The block skips a missing predecessor in silence and logs only a failed read, so it branches
	 * on that difference. It used to come from the query returning no row versus the query failing.
	 *
	 * @return void
	 */
	public function testAMissingPreviousLineIsNotAFailedRead()
	{
		global $db;

		$sql = 'SELECT MAX(rowid) as maxid FROM ' . MAIN_DB_PREFIX . 'facturedet';
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, 'the invoice lines table is readable: ' . $db->lasterror());
		$obj = $db->fetch_object($resql);
		$db->free($resql);

		$absent = ((int) (is_object($obj) ? $obj->maxid : 0)) + 1000;

		$prevline = new FactureLigne($db);
		$this->assertSame(0, (int) $prevline->fetch($absent), 'FactureLigne::fetch() answers 0 for a line that does not exist');
	}

	/**
	 * The document deducts the amounts the previous line recorded, to the cent.
	 *
	 * @return void
	 */
	public function testTheDocumentDeductsWhatThePreviousLineRecorded()
	{
		global $db;

		$built = $this->buildSecondSituation();
		$xml = $built['xml'];
		$invoice = $built['invoice'];

		$prevline = new FactureLigne($db);
		$this->assertSame(1, (int) $prevline->fetch($built['previousLineId']), 'the previous line is read: ' . $prevline->error);

		$this->assertEqualsWithDelta(
			(float) price2num((float) $prevline->total_ht, 2),
			$this->summation($xml, 'AllowanceTotalAmount'),
			0.0051,
			'BT-107 deducts the net amount recorded on the line of the first situation'
		);

		// And the deduction lands where it has to: the document still states what Dolibarr bills.
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
	}
}
