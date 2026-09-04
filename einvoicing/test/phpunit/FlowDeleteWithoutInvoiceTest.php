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
 *      \file       test/phpunit/FlowDeleteWithoutInvoiceTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the DOCUMENT_DELETE trigger of the module (issue #766): a flow
 *                  that carries fk_element_type 'invoice_supplier' does not always carry a supplier
 *                  invoice id. A lifecycle message ('SupplierInvoiceLC') whose invoice could not be
 *                  resolved leaves the column NULL, and deleting such a record from the
 *                  synchronization list used to end on an uncaught TypeError. The protection of a
 *                  flow that really is linked to a supplier invoice must stay in place.
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
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
dol_include_once('einvoicing/class/document.class.php');
dol_include_once('einvoicing/core/triggers/interface_98_modEInvoicing_EInvoicingTriggers.class.php');
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
class FlowDeleteWithoutInvoiceTest extends CommonClassTest
{
	/** @var InterfaceEInvoicingTriggers Trigger of the last runDeleteTrigger() call, for its errors */
	private $lasttrigger;

	/**
	 * Ask the trigger whether a flow may be deleted, the way Document::delete() does.
	 *
	 * @param	Document	$doc	Flow being deleted
	 * @return	int					0 if the deletion is allowed, -1 if the trigger refuses it
	 */
	private function runDeleteTrigger($doc)
	{
		global $conf, $db, $langs, $user;

		$this->lasttrigger = new InterfaceEInvoicingTriggers($db);

		return $this->lasttrigger->runTrigger('DOCUMENT_DELETE', $doc, $user, $langs, $conf);
	}

	/**
	 * Insert a flow row and return it read back from the database, so the properties under test hold
	 * what a real deletion sees rather than what this fixture assigned. The whole class runs inside
	 * the transaction opened by CommonClassTest::setUpBeforeClass(), so nothing survives the run.
	 *
	 * @param	string	$flowType			Value of the flow_type column
	 * @param	?int	$supplierInvoiceId	Supplier invoice the flow is booked on, null for none
	 * @return	Document					The flow, fetched back
	 */
	private function insertFlow($flowType, $supplierInvoiceId)
	{
		global $conf, $db;

		$now = $db->idate(dol_now());

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "einvoicing_document";
		$sql .= " (entity, fk_element_type, fk_element_id, flow_id, flow_type, flow_direction, date_creation, fk_user_creat, status, submittedat, provider)";
		$sql .= " VALUES (" . ((int) $conf->entity) . ", 'invoice_supplier', ";
		$sql .= (is_null($supplierInvoiceId) ? "NULL" : ((int) $supplierInvoiceId)) . ", ";
		$sql .= "'PHPUNIT-766-" . uniqid() . "', '" . $db->escape($flowType) . "', 'In', '" . $now . "', 1, 0, '" . $now . "', 'PHPUNIT')";

		$this->assertNotFalse($db->query($sql), (string) $db->lasterror());

		$doc = new Document($db);
		$this->assertGreaterThan(0, $doc->fetch((int) $db->last_insert_id(MAIN_DB_PREFIX . 'einvoicing_document')));

		return $doc;
	}

	/**
	 * Create a supplier invoice to book a flow on. initAsSpecimen() hardcodes socid = 1, which only
	 * exists on an instance still carrying the demo data, so resolve an existing third party instead.
	 *
	 * @return FactureFournisseur	The invoice created
	 */
	private function createSpecimenSupplierInvoice()
	{
		global $db, $user;

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE entity IN (" . getEntity('societe') . ")" . $db->plimit(1);
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, (string) $db->lasterror());
		$obj = $db->fetch_object($resql);
		$this->assertNotNull($obj, 'No third party on this instance to book a supplier invoice on');

		$invoice = new FactureFournisseur($db);
		$invoice->initAsSpecimen();
		$invoice->ref_supplier = 'SUPPLIER_REF_766_' . uniqid();
		$invoice->socid = (int) $obj->rowid;
		$this->assertGreaterThan(0, $invoice->create($user), $invoice->errorsToString());

		return $invoice;
	}

	/**
	 * The regression of issue #766: a lifecycle flow never resolved its supplier invoice, so its
	 * fk_element_id is NULL. Deleting it from the synchronization list must be allowed - there is no
	 * invoice to protect - and above all must not raise, which is what the mass deletion of the list
	 * used to end on (TypeError on the int parameter of SupplierInvoiceHelper::isEInvoice()).
	 *
	 * @return void
	 */
	public function testFlowWithoutSupplierInvoiceIdCanBeDeleted()
	{
		$doc = $this->insertFlow('SupplierInvoiceLC', null);

		$this->assertNull($doc->fk_element_id, 'The fixture must read back a NULL supplier invoice id');
		$this->assertSame(0, $this->runDeleteTrigger($doc));
		$this->assertSame(array(), $this->lasttrigger->errors);
	}

	/**
	 * Same answer for a flow detached by the deletion of its supplier invoice: the BILL_SUPPLIER_DELETE
	 * trigger sets the column to 0 rather than NULL, and that case already worked - it must keep doing so.
	 *
	 * @return void
	 */
	public function testDetachedFlowCanBeDeleted()
	{
		$doc = $this->insertFlow('SupplierInvoiceLC', 0);

		$this->assertSame(0, $this->runDeleteTrigger($doc));
		$this->assertSame(array(), $this->lasttrigger->errors);
	}

	/**
	 * The protection itself: a flow booked on a supplier invoice that still exists, with a more recent
	 * flow after it, is refused with a message rather than deleted.
	 *
	 * @return void
	 */
	public function testFlowLinkedToAnExistingSupplierInvoiceIsStillRefused()
	{
		$invoice = $this->createSpecimenSupplierInvoice();

		$doc = $this->insertFlow('SupplierInvoice', (int) $invoice->id);

		// The trigger accepts the deletion of the last flow of the table (it comes back at the next
		// synchronization), so the record under test must not be the last one.
		$this->insertFlow('SupplierInvoiceLC', null);

		$this->assertSame(-1, $this->runDeleteTrigger($doc));
		$this->assertNotEmpty($this->lasttrigger->errors);
	}
}
