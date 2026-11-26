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
 *                  of the scheduled synchronization in the open elements dashboard of the home page, and
 *                  the e-invoice of a transmitted invoice kept when its PDF is rebuilt.
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
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
dol_include_once('einvoicing/class/einvoicing.class.php');
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

	/** @var int Element id of the in-memory invoice of the PDF rebuild tests: no real invoice uses it */
	const REBUILD_ELEMENT_ID = 999912650;

	/**
	 * @var	array<string,?string>	Constants changed by a test, with the value they had before
	 */
	private $savedConsts = array();

	/**
	 * Set a constant for this test only, the way getDolGlobalString() reads it.
	 *
	 * @param	string	$name	Constant name
	 * @param	string	$value	Value
	 * @return	void
	 */
	private function setConst($name, $value)
	{
		global $conf;

		if (!array_key_exists($name, $this->savedConsts)) {
			$this->savedConsts[$name] = isset($conf->global->$name) ? $conf->global->$name : null;
		}
		$conf->global->$name = $value;
	}

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
		foreach ($this->savedConsts as $name => $value) {
			if ($value === null) {
				unset($conf->global->$name);
			} else {
				$conf->global->$name = $value;
			}
		}
		$this->savedConsts = array();
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

	/**
	 * Rebuild the PDF of a validated in-memory invoice of a French company, with the e-invoice generated in
	 * real time and the record given, and say what the hook reported.
	 *
	 * @param	string	$flowId		Flow id of the record ('' = never submitted)
	 * @param	string	$allowRegen	Value of EINVOICING_ALLOW_REGEN_TRANSMITTED
	 * @return	array<string,mixed>	Return code, errors and warnings of the hook
	 */
	private function rebuildPdf($flowId, $allowRegen = '0')
	{
		global $db;

		$this->setConst('EINVOICING_ALLOW_REGEN_TRANSMITTED', $allowRegen);
		foreach (array('EINVOICING_DISABLE_SYNC_DOLI_TO_AP', 'EINVOICING_ALLOW_RESEND_TRANSMITTED',
			'EINVOICING_EINVOICE_CANCEL_IF_EINVOICE_FAILS', 'EINVOICING_SKIP_B2C', 'EINVOICING_USE_BILLING_CONTACT_AS_BUYER') as $name) {
			$this->setConst($name, '0');
		}
		$this->setConst('EINVOICING_PDP', 'SUPERPDP');
		$this->setConst('EINVOICING_EINVOICE_IN_REAL_TIME', '1');

		$db->query("DELETE FROM " . $db->prefix() . "einvoicing_extlinks WHERE element_id = " . self::REBUILD_ELEMENT_ID);
		$db->query("DELETE FROM " . $db->prefix() . "einvoicing_lifecycle_msg WHERE element_id = " . self::REBUILD_ELEMENT_ID);
		$einvoicing = new EInvoicing($db);
		$einvoicing->insertOrUpdateExtLink(self::REBUILD_ELEMENT_ID, 'facture', $flowId, $flowId ? EInvoicing::STATUS_AWAITING_VALIDATION : EInvoicing::STATUS_GENERATED, 'TEST-1265-0001');

		$invoice = new Facture($db);
		$invoice->id = self::REBUILD_ELEMENT_ID;
		$invoice->ref = 'TEST-1265-0001';
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->status = Facture::STATUS_VALIDATED;
		$invoice->statut = Facture::STATUS_VALIDATED;
		$invoice->thirdparty = new Societe($db);
		$invoice->thirdparty->country_code = 'FR';
		$invoice->thirdparty->tva_assuj = 1;
		$invoice->lines = array();

		$hooks = new ActionsEInvoicing($db);
		$action = '';
		$res = $hooks->afterPDFCreation(array('file' => '', 'object' => $invoice), $invoice, $action, new HookManager($db));

		return array('res' => $res, 'reported' => array_merge((array) $hooks->errors, (array) $hooks->warnings));
	}

	/**
	 * A PDF rebuilt after the transmission (a payment, the Generate button) leaves the e-invoice the AP holds
	 * alone: the hook stops before generating anything (#1265). The same invoice not yet submitted still goes
	 * to the generation, which reports here on the incomplete in-memory invoice.
	 *
	 * @return void
	 */
	public function testRebuildingThePdfOfATransmittedInvoiceKeepsItsEInvoice()
	{
		$notSubmitted = $this->rebuildPdf('');
		$this->assertNotEmpty($notSubmitted['reported'], 'an invoice never submitted goes to the generation');

		$transmitted = $this->rebuildPdf('i_1265');
		$this->assertSame(0, $transmitted['res']);
		$this->assertSame(array(), $transmitted['reported'], 'nothing is generated for a transmitted invoice');

		$this->assertNotEmpty($this->rebuildPdf('i_1265', '1')['reported'], 'EINVOICING_ALLOW_REGEN_TRANSMITTED lifts the lock');
	}
}
