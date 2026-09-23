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
 *      \file       test/phpunit/DocumentTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the Document class: the confirmation of the import made again of a
 *                  received flow names the files attached by hand that it deletes (issue #1022).
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
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
dol_include_once('einvoicing/class/document.class.php');
dol_include_once('einvoicing/class/protocols/CIIProtocol.class.php');
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
class DocumentTest extends CommonClassTest
{
	/** @var string[] Directories written on disk by a test, which the transaction does not undo */
	private $dirsToRemove = array();

	/**
	 * Remove what a test wrote on disk: the database is rolled back, the documents directory is not.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		foreach ($this->dirsToRemove as $dir) {
			if (is_dir($dir)) {
				dol_delete_dir_recursive($dir, 0, 1);
			}
		}
		$this->dirsToRemove = array();

		parent::tearDown();
	}

	/**
	 * Return the id of any existing third party, so the fixtures do not depend on the demo data.
	 *
	 * @return int	Id of an existing third party
	 */
	private function getAnyThirdpartyId()
	{
		global $db;

		$resql = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE entity IN (" . getEntity('societe') . ")" . $db->plimit(1));
		$this->assertNotFalse($resql, (string) $db->lasterror());
		$obj = $db->fetch_object($resql);
		$this->assertNotNull($obj, 'No third party on this instance to book a supplier invoice on');

		return (int) $obj->rowid;
	}

	/**
	 * A draft supplier invoice, and the incoming flow record it was imported from.
	 *
	 * @return array{0:FactureFournisseur,1:Document}	The invoice and its flow record
	 */
	private function createReceivedDraft()
	{
		global $conf, $db, $user;

		$invoice = new FactureFournisseur($db);
		$invoice->initAsSpecimen();
		$invoice->ref_supplier = 'PHPUNIT-1022W-' . uniqid();
		$invoice->socid = $this->getAnyThirdpartyId();
		$this->assertGreaterThan(0, $invoice->create($user), $invoice->errorsToString());
		$this->assertGreaterThan(0, $invoice->fetch($invoice->id));

		$now = $db->idate(dol_now());
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "einvoicing_document";
		$sql .= " (entity, fk_element_type, fk_element_id, flow_id, flow_type, flow_direction, date_creation, fk_user_creat, status, submittedat, provider)";
		$sql .= " VALUES (" . ((int) $conf->entity) . ", 'invoice_supplier', " . ((int) $invoice->id) . ", ";
		$sql .= "'PHPUNIT-1022W-" . uniqid() . "', 'SupplierInvoice', 'In', '" . $now . "', 1, 0, '" . $now . "', 'PHPUNIT')";
		$this->assertNotFalse($db->query($sql), (string) $db->lasterror());

		$doc = new Document($db);
		$this->assertGreaterThan(0, $doc->fetch((int) $db->last_insert_id(MAIN_DB_PREFIX . 'einvoicing_document')));

		$this->dirsToRemove[] = $this->dirOf($invoice);

		return array($invoice, $doc);
	}

	/**
	 * Directory of the documents of a supplier invoice, the one its deletion removes.
	 *
	 * @param	FactureFournisseur	$invoice	Invoice
	 * @return	string							Full path
	 */
	private function dirOf(FactureFournisseur $invoice)
	{
		global $conf;

		return $conf->fournisseur->facture->dir_output . '/' . get_exdir($invoice->id, 2, 0, 0, $invoice, 'invoice_supplier') . dol_sanitizeFileName($invoice->ref);
	}

	/**
	 * Write a file into the documents of an invoice and index it the way its writer does.
	 *
	 * @param	FactureFournisseur	$invoice		Invoice the file is attached to
	 * @param	string				$name			File name
	 * @param	string				$mode			gen_or_uploaded of the index entry
	 * @param	string				$description	Description of the index entry
	 * @return	void
	 */
	private function attach(FactureFournisseur $invoice, $name, $mode, $description = '')
	{
		global $db;

		$dir = $this->dirOf($invoice);
		dol_mkdir($dir);
		$this->assertNotFalse(file_put_contents($dir . '/' . $name, 'content of ' . $name));
		$this->assertGreaterThan(0, addFileIntoDatabaseIndex($dir, $name, $name, $mode, 0, $invoice));
		if ($description !== '') {
			$relative = preg_replace('/^' . preg_quote(DOL_DATA_ROOT . '/', '/') . '/', '', $dir);
			$sql = "UPDATE " . MAIN_DB_PREFIX . "ecm_files SET description = '" . $db->escape($description) . "'";
			$sql .= " WHERE filepath = '" . $db->escape($relative) . "' AND filename = '" . $db->escape($name) . "'";
			$this->assertNotFalse($db->query($sql), (string) $db->lasterror());
		}
	}

	/**
	 * Only the files attached by hand are named: the files of the import, whatever the core recorded
	 * as their origin, and the PDF generated from the draft are written again or not missed.
	 *
	 * @return void
	 */
	public function testOnlyTheFilesAttachedByHandAreNamed()
	{
		list($invoice, $doc) = $this->createReceivedDraft();
		$this->attach($invoice, 'delivery_note.pdf', 'uploaded');
		$this->attach($invoice, dol_sanitizeFileName($invoice->ref) . '.pdf', 'generated');
		$this->attach($invoice, 'vendor_einvoice.xml', 'imported', CIIProtocol::IMPORTED_FILE_DESCRIPTION);
		// Below Dolibarr 21 dol_move() ignores the origin it is given: only the description says it.
		$this->attach($invoice, 'vendor_SUPERPDP.pdf', 'uploaded', CIIProtocol::IMPORTED_FILE_DESCRIPTION);
		// Put there without going through Dolibarr: no index entry, lost all the same.
		$this->assertNotFalse(file_put_contents($this->dirOf($invoice) . '/scan.jpg', 'scan'));

		$lost = $doc->getFilesLostByReimport();
		sort($lost);
		$this->assertSame(array('delivery_note.pdf', 'scan.jpg'), $lost);
	}

	/**
	 * A draft holding only what the import wrote loses nothing.
	 *
	 * @return void
	 */
	public function testNothingIsNamedWhenOnlyTheImportWroteFiles()
	{
		list($invoice, $doc) = $this->createReceivedDraft();
		$this->attach($invoice, 'vendor_einvoice.xml', 'imported', CIIProtocol::IMPORTED_FILE_DESCRIPTION);

		$this->assertSame(array(), $doc->getFilesLostByReimport());
	}

	/**
	 * A flow whose draft is gone has nothing to lose.
	 *
	 * @return void
	 */
	public function testNothingIsNamedWithoutAnInvoice()
	{
		list($invoice, $doc) = $this->createReceivedDraft();
		$this->attach($invoice, 'delivery_note.pdf', 'uploaded');
		$doc->fk_element_id = 0;

		$this->assertSame(array(), $doc->getFilesLostByReimport());
	}
}
