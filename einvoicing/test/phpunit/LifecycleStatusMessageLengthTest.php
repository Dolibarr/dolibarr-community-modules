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
 * \file    einvoicing/test/phpunit/LifecycleStatusMessageLengthTest.php
 * \ingroup einvoicing
 * \brief   A lifecycle status message longer than its column is stored, cut to the column.
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
dol_include_once('einvoicing/class/einvoicing.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class LifecycleStatusMessageLengthTest extends CommonClassTest
{
	const TEST_ELEMENT_ID = 999999255;

	/** @var string|null	EINVOICING_PDP before the test */
	private $savProvider;

	/**
	 * A provider has to be set for storeStatusMessage() to record anything.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();
		$this->savProvider = isset($conf->global->EINVOICING_PDP) ? $conf->global->EINVOICING_PDP : null;
		$conf->global->EINVOICING_PDP = 'SUPERPDP';
	}

	/**
	 * Put the provider back.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		if ($this->savProvider === null) {
			unset($conf->global->EINVOICING_PDP);
		} else {
			$conf->global->EINVOICING_PDP = $this->savProvider;
		}
		parent::tearDown();
	}

	/**
	 * The notes of a CDAR can be longer than lc_status_message: the status is kept, its message cut.
	 *
	 * @return void
	 */
	public function testAStatusMessageLongerThanItsColumnIsStoredCut()
	{
		global $db;

		$einvoicing = new EInvoicing($db);
		$message = str_repeat('é', 300);

		$rowid = $einvoicing->storeStatusMessage(self::TEST_ELEMENT_ID, 'facture', 213, $message, 'IN', 'ie_999255');
		$this->assertGreaterThan(0, $rowid, 'the status is stored: ' . $db->lasterror());
		$this->assertSame(255, $this->storedMessageLength($rowid));

		$this->assertGreaterThan(0, $einvoicing->updateStatusMessageValidation($rowid, $message . 'x', 'Ok'), 'the status is updated: ' . $db->lasterror());
		$this->assertSame(255, $this->storedMessageLength($rowid));
	}

	/**
	 * Characters of lc_status_message in a stored status row.
	 *
	 * @param	int		$rowid	Row of llx_einvoicing_lifecycle_msg
	 * @return	int
	 */
	private function storedMessageLength($rowid)
	{
		global $db;

		$resql = $db->query("SELECT lc_status_message FROM " . $db->prefix() . "einvoicing_lifecycle_msg WHERE rowid = " . (int) $rowid);
		$this->assertNotFalse($resql);
		$obj = $db->fetch_object($resql);

		return dol_strlen((string) $obj->lc_status_message, 'UTF-8');
	}
}
