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
 *      \file       test/phpunit/VatDictionaryEntityTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the entity the VAT dictionary is read in.
 *                  The VAT dictionary is per entity since Dolibarr 19, and the core reads it with
 *                  "AND t.entity IN (".getEntity('c_tva').")" (getTaxesFromId() in
 *                  htdocs/core/lib/functions.lib.php). The reads the generator makes of it decide the
 *                  VAT exemption reason and code of the document, BT-120 and BT-121, so a read that
 *                  ignores the entity states on the invoice a reason another company declared.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See VatPointDateCodeTest for why DOLIBARR_HTDOCS is honoured before the relative path.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class VatDictionaryEntityTest extends CommonClassTest
{
	/** @var int	Entity the test writes its "other company" dictionary lines in */
	private $otherentity = 0;

	/** @var int	Entity of the run, restored in tearDown() */
	private $initialentity = 0;

	/** @var int[]	rowid of the dictionary lines inserted, removed in tearDown() */
	private $insertedrowids = array();

	/**
	 * Give the class a dictionary of its own: one line for the entity of the run, one for an entity
	 * that does not belong to it. The whole class runs inside the transaction opened by
	 * CommonClassTest::setUpBeforeClass(), and tearDown() deletes the lines anyway.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf, $db;

		parent::setUp();

		if (!$this->dictionaryHasEntityColumn()) {
			$this->markTestSkipped('The entity column of llx_c_tva is added by the 18.0.0-19.0.0 migration: on Dolibarr 18 the dictionary is not per entity and there is nothing to restrict.');
		}

		$this->initialentity = (int) $conf->entity;

		$sql = "SELECT MAX(entity) as maxentity FROM " . MAIN_DB_PREFIX . "c_tva";
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, (string) $db->lasterror());
		$obj = $db->fetch_object($resql);
		$this->otherentity = max((int) $obj->maxentity, $this->initialentity) + 1;
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf, $db;

		$conf->entity = $this->initialentity;

		if (!empty($this->insertedrowids)) {
			$db->query("DELETE FROM " . MAIN_DB_PREFIX . "c_tva WHERE rowid IN (" . implode(',', $this->insertedrowids) . ")");
			$this->insertedrowids = array();
		}

		parent::tearDown();
	}

	/**
	 * Whether the dictionary table holds an entity column, which the 18.0.0-19.0.0 migration adds.
	 *
	 * The version is not asked to the constant here but to the table itself: an instance upgraded from
	 * an old release is the very case this test is about, and the column is what decides.
	 *
	 * @return bool
	 */
	private function dictionaryHasEntityColumn()
	{
		global $db;

		$resql = $db->DDLDescTable(MAIN_DB_PREFIX . 'c_tva', 'entity');

		return ($resql && $db->num_rows($resql) > 0);
	}

	/**
	 * Add one line to the VAT dictionary and remember it for the cleanup.
	 *
	 * @param	int		$entity		Entity the line belongs to
	 * @param	string	$code		Value of the code column, at most 10 characters
	 * @param	string	$note		Value of the note column, which is BT-120
	 * @param	string	$vatex		Value of the einvoice_vatex column, ignored below Dolibarr 24
	 * @return	int					rowid of the line
	 */
	private function addDictionaryLine($entity, $code, $note, $vatex = '')
	{
		global $db, $mysoc;

		$hasvatexcolumn = ((float) DOL_VERSION >= 24.0);

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "c_tva";
		$sql .= " (entity, fk_pays, code, taux, recuperableonly, note, active" . ($hasvatexcolumn ? ", einvoice_vatex" : "") . ")";
		$sql .= " VALUES (" . ((int) $entity) . ", " . ((int) $mysoc->country_id) . ", '" . $db->escape($code) . "', 0, 0, '" . $db->escape($note) . "', 1";
		$sql .= ($hasvatexcolumn ? ", '" . $db->escape($vatex) . "'" : "") . ")";

		$this->assertNotFalse($db->query($sql), (string) $db->lasterror());

		$rowid = (int) $db->last_insert_id(MAIN_DB_PREFIX . 'c_tva');
		$this->insertedrowids[] = $rowid;

		return $rowid;
	}

	/**
	 * Read the dictionary line a rate and a code point to, the way the generator does.
	 *
	 * @param	float|string	$rate		VAT rate of the line
	 * @param	string			$code		Code of the dictionary line
	 * @return	array{note:string,einvoice_vatex:string}
	 */
	private function dictionaryEntry($rate, $code)
	{
		global $db;

		$method = new ReflectionMethod(CIIProtocol::class, '_getVatDictionaryEntry');
		$method->setAccessible(true);

		return $method->invoke(new CIIProtocol($db), $rate, $code);
	}

	/**
	 * An invoice line, reduced to what getCategoryRate() reads on it.
	 *
	 * @param	float|string	$rate		->tva_tx
	 * @param	string			$vatCode	->vat_src_code, the code of the VAT dictionary line used
	 * @return	stdClass
	 */
	private function line($rate, $vatCode)
	{
		$line = new stdClass();
		$line->id = 121;
		$line->tva_tx = $rate;
		$line->vat_src_code = $vatCode;
		$line->info_bits = 0;

		return $line;
	}

	/**
	 * A seller subject to VAT, described the way Societe::setMysoc() describes $mysoc.
	 *
	 * @return Societe
	 */
	private function seller()
	{
		global $db;

		$seller = new Societe($db);
		$seller->name = 'Seller';
		$seller->tva_assuj = 1;
		$seller->tva_intra = 'FR75911270304';
		$seller->idprof1 = '911270304';
		$seller->country_code = 'FR';

		return $seller;
	}

	/**
	 * The invoice the line belongs to, whose ->thirdparty is the buyer: a customer of the same country
	 * as the seller, so the regime of the line is the one the dictionary states.
	 *
	 * @return stdClass
	 */
	private function invoiceOf()
	{
		global $db;

		$buyer = new Societe($db);
		$buyer->name = 'Customer';
		$buyer->tva_intra = 'FR16384322020';
		$buyer->idprof1 = '384322020';
		$buyer->country_code = 'FR';

		$invoice = new stdClass();
		$invoice->thirdparty = $buyer;

		return $invoice;
	}

	/**
	 * A dictionary line of another entity is not the seller's line, and reading it would state on the
	 * invoice a reason (BT-120) and a code (BT-121) another company declared.
	 *
	 * @return void
	 */
	public function testALineOfAnotherEntityIsNotRead()
	{
		$this->addDictionaryLine($this->otherentity, 'E-OTHER', 'Reason of the other company', 'VATEX-EU-132');

		$entry = $this->dictionaryEntry(0, 'E-OTHER');

		$this->assertSame('', $entry['note']);
		$this->assertSame('', $entry['einvoice_vatex']);
	}

	/**
	 * The line of the entity of the run is still read, with everything it states.
	 *
	 * @return void
	 */
	public function testTheLineOfTheCurrentEntityIsStillRead()
	{
		$this->addDictionaryLine($this->initialentity, 'E-MINE', 'Reason of my company', 'VATEX-FR-CGI261-4');

		$entry = $this->dictionaryEntry(0, 'E-MINE');

		$this->assertSame('Reason of my company', $entry['note']);
		if ((float) DOL_VERSION >= 24.0) {
			$this->assertSame('VATEX-FR-CGI261-4', $entry['einvoice_vatex']);
		}
	}

	/**
	 * Two entities of one installation describe the same regime with the same code and their own
	 * wording, which is the whole point of a dictionary that is per entity. The document must carry the
	 * wording of the entity that issues it.
	 *
	 * The line of the other entity is inserted first on purpose: it is the one a read that names no
	 * entity comes back with.
	 *
	 * @return void
	 */
	public function testTheSameCodeInTwoEntitiesReadsTheSellersOne()
	{
		$this->addDictionaryLine($this->otherentity, 'E-SHARED', 'Reason of the other company', 'VATEX-EU-132');
		$this->addDictionaryLine($this->initialentity, 'E-SHARED', 'Reason of my company', 'VATEX-FR-CGI261-4');

		$entry = $this->dictionaryEntry(0, 'E-SHARED');

		$this->assertSame('Reason of my company', $entry['note']);
	}

	/**
	 * The read follows the entity of the run and not the entity the line was declared in first: an
	 * operator that switches company issues the documents of that company.
	 *
	 * @return void
	 */
	public function testTheReadFollowsTheEntityOfTheRun()
	{
		global $conf;

		$this->addDictionaryLine($this->otherentity, 'E-SWITCH', 'Reason of the other company', 'VATEX-EU-132');

		$this->assertSame('', $this->dictionaryEntry(0, 'E-SWITCH')['note']);

		$conf->entity = $this->otherentity;

		$this->assertSame('Reason of the other company', $this->dictionaryEntry(0, 'E-SWITCH')['note']);
	}

	/**
	 * The other read of the dictionary, the one that answers the VATEX code of an exempt line whose code
	 * is not a VAT category, must not answer a line of another entity either. With nothing found, the
	 * module names the setup to complete instead of inventing a reason - which is what the generator
	 * does everywhere else.
	 *
	 * @return void
	 */
	public function testTheVatexCodeOfAnotherEntityIsNotIssued()
	{
		$protocol = new CIIProtocol($GLOBALS['db']);

		// 'VATEX-EU-D' is ten characters, which is all the code column of the dictionary holds.
		$this->addDictionaryLine($this->otherentity, 'VATEX-EU-D', 'Travel agency', 'VATEX-EU-D');

		$this->expectException(Exception::class);
		$this->expectExceptionMessageMatches('/MISSINGSETUP/');

		$protocol->getCategoryRate($this->line(0, 'VATEX-EU-D'), $this->seller(), $this->invoiceOf());
	}

	/**
	 * And the line of the entity of the run still answers, so an installation that has only ever had one
	 * company issues exactly the documents it used to.
	 *
	 * @return void
	 */
	public function testTheVatexCodeOfTheCurrentEntityIsStillIssued()
	{
		$protocol = new CIIProtocol($GLOBALS['db']);

		$this->addDictionaryLine($this->initialentity, 'VATEX-EU-D', 'Travel agency', 'VATEX-EU-D');

		$result = $protocol->getCategoryRate($this->line(0, 'VATEX-EU-D'), $this->seller(), $this->invoiceOf());

		$this->assertSame('E', $result['categoryVAT']);
		$this->assertSame('VATEX-EU-D', $result['ExemptionReasonCode']);
	}
}
