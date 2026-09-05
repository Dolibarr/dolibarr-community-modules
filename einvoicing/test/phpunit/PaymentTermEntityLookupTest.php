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
 *      \file       test/phpunit/PaymentTermEntityLookupTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the entity of the payment term put on a received invoice.
 *
 *                  When a document carries a due date (BT-9), the import derives the number of days
 *                  between the invoice date and that due date and looks the matching line up in the
 *                  payment term dictionary. That dictionary is per entity: llx_c_payment_term has an
 *                  entity column and its unique key is (entity, code), so two entities may perfectly
 *                  well each own a line of 137 days. The lookup used to read the whole table, so the
 *                  lowest rowid won and the supplier invoice of one entity could end up carrying the
 *                  payment term of another one.
 *
 *                  The fixture below is that exact shape: the line of the other entity is inserted
 *                  first, hence gets the lower rowid, hence is the one the unfiltered query returned.
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
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
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
class PaymentTermEntityLookupTest extends CommonClassTest
{
	/**
	 * Number of days owned by both entities. Nothing in the standard dictionary uses it.
	 */
	const NBDAYS_IN_BOTH_ENTITIES = 137;

	/**
	 * Number of days owned by the other entity only.
	 */
	const NBDAYS_IN_THE_OTHER_ENTITY_ONLY = 138;

	/**
	 * Number of days of the standard dictionary (code 30D, type_cdr 0), which the current entity must keep finding.
	 */
	const NBDAYS_IN_THE_STANDARD_DICTIONARY = 30;

	/**
	 * Entity of the instance, restored in tearDown().
	 *
	 * @var int
	 */
	private $savedEntity = 1;

	/**
	 * Entity created for the test, one above the highest the dictionary knows.
	 *
	 * @var int
	 */
	private $otherEntity = 0;

	/**
	 * Dictionary lines created by the fixture, removed at the end.
	 *
	 * @var int[]
	 */
	private $insertedTermIds = array();

	/**
	 * Line of NBDAYS_IN_BOTH_ENTITIES belonging to the current entity.
	 *
	 * @var int
	 */
	private $termIdOfCurrentEntity = 0;

	/**
	 * Line of NBDAYS_IN_BOTH_ENTITIES belonging to the other entity, inserted first so its rowid is the lower one.
	 *
	 * @var int
	 */
	private $termIdOfOtherEntity = 0;

	/**
	 * Line of NBDAYS_IN_THE_OTHER_ENTITY_ONLY, belonging to the other entity.
	 *
	 * @var int
	 */
	private $termIdOnlyInOtherEntity = 0;

	/**
	 * Build the two-entity dictionary the test needs.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf, $db;

		parent::setUp();

		$this->savedEntity = (int) $conf->entity;

		// Do not assume an entity 2 exists: take one nothing can already own
		$resql = $db->query("SELECT MAX(entity) as maxentity FROM " . MAIN_DB_PREFIX . "c_payment_term");
		$this->assertNotFalse($resql, self::lastErrorOf($db));
		$obj = $db->fetch_object($resql);
		$this->otherEntity = max((int) $this->savedEntity, (int) $obj->maxentity) + 1;

		$suffix = strtoupper(bin2hex(random_bytes(4)));

		// The other entity first, so it holds the lower rowid: that is the line the unfiltered query returned
		$this->termIdOfOtherEntity = $this->insertPaymentTerm($this->otherEntity, 'PTE' . $suffix . 'A', self::NBDAYS_IN_BOTH_ENTITIES);
		$this->termIdOnlyInOtherEntity = $this->insertPaymentTerm($this->otherEntity, 'PTE' . $suffix . 'B', self::NBDAYS_IN_THE_OTHER_ENTITY_ONLY);
		$this->termIdOfCurrentEntity = $this->insertPaymentTerm($this->savedEntity, 'PTE' . $suffix . 'C', self::NBDAYS_IN_BOTH_ENTITIES);
	}

	/**
	 * Remove the dictionary lines of the fixture and put the entity back.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf, $db;

		$conf->entity = $this->savedEntity;

		foreach ($this->insertedTermIds as $id) {
			$db->query("DELETE FROM " . MAIN_DB_PREFIX . "c_payment_term WHERE rowid = " . ((int) $id));
		}
		$this->insertedTermIds = array();

		parent::tearDown();
	}

	/**
	 * Add one line to the payment term dictionary.
	 *
	 * @param	int		$entity		Entity owning the line
	 * @param	string	$code		Code of the line, unique inside its entity
	 * @param	int		$nbjour		Number of days
	 * @param	int		$typeCdr	0 for a fixed number of days, which is what the import matches on
	 * @return	int					Rowid of the created line
	 */
	private function insertPaymentTerm($entity, $code, $nbjour, $typeCdr = 0)
	{
		global $db;

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "c_payment_term";
		$sql .= "(entity, code, sortorder, active, libelle, libelle_facture, type_cdr, nbjour, decalage)";
		$sql .= " VALUES (" . ((int) $entity) . ", '" . $db->escape($code) . "', 900, 1,";
		$sql .= " '" . $db->escape($code) . "', '" . $db->escape($code) . "',";
		$sql .= " " . ((int) $typeCdr) . ", " . ((int) $nbjour) . ", 0)";

		$resql = $db->query($sql);
		$this->assertNotFalse($resql, self::lastErrorOf($db));

		$id = (int) $db->last_insert_id(MAIN_DB_PREFIX . 'c_payment_term');
		$this->assertGreaterThan(0, $id, 'the fixture line could not be created');
		$this->insertedTermIds[] = $id;

		return $id;
	}

