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
 *      \file       test/phpunit/SupportExportTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the "Export for support" mass action of the flow list and of the
 *                  API call log. What has to hold whatever else changes: an archive built to be sent
 *                  to a maintainer carries no credential, and its manifest alone says which core,
 *                  which module build, which platform and which entity it was taken from - debug
 *                  mode included, without which an empty response cannot be told from a response
 *                  that was never stored.
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// See SupplierInvoiceHelperTest.php for why DOLIBARR_HTDOCS is honoured before the relative path.
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
dol_include_once('einvoicing/class/utils/SupportExport.class.php');
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
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class SupportExportTest extends CommonClassTest
{
	/** @var string Directory the archives of the run are written into */
	private $destdir;

	/**
	 * Give each test a directory of its own, outside the mass generation area of a real user.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();

		$this->destdir = $conf->einvoicing->dir_output . '/temp/phpunit-supportexport-' . getmypid();
		dol_mkdir($this->destdir);
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		if (!empty($this->destdir) && dol_is_dir($this->destdir)) {
			dol_delete_dir_recursive($this->destdir);
		}

		parent::tearDown();
	}

	/**
	 * Insert a row in the API call log, the way AbstractPDPProvider::logCall() does but WITHOUT its
	 * redaction, which is the whole point: this is what a row written before that redaction existed
	 * looks like, and such rows are still in the log of every instance updated since.
	 *
	 * @param	string	$requestBody	Value of the request_body column
	 * @param	string	$response		Value of the response column
	 * @return	int						Rowid of the inserted call
	 */
	private function insertCall($requestBody, $response)
	{
		global $conf, $db;

		$now = $db->idate(dol_now());

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "einvoicing_call";
		$sql .= " (entity, call_id, provider, call_type, method, endpoint, request_body, response,";
		$sql .= " status, skippedflow, successflow, totalflow, batchlimit, date_creation, fk_user_creat)";
		$sql .= " VALUES (" . ((int) $conf->entity) . ", 'PHPUNIT-" . uniqid() . "', 'PHPUNIT', 'OAuth', 'POST',";
		$sql .= " '/oauth/token', '" . $db->escape($requestBody) . "', '" . $db->escape($response) . "',";
		$sql .= " 0, 0, 0, 0, 0, '" . $now . "', 1)";

		$this->assertNotFalse($db->query($sql), (string) $db->lasterror());

		return (int) $db->last_insert_id(MAIN_DB_PREFIX . 'einvoicing_call');
	}

	/**
	 * Insert a flow row.
	 *
	 * @param	string	$documentBody	Value of the document_body column, '' for a flow that carries none
	 * @return	int						Rowid of the inserted flow
	 */
	private function insertFlow($documentBody)
	{
		global $conf, $db;

		$now = $db->idate(dol_now());

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "einvoicing_document";
		$sql .= " (entity, provider, flow_id, flow_type, flow_direction, document_body, submittedat, date_creation, fk_user_creat, status)";
		$sql .= " VALUES (" . ((int) $conf->entity) . ", 'PHPUNIT', 'PHPUNIT-" . uniqid() . "', 'CustomerInvoice', 'Out',";
		$sql .= " '" . $db->escape($documentBody) . "', '" . $now . "', '" . $now . "', 1, 0)";

		$this->assertNotFalse($db->query($sql), (string) $db->lasterror());

		return (int) $db->last_insert_id(MAIN_DB_PREFIX . 'einvoicing_document');
	}

	/**
	 * Read an archive back as a map "entry name => content", so a test can assert on the shape of
	 * the archive and on every byte it carries at once.
	 *
	 * @param	string					$zipfile	Archive built by SupportExport::build()
	 * @return	array<string,string>				Content of each entry
	 */
	private function readArchive($zipfile)
	{
		$this->assertNotSame('', $zipfile, 'No archive was built');
		$this->assertFileExists($zipfile);

		$zip = new ZipArchive();
		$this->assertTrue($zip->open($zipfile) === true, 'The archive could not be reopened');

		$entries = array();
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			$entries[$name] = (string) $zip->getFromIndex($i);
		}
		$zip->close();

		return $entries;
	}

	/**
	 * A token and a client secret left in an old row of the call log must not come out of the
	 * archive, in any of its files.
	 *
	 * @return void
	 */
	public function testCredentialsOfAnOldCallLogRowDoNotLeaveTheInstance()
	{
		global $db;

		$token = 'phpunit-access-token-a1b2c3d4e5';
		$secret = 'phpunit-client-secret-f6g7h8';

		$id = $this->insertCall(
			'grant_type=client_credentials&client_id=someclient&client_secret=' . $secret,
			'{"access_token":"' . $token . '","refresh_token":"' . $token . '-r","token_type":"Bearer","expires_in":3600}'
		);

		$export = new SupportExport($db);
		$entries = $this->readArchive($export->build(SupportExport::TYPE_CALL, array($id), $this->destdir));

		$whole = implode("\n", $entries);
		$this->assertStringNotContainsString($token, $whole, 'The access token came out of the archive');
		$this->assertStringNotContainsString($secret, $whole, 'The client secret came out of the archive');
		$this->assertStringContainsString('[REDACTED]', $whole, 'Nothing was redacted at all, so the assertions above prove nothing');

		// The rest of the call must still be there, otherwise the export is useless.
		$this->assertStringContainsString('/oauth/token', $whole);
		$this->assertStringContainsString('expires_in', $whole);
	}

	/**
	 * The manifest alone must answer the questions a maintainer asks first.
	 *
	 * @return void
	 */
	public function testManifestNamesTheContextOfTheExport()
	{
		global $conf, $db;

		$conf->global->EINVOICING_DEBUG_MODE = '1';

		$id = $this->insertCall('{}', '{}');

		$export = new SupportExport($db);
		$entries = $this->readArchive($export->build(SupportExport::TYPE_CALL, array($id), $this->destdir));

		$this->assertArrayHasKey('manifest.json', $entries);
		$manifest = json_decode($entries['manifest.json'], true);
		$this->assertIsArray($manifest, 'manifest.json is not readable JSON');

		$this->assertSame(DOL_VERSION, $manifest['dolibarr_version']);
		$this->assertSame(einvoicingModuleStamp(), $manifest['einvoicing_version']);
		$this->assertSame(PHP_VERSION, $manifest['php_version']);
		$this->assertSame((int) $conf->entity, $manifest['entity']);
		$this->assertSame(getDolGlobalString('EINVOICING_PDP'), $manifest['provider']);
		$this->assertSame(SupportExport::TYPE_CALL, $manifest['export_type']);
		$this->assertTrue($manifest['debug_mode'], 'The manifest must say the debug columns could be written');
		$this->assertSame(1, $manifest['exported_count']);

		$conf->global->EINVOICING_DEBUG_MODE = '0';
		$entries = $this->readArchive($export->build(SupportExport::TYPE_CALL, array($id), $this->destdir));
		$manifest = json_decode($entries['manifest.json'], true);
		$this->assertFalse($manifest['debug_mode'], 'An empty response must be tellable from a response never stored');
	}

	/**
	 * A setup constant that holds a credential is reported as set, never quoted.
	 *
	 * @return void
	 */
	public function testSecretSetupConstantsAreReportedByTheirStateOnly()
	{
		global $conf, $db;

		$conf->global->EINVOICING_SUPERPDP_CLIENT_SECRET = 'phpunit-setup-secret-z9y8x7';
		$conf->global->EINVOICING_SUPERPDP_PROD_TOKEN = 'phpunit-setup-token-w6v5u4';
		$conf->global->EINVOICING_FLOWS_SYNC_CALL_LIMIT = '25';

		$id = $this->insertCall('{}', '{}');

		$export = new SupportExport($db);
		$entries = $this->readArchive($export->build(SupportExport::TYPE_CALL, array($id), $this->destdir));
		$manifest = json_decode($entries['manifest.json'], true);

		$this->assertSame(SupportExport::CONST_SET, $manifest['constants']['EINVOICING_SUPERPDP_CLIENT_SECRET']);
		$this->assertSame(SupportExport::CONST_SET, $manifest['constants']['EINVOICING_SUPERPDP_PROD_TOKEN']);
		$this->assertStringNotContainsString('phpunit-setup-secret-z9y8x7', $entries['manifest.json']);
		$this->assertStringNotContainsString('phpunit-setup-token-w6v5u4', $entries['manifest.json']);

		// A constant that is not a credential keeps its value, or the manifest tells nothing useful.
		$this->assertSame('25', $manifest['constants']['EINVOICING_FLOWS_SYNC_CALL_LIMIT']);
	}

	/**
	 * A flow carries its XML as a file of its own, and a flow that has none is still exported.
	 *
	 * @return void
	 */
	public function testFlowsAreExportedWithAndWithoutTheirDocument()
	{
		global $db;

		$xml = '<?xml version="1.0" encoding="UTF-8"?><rsm:CrossIndustryInvoice><phpunit>1</phpunit></rsm:CrossIndustryInvoice>';

		$withbody = $this->insertFlow($xml);
		$withoutbody = $this->insertFlow('');

		$export = new SupportExport($db);
		$entries = $this->readArchive($export->build(SupportExport::TYPE_FLOW, array($withbody, $withoutbody), $this->destdir));

		$this->assertSame(2, $export->report['exported']);
		$this->assertSame(0, $export->report['skipped']);

		$documents = preg_grep('#^flows/.*/document\.xml$#', array_keys($entries));
		$rows = preg_grep('#^flows/.*/flow\.json$#', array_keys($entries));
		$this->assertCount(2, $rows, 'Every selected flow must have its row in the archive');
		$this->assertCount(1, $documents, 'Only the flow that carries an XML may have a document.xml');
		$this->assertSame($xml, $entries[reset($documents)]);

		// The row keeps the columns a maintainer reads, and leaves the body out of the JSON.
		$flow = json_decode($entries[reset($rows)], true);
		$this->assertIsArray($flow);
		$this->assertArrayNotHasKey('document_body', $flow);
		$this->assertSame('Out', $flow['flow_direction']);
		$this->assertSame('CustomerInvoice', $flow['flow_type']);
	}

	/**
	 * A selection wider than the bound is refused with a message, rather than met with a timeout
	 * or a memory limit.
	 *
	 * @return void
	 */
	public function testASelectionOverTheBoundIsRefused()
	{
		global $conf, $db;

		$conf->global->EINVOICING_SUPPORT_EXPORT_MAX = 2;
		$this->assertSame(2, SupportExport::maxRecords());

		$export = new SupportExport($db);
		$this->assertSame('', $export->build(SupportExport::TYPE_FLOW, array(1, 2, 3), $this->destdir));
		$this->assertNotSame('', $export->error, 'The refusal must carry a message for the user');

		$this->assertCount(0, (array) dol_dir_list($this->destdir, 'files'), 'A refused export must leave nothing behind');

		unset($conf->global->EINVOICING_SUPPORT_EXPORT_MAX);
	}

	/**
	 * An id that does not exist, or that belongs to another entity, is counted as skipped instead
	 * of aborting the whole export.
	 *
	 * @return void
	 */
	public function testAnUnreachableRecordIsSkippedWithoutLosingTheOthers()
	{
		global $db;

		$id = $this->insertFlow('');

		$export = new SupportExport($db);
		$entries = $this->readArchive($export->build(SupportExport::TYPE_FLOW, array($id, 999999999), $this->destdir));

		$this->assertSame(1, $export->report['exported']);
		$this->assertSame(1, $export->report['skipped']);

		$manifest = json_decode($entries['manifest.json'], true);
		$this->assertSame(2, $manifest['requested_count']);
		$this->assertSame(1, $manifest['skipped_count']);
	}
}
