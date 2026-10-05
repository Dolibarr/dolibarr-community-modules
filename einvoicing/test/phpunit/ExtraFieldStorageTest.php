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
 *      \file       test/phpunit/ExtraFieldStorageTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for EInvoicing::insertOrUpdateExtraField()
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See RecipientDirectoryTest.php for why DOLIBARR_HTDOCS is honoured before the relative path.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
dol_include_once('einvoicing/class/einvoicing.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
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
 * Tests on the properties the module stores in llx_einvoicing_extrafields.
 */
class ExtraFieldStorageTest extends CommonClassTest
{
	/** @var int Element id no real object uses */
	const TEST_ELEMENT_ID = 999999003;

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $db;

		parent::setUp();

		$db->query("DELETE FROM " . $db->prefix() . "einvoicing_extrafields WHERE element_id = " . (int) self::TEST_ELEMENT_ID);
	}

	/**
	 * PostgreSQL fails reading the id of a row the connection did not insert (currval of its sequence).
	 *
	 * @return void
	 */
	public function testEmptyingAPropertyNeverStoredReadsNoNewId()
	{
		global $db;

		$recordingDb = new IdReadRecordingDb($db);
		$einvoicing = new EInvoicing($recordingDb);

		$this->assertGreaterThan(0, $einvoicing->insertOrUpdateExtraField(self::TEST_ELEMENT_ID, 'invoice_supplier', EInvoicing::EXTRAFIELD_BUYER_ORDER_REFERENCE, 'PO-0001'));

		$this->assertSame(0, $einvoicing->insertOrUpdateExtraField(self::TEST_ELEMENT_ID, 'invoice_supplier', EInvoicing::EXTRAFIELD_TOTALS_MISMATCH, ''));
		$this->assertSame(array(), $recordingDb->idReadsWithoutInsert);
		$this->assertNull($einvoicing->getExtraFieldValue(self::TEST_ELEMENT_ID, 'invoice_supplier', EInvoicing::EXTRAFIELD_TOTALS_MISMATCH));
	}

	/**
	 * shouldMergeLineChargesIntoDescription() (issue #969): a supplier with no override follows the
	 * global default, '1' or '0' overrides it regardless of the global value, and deleting the override
	 * (an empty value, which insertOrUpdateExtraField() reads as a delete) falls back to the global
	 * default again.
	 *
	 * @return void
	 */
	public function testMergeLineChargesFollowsTheOverrideThenTheGlobalDefault()
	{
		global $conf, $db;

		$einvoicing = new EInvoicing($db);
		$savedDefault = $conf->global->EINVOICING_MERGE_LINE_CHARGES_INTO_DESCRIPTION ?? null;

		try {
			$conf->global->EINVOICING_MERGE_LINE_CHARGES_INTO_DESCRIPTION = 0;
			$this->assertFalse($einvoicing->shouldMergeLineChargesIntoDescription(self::TEST_ELEMENT_ID), 'no override: follows the global default (off)');

			$this->assertGreaterThan(0, $einvoicing->insertOrUpdateExtraField(self::TEST_ELEMENT_ID, 'societe', EInvoicing::EXTRAFIELD_MERGE_LINE_CHARGES, '1'));
			$this->assertTrue($einvoicing->shouldMergeLineChargesIntoDescription(self::TEST_ELEMENT_ID), 'overridden on, although the global default is off');

			$conf->global->EINVOICING_MERGE_LINE_CHARGES_INTO_DESCRIPTION = 1;
			$this->assertTrue($einvoicing->shouldMergeLineChargesIntoDescription(self::TEST_ELEMENT_ID), 'still on: the override does not depend on the global value');

			$this->assertSame(1, $einvoicing->insertOrUpdateExtraField(self::TEST_ELEMENT_ID, 'societe', EInvoicing::EXTRAFIELD_MERGE_LINE_CHARGES, '0'));
			$this->assertFalse($einvoicing->shouldMergeLineChargesIntoDescription(self::TEST_ELEMENT_ID), 'overridden off, although the global default is now on');

			// Deleting the override (empty value) falls back to the global default again.
			$this->assertSame(1, $einvoicing->insertOrUpdateExtraField(self::TEST_ELEMENT_ID, 'societe', EInvoicing::EXTRAFIELD_MERGE_LINE_CHARGES, ''));
			$this->assertTrue($einvoicing->shouldMergeLineChargesIntoDescription(self::TEST_ELEMENT_ID), 'override deleted: back to the global default (on)');
		} finally {
			if ($savedDefault === null) {
				unset($conf->global->EINVOICING_MERGE_LINE_CHARGES_INTO_DESCRIPTION);
			} else {
				$conf->global->EINVOICING_MERGE_LINE_CHARGES_INTO_DESCRIPTION = $savedDefault;
			}
		}
	}
}

/**
 * Database handler forwarding every call, which records the reads of a new id that do not follow an INSERT.
 */
class IdReadRecordingDb
{
	/** @var string[] Query that preceded each such read */
	public $idReadsWithoutInsert = array();

	/** @var DoliDB */
	private $db;

	/**
	 * @param DoliDB $db Database handler to forward to
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param string	$method		Method of the database handler
	 * @param mixed[]	$arguments	Its arguments
	 * @return mixed				What the database handler returns
	 */
	public function __call($method, $arguments)
	{
		if ($method === 'last_insert_id' && strpos($this->db->lastquery, 'INSERT') !== 0) {
			$this->idReadsWithoutInsert[] = $this->db->lastquery;
		}

		return call_user_func_array(array($this->db, $method), $arguments);
	}
}