	/**
	 * Run the payment information of a received document onto a supplier invoice, the way the import does.
	 *
	 * @param	int		$nbDays		Number of days between the invoice date (BT-2) and the due date (BT-9)
	 * @return	array{0:FactureFournisseur,1:string}	The invoice the import would create, and what it said
	 */
	private function applyPaymentInfoForDelay($nbDays)
	{
		global $db;

		$invoiceDate = dol_mktime(0, 0, 0, 6, 1, 2026, 'gmt');

		$supplierInvoice = new FactureFournisseur($db);
		$supplierInvoice->date = $invoiceDate;
		$supplierInvoice->cond_reglement_id = 0;

		$parsedHeader = array(
			// normDate() hands the import a YYYY-MM-DD string, whatever the document carried
			'paymentDueDate' => dol_print_date($invoiceDate + ($nbDays * 86400), '%Y-%m-%d', 'gmt'),
		);

		$method = new ReflectionMethod(CIIProtocol::class, '_applyPaymentInfoToSupplierInvoice');
		$method->setAccessible(true);
		$res = $method->invokeArgs(new CIIProtocol($db), array($supplierInvoice, $parsedHeader));

		return array($supplierInvoice, isset($res['message']) ? (string) $res['message'] : '');
	}

	/**
	 * Read one line of the dictionary back.
	 *
	 * @param	int		$id		Rowid of the line
	 * @return	object			The line
	 */
	private function fetchPaymentTerm($id)
	{
		global $db;

		$sql = "SELECT rowid, entity, nbjour, type_cdr, active FROM " . MAIN_DB_PREFIX . "c_payment_term";
		$sql .= " WHERE rowid = " . ((int) $id);

		$resql = $db->query($sql);
		$this->assertNotFalse($resql, self::lastErrorOf($db));
		$obj = $db->fetch_object($resql);
		$this->assertNotEmpty($obj, 'payment term ' . ((int) $id) . ' not found');

		return $obj;
	}

	/**
	 * The last database error, as a string.
	 *
	 * DoliDB::lasterror() returns the lasterror property, which is null as long as no query has failed
	 * (core/db/DoliDB.class.php). PHPUnit types the message argument of its assertions as a strict
	 * string, so handing it over raw fatals on the nominal path - where the query has just succeeded.
	 *
	 * @param	DoliDB	$db		Database handler
	 * @return	string			Never null
	 */
	private static function lastErrorOf($db)
	{
		return 'last database error: ' . (string) $db->lasterror();
	}

	/**
	 * The entities the current one may read the payment term dictionary in, as a list of integers.
	 *
	 * @return	int[]
	 */
	private static function visibleEntities()
	{
		return array_map('intval', explode(',', getEntity('c_payment_term')));
	}

	/**
	 * The fixture is only worth something if the line of the other entity is the one a lookup over the
	 * whole table would have returned, that is the one with the lower rowid.
	 *
	 * @return void
	 */
	public function testTheOtherEntityHoldsTheLineAnUnfilteredLookupWouldReturn()
	{
		$this->assertGreaterThan($this->savedEntity, $this->otherEntity, 'the test entity must not be the current one');
		$this->assertLessThan(
			$this->termIdOfCurrentEntity,
			$this->termIdOfOtherEntity,
			'ORDER BY rowid ASC over the whole table would pick the line of the other entity'
		);
	}

	/**
	 * A number of days only the other entity knows must not be matched at all: the invoice is left
	 * without payment term rather than carrying the one of a company it does not belong to.
	 *
	 * @return void
	 */
	public function testATermOfAnotherEntityIsNotRetained()
	{
		list($supplierInvoice, $message) = $this->applyPaymentInfoForDelay(self::NBDAYS_IN_THE_OTHER_ENTITY_ONLY);

		$this->assertEquals(0, (int) $supplierInvoice->cond_reglement_id, 'the payment term of another entity must not be used');
		$this->assertNotEquals($this->termIdOnlyInOtherEntity, (int) $supplierInvoice->cond_reglement_id);
		$this->assertStringContainsString('No matching Payment Terms', $message, 'the import says it found nothing');
	}

