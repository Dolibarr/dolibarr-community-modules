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
 *      \file       test/phpunit/CronSyncBatchSizeTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for Document::getCronSyncBatchSize(), the batch size of the scheduled sync.
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
dol_include_once('einvoicing/class/document.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';


/**
 * Tests on the batch size of the scheduled sync.
 */
class CronSyncBatchSizeTest extends CommonClassTest
{
	const SETTINGS = array('EINVOICING_FLOWS_SYNC_CRON_SIZE', 'EINVOICING_FLOWS_SYNC_CALL_SIZE');

	/** @var array<string,string|null>	Values of the settings before the test */
	private $savSettings = array();

	/**
	 * Start every case with neither constant set.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();

		foreach (self::SETTINGS as $name) {
			$this->savSettings[$name] = isset($conf->global->$name) ? $conf->global->$name : null;
			unset($conf->global->$name);
		}
	}

	/**
	 * Put the settings back for the test files run after this one in the same process.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		foreach ($this->savSettings as $name => $value) {
			if ($value === null) {
				unset($conf->global->$name);
			} else {
				$conf->global->$name = $value;
			}
		}
		parent::tearDown();
	}

	/**
	 * An instance upgraded from 1.2.0 only has the admin setting: the scheduled sync keeps using it.
	 *
	 * @return void
	 */
	public function testAdminSettingAloneIsUsed()
	{
		global $conf;

		$conf->global->EINVOICING_FLOWS_SYNC_CALL_SIZE = '250';

		$this->assertSame(250, Document::getCronSyncBatchSize());
	}

	/**
	 * @return void
	 */
	public function testCronSettingAloneIsUsed()
	{
		global $conf;

		$conf->global->EINVOICING_FLOWS_SYNC_CRON_SIZE = '40';

		$this->assertSame(40, Document::getCronSyncBatchSize());
	}

	/**
	 * The cron setting overrides the admin setting.
	 *
	 * @return void
	 */
	public function testCronSettingWinsOverAdminSetting()
	{
		global $conf;

		$conf->global->EINVOICING_FLOWS_SYNC_CRON_SIZE = '40';
		$conf->global->EINVOICING_FLOWS_SYNC_CALL_SIZE = '250';

		$this->assertSame(40, Document::getCronSyncBatchSize());
	}

	/**
	 * @return void
	 */
	public function testDefaultWhenNothingIsSet()
	{
		$this->assertSame(100, Document::getCronSyncBatchSize());
	}
}
