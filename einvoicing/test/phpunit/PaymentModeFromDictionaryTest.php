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
 *      \file       test/phpunit/PaymentModeFromDictionaryTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the payment mode (BT-81, UNTDID 4461) of a received document.
 *
 *                  The UNTDID 4461 code of a received invoice is mapped to a Dolibarr payment code
 *                  (30 -> VIR, 20 -> CHQ, ...) which is then looked up in the c_paiement dictionary.
 *                  That dictionary is per entity - llx_c_paiement carries an "entity" column and a
 *                  unique index on (entity, code) - and entries can be deactivated. The lookup has
 *                  therefore to return the row of the current entity, and only an active one.
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
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class PaymentModeFromDictionaryTest extends CommonClassTest
{
	/** @var string	UNTDID 4461 code for a bank transfer, mapped to the Dolibarr code VIR */
	const UNTDID_BANK_TRANSFER = '30';

	/** @var string	UNTDID 4461 code for a cheque, mapped to the Dolibarr code CHQ */
	const UNTDID_CHEQUE = '20';

	/** @var string	UNTDID 4461 code with no Dolibarr equivalent (clearing between partners) */
	const UNTDID_UNMAPPED = '97';

	/** @var int	Entity the test was started in, restored after each test */
	private $savedEntity = 0;

	/**
	 * Start every test on a clean core code cache and remember the working entity.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();

		$this->savedEntity = (int) $conf->entity;
		$this->clearCoreCodeCache();
	}

	/**
	 * Put the working entity back and drop whatever the test left in the core code cache.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$conf->entity = $this->savedEntity;
		$this->clearCoreCodeCache();

		parent::tearDown();
	}

	/**
	 * Empty the cache dol_getIdFromCode() keeps its answers in.
	 *
	 * That cache changed container between Dolibarr 19 and 20: up to 19 it is the global $cache_codes,
	 * from 20 on it is $conf->cache['codeid']. Both are emptied here so the test says the same thing on
	 * every supported version.
	 *
	 * It matters because the cache key is only [table][key][fieldid] from Dolibarr 19 on (18 is the
	 * one version keying on the searched field, the entity flag and the extra filter as well): it
	 * holds neither the value of $conf->entity nor the "active" filter, and it memorizes the empty
	 * answers too. A test class that walks several entities and both states of the same entry has to
	 * reset it between tests. An import reads the dictionary in a single entity with a single filter,
	 * so it is not concerned.
	 *
	 * @return void
	 */
	private function clearCoreCodeCache()
	{
		global $conf;

		// Dolibarr 18 and 19
		unset($GLOBALS['cache_codes']);

		// Dolibarr 20 and above
		if (isset($conf->cache) && is_array($conf->cache)) {
			unset($conf->cache['codeid']);
		}
	}

	/**
	 * Fail with a readable message if the code cache still holds something.
	 *
	 * Without this, a core release moving that cache to yet another container would only show up as an
	 * unexplained rowid in the entity test.
	 *
	 * @param	string	$context	What the test was about to do
	 * @return	void
	 */
	private function assertCoreCodeCacheIsEmpty($context)
	{
		global $conf;

		$this->assertArrayNotHasKey('cache_codes', $GLOBALS, 'The global $cache_codes of Dolibarr 18/19 survived the reset before ' . $context);
		$this->assertTrue(empty($conf->cache['codeid']), 'The $conf->cache[\'codeid\'] of Dolibarr 20+ survived the reset before ' . $context);
	}

	/**
	 * Run the payment information step on a fresh supplier invoice, the way both protocols call it on
	 * a received document, with nothing but BT-81 filled in.
	 *
	 * @param	string					$untdidCode	UNTDID 4461 code carried by the document (BT-81)
	 * @param	array{message:string}	$result		Messages returned by the step, filled by the call
	 * @return	FactureFournisseur					The invoice the step worked on
	 */
	private function applyPaymentMeansCode($untdidCode, &$result)
	{
		global $db;

		$supplierInvoice = new FactureFournisseur($db);
		$supplierInvoice->mode_reglement_id = 0;

		// CommonProtocol is a trait: the method belongs to the class that uses it.
		$method = new ReflectionMethod(CIIProtocol::class, '_applyPaymentInfoToSupplierInvoice');
		$method->setAccessible(true);
		$result = $method->invoke(new CIIProtocol($db), $supplierInvoice, array('paymentMeansCode' => $untdidCode));

		return $supplierInvoice;
	}

	/**
	 * Read the dictionary the way the module is expected to read it, as a control value.
	 *
	 * @param	string	$code	Dolibarr payment code (VIR, CHQ, ...)
	 * @return	int				Rowid of the active entry of the current entity, 0 if there is none
	 */
	private function expectedRowidFor($code)
	{
		global $db;

		$sql = "SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement";
		$sql .= " WHERE code = '" . $db->escape($code) . "'";
		$sql .= " AND active = 1";
		$sql .= " AND entity IN (" . getEntity('c_paiement') . ")";

		$resql = $db->query($sql);
		$this->assertNotEquals(false, $resql, 'Could not read the c_paiement dictionary: ' . $db->lasterror());

		$obj = $db->fetch_object($resql);
		$db->free($resql);

		return $obj ? (int) $obj->id : 0;
	}

	/**
	 * Add a payment mode to the dictionary of a given entity.
	 *
	 * @param	int		$entity	Entity to create the entry in
	 * @param	string	$code	Dolibarr payment code
	 * @return	int				Rowid of the created entry
	 */
	private function addDictionaryEntry($entity, $code)
	{
		global $db;

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "c_paiement (entity, code, libelle, type, active, position)";
		$sql .= " VALUES (" . ((int) $entity) . ", '" . $db->escape($code) . "', 'Entry of PaymentModeFromDictionaryTest', 0, 1, 0)";

		$this->assertNotEquals(false, $db->query($sql), 'Could not add a c_paiement entry: ' . $db->lasterror());

		// c_paiement names its primary key "id", not "rowid"
		return (int) $db->last_insert_id(MAIN_DB_PREFIX . 'c_paiement', 'id');
	}

	/**
	 * An entity id no dictionary entry uses yet, so a test can work in an empty dictionary.
	 *
	 * @return int	Free entity id
	 */
	private function unusedEntity()
	{
		global $db;

		$resql = $db->query("SELECT MAX(entity) as maxentity FROM " . MAIN_DB_PREFIX . "c_paiement");
		$this->assertNotEquals(false, $resql, 'Could not read the c_paiement dictionary: ' . $db->lasterror());

		$obj = $db->fetch_object($resql);
		$db->free($resql);

		return ((int) $obj->maxentity) + 1;
	}

	/**
	 * BT-81 = 30 must land on the rowid of the VIR entry of the dictionary, not on anything else.
	 *
	 * @return void
	 */
	public function testMappedCodeResolvesToTheDictionaryRowid()
	{
		$expected = $this->expectedRowidFor('VIR');
		$this->assertGreaterThan(0, $expected, 'The instance has no active VIR entry in c_paiement, cannot run the test');

		$result = array();
		$supplierInvoice = $this->applyPaymentMeansCode(self::UNTDID_BANK_TRANSFER, $result);

		$this->assertSame($expected, (int) $supplierInvoice->mode_reglement_id, 'The payment mode is not the rowid of the VIR dictionary entry: ' . $result['message']);
	}

	/**
	 * A UNTDID 4461 code the module has no mapping for leaves the payment mode empty and says so.
	 *
	 * @return void
	 */
	public function testUnmappedCodeLeavesThePaymentModeEmpty()
	{
		$result = array();
		$supplierInvoice = $this->applyPaymentMeansCode(self::UNTDID_UNMAPPED, $result);

		$this->assertSame(0, (int) $supplierInvoice->mode_reglement_id, 'An unmapped UNTDID 4461 code set a payment mode');
		$this->assertStringContainsString('no known Dolibarr mapping', $result['message']);
	}

	/**
	 * A dictionary entry that has been deactivated must not be picked: the module must behave as if
	 * the code were not there at all, rather than assign a mode the user has switched off.
	 *
	 * @return void
	 */
	public function testInactiveDictionaryEntryIsNotUsed()
	{
		global $db;

		$this->assertGreaterThan(0, $this->expectedRowidFor('CHQ'), 'The instance has no active CHQ entry in c_paiement, cannot run the test');

		$sql = "UPDATE " . MAIN_DB_PREFIX . "c_paiement SET active = 0";
		$sql .= " WHERE code = 'CHQ' AND entity IN (" . getEntity('c_paiement') . ")";
		$this->assertNotEquals(false, $db->query($sql), 'Could not deactivate the CHQ entry: ' . $db->lasterror());

		$result = array();
		$supplierInvoice = $this->applyPaymentMeansCode(self::UNTDID_CHEQUE, $result);

		$this->assertSame(0, (int) $supplierInvoice->mode_reglement_id, 'An inactive dictionary entry was used as payment mode');
		$this->assertStringContainsString('not found or not active', $result['message']);
	}

	/**
	 * The lookup must stay inside the current entity: in an entity whose dictionary is empty nothing
	 * is found, and once that entity has its own VIR entry it is that rowid which is used, not the one
	 * of the entity the entries were read from before.
	 *
	 * @return void
	 */
	public function testLookupIsFilteredByEntity()
	{
		global $conf;

		$otherEntity = $this->unusedEntity();
		$rowidOfCurrentEntity = $this->expectedRowidFor('VIR');
		$this->assertGreaterThan(0, $rowidOfCurrentEntity, 'The instance has no active VIR entry in c_paiement, cannot run the test');

		$conf->entity = $otherEntity;
		$this->clearCoreCodeCache();
		$this->assertCoreCodeCacheIsEmpty('the lookup in the empty entity');

		$result = array();
		$supplierInvoice = $this->applyPaymentMeansCode(self::UNTDID_BANK_TRANSFER, $result);
		$this->assertSame(0, (int) $supplierInvoice->mode_reglement_id, 'The dictionary of another entity was used');

		$rowidOfOtherEntity = $this->addDictionaryEntry($otherEntity, 'VIR');
		$this->assertNotSame($rowidOfCurrentEntity, $rowidOfOtherEntity, 'The two dictionary entries should be two distinct rows');
		$this->clearCoreCodeCache();
		$this->assertCoreCodeCacheIsEmpty('the lookup in the entity that now has its own entry');

		$supplierInvoice = $this->applyPaymentMeansCode(self::UNTDID_BANK_TRANSFER, $result);
		$this->assertSame($rowidOfOtherEntity, (int) $supplierInvoice->mode_reglement_id, 'The payment mode does not come from the dictionary of the working entity: ' . $result['message']);
	}
}
