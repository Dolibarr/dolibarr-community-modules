<?php
/* Copyright (C) 2026		Pierre Grasswill			<da.grumpf@gmail.com>
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
 *      \file       test/phpunit/TemporaryFilesCleanupTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the way the module disposes of its temporary files.
 *
 *                  Two places used to reach for the raw PHP functions instead of the file helpers of
 *                  the core the project asks for (.agents/AGENTS.md, "Standardization"):
 *                  EInvoicing::cleanUpTemporaryFiles() listed the module temporary directory with
 *                  scandir(), and FacturxTcpdfMerger::saveDocument() removed the XML it had written
 *                  with unlink(). Both now go through dol_dir_list() and dol_delete_file(), which is
 *                  a refactoring: what disappears from the directory has to stay the same.
 *
 *                  What is pinned here is therefore the perimeter of the cleanup - the flat files of
 *                  the directory go, a subdirectory and what it holds stay - plus the one thing that
 *                  does change and is meant to: a dot file is no longer removed, because the listing
 *                  of the core never returns one. Nothing the module writes there is hidden, and a
 *                  .htaccess dropped in a data directory is exactly what must survive a cleanup.
 *
 *      \remarks    To run this script as CLI: phpunit filename.php
 *                  Everything happens in a temporary directory of its own, never in the temporary
 *                  directory of the instance under test.
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
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
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
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class TemporaryFilesCleanupTest extends CommonClassTest
{
	/** @var string Temporary directory of this test, standing in for the one of the module */
	private $workDir = '';

	/** @var ?object Temporary directory the instance really uses, put back at the end */
	private $savedTempDir = null;

	/** @var bool Whether $conf->einvoicing had to be created here */
	private $createdConfEntry = false;

	/**
	 * Point the module temporary directory at a directory of this test, so nothing here can reach
	 * the files of the instance it runs on.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();

		$this->workDir = sys_get_temp_dir() . '/einvoicing-cleanup-' . getmypid() . '-' . uniqid();
		if (!dol_mkdir($this->workDir) && !dol_is_dir($this->workDir)) {
			throw new \RuntimeException('Cannot create the working directory ' . $this->workDir);
		}

		if (!isset($conf->einvoicing) || !is_object($conf->einvoicing)) {
			$conf->einvoicing = new stdClass();
			$this->createdConfEntry = true;
		} else {
			$this->savedTempDir = isset($conf->einvoicing->dir_temp) ? $conf->einvoicing->dir_temp : null;
		}
		$conf->einvoicing->dir_temp = $this->workDir;
	}

	/**
	 * Put the temporary directory of the instance back and remove everything this test wrote.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		if ($this->createdConfEntry) {
			unset($conf->einvoicing);
			$this->createdConfEntry = false;
		} elseif (isset($conf->einvoicing)) {
			$conf->einvoicing->dir_temp = $this->savedTempDir;
		}

		if ($this->workDir !== '' && dol_is_dir($this->workDir)) {
			dol_delete_dir_recursive($this->workDir);
		}
		$this->workDir = '';

		parent::tearDown();
	}

	/**
	 * Write a file below the working directory, creating the directories it needs.
	 *
	 * @param	string	$relative	Path relative to the working directory
	 * @return	string				Full path of the file written
	 */
	private function writeFile($relative)
	{
		$path = $this->workDir . '/' . $relative;
		$directory = dirname($path);
		if (!dol_is_dir($directory)) {
			dol_mkdir($directory);
		}
		$this->assertNotFalse(file_put_contents($path, 'phpunit'), 'Cannot write ' . $path);

		return $path;
	}

	/**
	 * The cleanup empties the module temporary directory of its files.
	 *
	 * The names are the ones the module really puts there: the working copies of an incoming document
	 * written by CIIProtocol (in_*.xml, in_*_readable.pdf), the CDAR of an outgoing status written by
	 * CdarHandler (cdar_*.xml) and the einvoice.* diagnostics of the last document that could not be
	 * imported. A name carrying glob characters is thrown in as well: dol_delete_file() reads its
	 * argument as a mask unless it is told not to, and a file listed by name must be deleted by name.
	 *
	 * @return void
	 */
	public function testCleanUpTemporaryFilesRemovesTheFilesOfTheDirectory()
	{
		global $db;

		$files = array(
			$this->writeFile('in_0123456789abcdef.xml'),
			$this->writeFile('in_0123456789abcdef_readable.pdf'),
			$this->writeFile('cdar_205_fedcba9876543210.xml'),
			$this->writeFile('einvoice.xml'),
			$this->writeFile('einvoice_readable.pdf'),
			$this->writeFile('facturx[1].xml'),
		);

		$einvoicing = new EInvoicing($db);
		$einvoicing->cleanUpTemporaryFiles();

		foreach ($files as $file) {
			$this->assertFileDoesNotExistCompat($file, basename($file) . ' was left behind by the cleanup');
		}
	}

	/**
	 * The cleanup does not descend into a subdirectory, and does not remove it either.
	 *
	 * That was already the perimeter of the scandir() loop this replaces - it only ever looked at
	 * is_file() entries of the first level - and everything the module writes in that directory is
	 * flat, so there is nothing to gain from going deeper and a directory belonging to something else
	 * to lose.
	 *
	 * @return void
	 */
	public function testCleanUpTemporaryFilesLeavesSubdirectoriesAlone()
	{
		global $db;

		$nested = $this->writeFile('subdir/kept.xml');
		$flat = $this->writeFile('in_deadbeefdeadbeef.xml');

		$einvoicing = new EInvoicing($db);
		$einvoicing->cleanUpTemporaryFiles();

		$this->assertFileDoesNotExistCompat($flat, 'The file of the first level was not removed');
		$this->assertDirectoryExists($this->workDir . '/subdir', 'The subdirectory was removed by the cleanup');
		$this->assertFileExists($nested, 'The cleanup went down into the subdirectory');
	}

	/**
	 * A dot file survives the cleanup.
	 *
	 * This is the one difference with the scandir() loop, and the reason to prefer the listing of the
	 * core: dol_dir_list() never returns an entry matching '^\.', so a .htaccess - which is what a
	 * Dolibarr data directory carries when the instance protects it - is no longer at the mercy of a
	 * cleanup. Nothing the module writes there is hidden, so nothing that had to go stays.
	 *
	 * @return void
	 */
	public function testCleanUpTemporaryFilesKeepsDotFiles()
	{
		global $db;

		$hidden = $this->writeFile('.htaccess');

		$einvoicing = new EInvoicing($db);
		$einvoicing->cleanUpTemporaryFiles();

		$this->assertFileExists($hidden, 'A dot file of the directory was removed by the cleanup');
	}

	/**
	 * The cleanup accepts a directory that does not exist, and one that is not configured.
	 *
	 * @return void
	 */
	public function testCleanUpTemporaryFilesAcceptsAMissingDirectory()
	{
		global $conf, $db;

		$einvoicing = new EInvoicing($db);

		$conf->einvoicing->dir_temp = $this->workDir . '/never-created';
		$einvoicing->cleanUpTemporaryFiles();

		$conf->einvoicing->dir_temp = '';
		$einvoicing->cleanUpTemporaryFiles();

		// Reaching here without a fatal is the assertion; state it so the test is not marked risky.
		$this->assertTrue(true);
	}

	/**
	 * The merger removes the XML file it wrote for itself.
	 *
	 * FacturxTcpdfMerger takes the XML either as a path or as content. In the second case it writes
	 * it into the temporary directory of the module, because TCPDF reads an attachment from a path,
	 * and that file has to be gone once the merged document is written. Handing over the content is
	 * what reaches the branch under test - the fixture is passed as a string, not as a file name.
	 *
	 * @return void
	 */
	public function testMergerRemovesItsTemporaryXml()
	{
		if (!class_exists('TCPDF', false)) {
			// TCPDF is shipped by Dolibarr, not by this repository, and the merger descends from it.
			// Loading the PDF stack of the core is what FacturXProtocol does right before it picks
			// this merger, so the class is exercised in the state it really runs in.
			require_once DOL_DOCUMENT_ROOT . '/core/lib/pdf.lib.php';
			pdf_getInstance();
		}
		if (!class_exists('TCPDF', false)) {
			$this->markTestSkipped('The PDF stack of the core did not bring TCPDF in');
		}
		require_once dirname(__FILE__) . '/../../class/utils/FacturxTcpdfMerger.class.php';

		$fixtures = (array) glob(dirname(__FILE__) . '/fixtures/einvoicing_samples/*.xml');
		$this->assertNotEmpty($fixtures, 'No XML fixture to embed');
		$xml = (string) file_get_contents((string) $fixtures[0]);

		// The carrier is the PDF shipped by horstoeko/zugferd, as FacturxPdfaContainerTest uses.
		$carrier = dirname(__FILE__) . '/../../doc/00_ZugferdDocumentPdfBuilder_PrintLayout.pdf';
		$this->assertFileExists($carrier, 'No carrier PDF to merge into');

		$merger = new FacturxTcpdfMerger($xml, $carrier);
		$merger->setKeywordTemplate('');
		$merger->setSubjectTemplate('');
		$merger->setAuthorTemplate('');
		$merger->setAdditionalCreatorTool('');

		$merger->generateDocument();

		// The XML was handed over as content, so the merger had to write it down to attach it.
		$written = (array) glob($this->workDir . '/facturx*');
		$this->assertCount(1, $written, 'The merger did not write the XML it was given as content');

		$merger->saveDocument($this->workDir . '/merged.pdf');

		$this->assertFileExists($this->workDir . '/merged.pdf', 'The merger wrote no document');
		$this->assertCount(0, (array) glob($this->workDir . '/facturx*'), 'The merger left its temporary XML behind');
	}

	/**
	 * assertFileDoesNotExist() only exists from PHPUnit 9.1, and the instances this module is tested
	 * on do not all carry the same runner.
	 *
	 * @param	string	$file		Full path of the file that must be gone
	 * @param	string	$message	Failure message
	 * @return	void
	 */
	private function assertFileDoesNotExistCompat($file, $message = '')
	{
		if (method_exists($this, 'assertFileDoesNotExist')) {
			$this->assertFileDoesNotExist($file, $message);
			return;
		}
		$this->assertFileNotExists($file, $message);		// @phan-suppress-current-line PhanDeprecatedFunction
	}
}