	/**
	 * Both entities own a line of the same number of days. The one of the current entity is the one put
	 * on the invoice, even though the other entity holds the lower rowid.
	 *
	 * @return void
	 */
	public function testTheTermOfTheCurrentEntityIsTheOneRetained()
	{
		list($supplierInvoice, $message) = $this->applyPaymentInfoForDelay(self::NBDAYS_IN_BOTH_ENTITIES);

		$this->assertEquals($this->termIdOfCurrentEntity, (int) $supplierInvoice->cond_reglement_id);
		$this->assertNotEquals($this->termIdOfOtherEntity, (int) $supplierInvoice->cond_reglement_id);
		$this->assertStringContainsString('Payment Terms matched', $message);

		$term = $this->fetchPaymentTerm((int) $supplierInvoice->cond_reglement_id);
		$this->assertEquals($this->savedEntity, (int) $term->entity, 'the retained line belongs to the entity of the invoice');
	}

	/**
	 * The filter does not cost the current entity what it already found: a number of days its own
	 * dictionary knows is still matched, on a line of its own entity.
	 *
	 * @return void
	 */
	public function testAKnownTermOfTheCurrentEntityIsStillRetained()
	{
		global $db;

		// Do not turn a dictionary this instance has edited into a red test: check the line is there
		// first, with a plain equality on the entity rather than with the query under test
		$sql = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "c_payment_term";
		$sql .= " WHERE entity = " . ((int) $this->savedEntity);
		$sql .= " AND nbjour = " . ((int) self::NBDAYS_IN_THE_STANDARD_DICTIONARY);
		$sql .= " AND type_cdr = 0";
		$sql .= " AND active = 1";

		$resql = $db->query($sql);
		$this->assertNotFalse($resql, self::lastErrorOf($db));
		$obj = $db->fetch_object($resql);
		if (empty($obj) || (int) $obj->nb == 0) {
			$this->markTestSkipped('this instance has no active payment term of ' . self::NBDAYS_IN_THE_STANDARD_DICTIONARY . ' fixed days in entity ' . $this->savedEntity);
		}

		list($supplierInvoice, $message) = $this->applyPaymentInfoForDelay(self::NBDAYS_IN_THE_STANDARD_DICTIONARY);

		$this->assertGreaterThan(0, (int) $supplierInvoice->cond_reglement_id, 'the standard 30 days line must still be found');
		$this->assertStringContainsString('Payment Terms matched', $message);

		$term = $this->fetchPaymentTerm((int) $supplierInvoice->cond_reglement_id);
		$this->assertEquals(self::NBDAYS_IN_THE_STANDARD_DICTIONARY, (int) $term->nbjour);
		$this->assertEquals(0, (int) $term->type_cdr, 'end of month lines are not a fixed number of days');
		$this->assertEquals(1, (int) $term->active);
		// Without Multicompany that list is the current entity alone; with it, a shared dictionary line is legitimate too
		$this->assertContains((int) $term->entity, self::visibleEntities(), 'the retained line is readable from the entity of the invoice');
	}

	/**
	 * The lookup follows the entity of the moment, it is not pinned on entity 1: run from the other
	 * entity, the line that was invisible a test ago is the one retained, and the twin of the first
	 * entity is not.
	 *
	 * @return void
	 */
	public function testTheLookupFollowsTheEntityItRunsIn()
	{
		global $conf;

		$conf->entity = $this->otherEntity;

		list($supplierInvoice, $message) = $this->applyPaymentInfoForDelay(self::NBDAYS_IN_THE_OTHER_ENTITY_ONLY);
		$this->assertEquals($this->termIdOnlyInOtherEntity, (int) $supplierInvoice->cond_reglement_id);
		$this->assertStringContainsString('Payment Terms matched', $message);

		list($supplierInvoice, $message) = $this->applyPaymentInfoForDelay(self::NBDAYS_IN_BOTH_ENTITIES);
		$this->assertEquals($this->termIdOfOtherEntity, (int) $supplierInvoice->cond_reglement_id);
		$this->assertNotEquals($this->termIdOfCurrentEntity, (int) $supplierInvoice->cond_reglement_id);

		// The other entity was given a 137 and a 138 days line, nothing else: unless the dictionary is
		// shared with it, the standard 30 days line of the first entity has to stay out of reach. That
		// is the case the unfiltered lookup got wrong, whichever entity it ran in.
		if (self::visibleEntities() === array($this->otherEntity)) {
			list($supplierInvoice, $message) = $this->applyPaymentInfoForDelay(self::NBDAYS_IN_THE_STANDARD_DICTIONARY);
			$this->assertEquals(0, (int) $supplierInvoice->cond_reglement_id, 'the dictionary of the other entity is not readable from here');
			$this->assertStringContainsString('No matching Payment Terms', $message);
		}
	}
}
