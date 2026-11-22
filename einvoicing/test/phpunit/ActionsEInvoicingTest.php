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
 *      \file       test/phpunit/ActionsEInvoicingTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the hooks of einvoicing/class/actions_einvoicing.class.php: the line
 *                  of the scheduled synchronization in the open elements dashboard of the home page.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// DOLIBARR_HTDOCS is honoured first so the module can be tested against any of the supported cores
// without moving the working copy.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/hookmanager.class.php';
dol_include_once('einvoicing/class/actions_einvoicing.class.php');
require_once __DIR__ . '/CommonClassTestCompat.inc.php';

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class ActionsEInvoicingTest extends CommonClassTest
{
	/**
	 * @var	string|null|false	Entry of the module Scheduled jobs in $conf->modules before the test, false while untouched
	 */
	private $savedCronModule = false;

	/**
	 * Turn the module Scheduled jobs on or off for this test only, the way isModEnabled() reads it.
	 *
	 * @param	bool	$on		True to have it enabled
	 * @return	void
	 */
	private function cronModule($on)
	{
		global $conf;

		if ($this->savedCronModule === false) {
			$this->savedCronModule = isset($conf->modules['cron']) ? $conf->modules['cron'] : null;
		}
		if ($on) {
			$conf->modules['cron'] = 'cron';
		} else {
			unset($conf->modules['cron']);
		}
	}

	/**
	 * Give back the module Scheduled jobs as the instance had it.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		if ($this->savedCronModule !== false) {
			if ($this->savedCronModule === null) {
				unset($conf->modules['cron']);
			} else {
				$conf->modules['cron'] = $this->savedCronModule;
			}
			$this->savedCronModule = false;
		}
		parent::tearDown();
	}

	/**
	 * Leave in llx_cronjob a single synchronization job of the module, with the given columns.
	 * The class runs in a transaction that is rolled back, so the rows of the instance come back.
	 *
	 * @param	array<string,string>	$columns	Column => SQL value
	 * @return	void
	 */
	private function syncJob($columns)
	{
		global $conf, $db;

		$db->query("UPDATE ".MAIN_DB_PREFIX."cronjob SET status = 2 WHERE module_name = 'einvoicing' AND methodename = 'cronSyncFlows'");
		$db->query("DELETE FROM ".MAIN_DB_PREFIX."cronjob WHERE label = 'PHPUNIT1142'");
		$columns = array_merge(array(
			'label' => "'PHPUNIT1142'", 'jobtype' => "'method'", 'module_name' => "'einvoicing'", 'methodename' => "'cronSyncFlows'",
			'objectname' => "'Document'", 'frequency' => '15', 'unitfrequency' => "'60'", 'status' => '1', 'processing' => '0',
			'entity' => (string) ((int) $conf->entity),
		), $columns);
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."cronjob (".implode(', ', array_keys($columns)).") VALUES (".implode(', ', $columns).")";
		$this->assertTrue((bool) $db->query($sql), (string) $db->lasterror());
	}

	/**
	 * Lines the hook adds to the open elements dashboard, with the user allowed to read the module.
	 *
	 * @return	array<string,mixed>
	 */
	private function dashboardLines()
	{
		global $db, $user;

		$saved = isset($user->rights->einvoicing) ? $user->rights->einvoicing : null;
		if (!isset($user->rights->einvoicing) || !is_object($user->rights->einvoicing)) {
			$user->rights->einvoicing = new stdClass();
		}
		$user->rights->einvoicing->read = 1;
		try {
			$hooks = new ActionsEInvoicing($db);
			$action = '';
			$this->assertSame(0, $hooks->addOpenElementsDashboardLine(array(), null, $action, new HookManager($db)));
			return (array) $hooks->results;
		} finally {
			$user->rights->einvoicing = $saved;
		}
	}

	/**
	 * The line is only there while the synchronization is scheduled: a disabled job, or the module
	 * Scheduled jobs turned off, is a choice of the administrator, not an element to deal with (#1142).
	 *
	 * @return void
	 */
	public function testTheDashboardLineIsHiddenWhenTheSyncIsNotScheduled()
	{
		global $db;

		$past = "'".$db->idate(dol_now() - 7200)."'";
		$this->cronModule(true);

		$this->syncJob(array('datelastrun' => $past, 'datenextrun' => $past, 'lastresult' => "'1'"));
		$lines = $this->dashboardLines();
		$this->assertArrayHasKey('einvoicing_sync', $lines, 'a failed job is shown');
		$this->assertSame(1, (int) $lines['einvoicing_sync']->nbtodolate);

		$this->syncJob(array('datelastrun' => $past, 'datenextrun' => $past, 'lastresult' => "'1'", 'status' => '0'));
		$this->assertSame(array(), $this->dashboardLines(), 'a job disabled on purpose shows nothing');

		$this->syncJob(array('datelastrun' => $past, 'datenextrun' => $past, 'lastresult' => "'0'"));
		$this->cronModule(false);
		$this->assertSame(array(), $this->dashboardLines(), 'without the module Scheduled jobs nothing runs the job: it is not late');
	}

	/**
	 * The line goes in the Invoices card of the core, which is returned whole since the core merges the
	 * returned groups over its own: nothing of the core card is lost, and no look of the module is added.
	 *
	 * @return void
	 */
	public function testTheDashboardLineGoesInTheInvoicesCardOfTheCore()
	{
		global $db;

		$core = array(
			'facture' => array('groupName' => 'Invoices', 'globalStatsKey' => 'invoices', 'stats' => array('facture')),
			'propal' => array('groupName' => 'Proposals', 'stats' => array('propal_opened', 'propal_signed')),
		);
		$hooks = new ActionsEInvoicing($db);
		$action = '';
		$this->assertSame(0, $hooks->addOpenElementsDashboardGroup(array('dashboardgroup' => $core), null, $action, new HookManager($db)));

		$this->assertSame(array('facture'), array_keys((array) $hooks->results), 'only the Invoices card is touched');
		$merged = array_merge($core, (array) $hooks->results);
		$this->assertSame(array('facture', 'einvoicing_sync'), $merged['facture']['stats']);
		$this->assertSame('invoices', $merged['facture']['globalStatsKey']);
		$this->assertSame($core['propal'], $merged['propal']);

		$hooks = new ActionsEInvoicing($db);
		$hooks->addOpenElementsDashboardGroup(array('dashboardgroup' => array()), null, $action, new HookManager($db));
		$this->assertEmpty($hooks->results, 'no Invoices card, no card of its own either');
	}
}
