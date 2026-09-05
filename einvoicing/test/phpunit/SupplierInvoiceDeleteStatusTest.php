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
 *      \file       test/phpunit/SupplierInvoiceDeleteStatusTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the BILL_SUPPLIER_DELETE trigger of the module: the supplier
 *                  invoice built from a received e-invoice may only be deleted while it is a draft.
 *                  The status that decides is re-read from the invoice itself - the object a trigger
 *                  is handed is not always freshly fetched - through the core class rather than by a
 *                  query of the module on llx_facture_fourn. This test pins both halves: the answer
 *                  follows the database and not the object handed over, and the property the core
 *                  fills at fetch() is the one being read on every supported Dolibarr version.
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
class SupplierInvoiceDeleteStatusTest extends CommonClassTest
{
	/** @var InterfaceEInvoicingTriggers Trigger of the last runDeleteTrigger() call, for its errors */
	private $lasttrigger;

	/**
	 * Ask the trigger whether a supplier invoice may be deleted, the way FactureFournisseur::delete()
	 * does.
	 *
	 * @param	FactureFournisseur	$invoice	Supplier invoice being deleted
	 * @return	int								0 if the deletion is allowed, -1 if the trigger refuses it
	 */
	private function runDeleteTrigger($invoice)
	{
		global $conf, $db, $langs, $user;

		$this->lasttrigger = new InterfaceEInvoicingTriggers($db);

		return $this->lasttrigger->runTrigger('BILL_SUPPLIER_DELETE', $invoice, $user, $langs, $conf);
	}

	/**
	 * Return the id of any existing third party, so the fixtures do not depend on the demo data
	 * being present.
	 *
	 * @return int	Id of an existing third party
	 */
	private function getAnyThirdpartyId()
	{
		global $db;

		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "societe";
		$sql .= " WHERE entity IN (" . getEntity('societe') . ")";
		$sql .= $db->plimit(1);

		$resql = $db->query($sql);
		$this->assertNotFalse($resql, (string) $db->lasterror());
		$obj = $db->fetch_object($resql);
		$this->assertNotNull($obj, 'No third party on this instance to book a supplier invoice on');

		return (int) $obj->rowid;
	}

	/**
	 * Create a supplier invoice that the module considers an e-invoice: an ordinary draft plus the
	 * incoming flow it was imported from, which is what makes the trigger take the branch under test.
	 *
	 * @return FactureFournisseur	The invoice created, still a draft
	 */
	private function createEInvoiceSupplierInvoice()
	{
		global $db, $user;

		$invoice = new FactureFournisseur($db);
		$invoice->initAsSpecimen();
		$invoice->ref_supplier = 'SUPPLIER_REF_DELSTATUS_' . uniqid();
		// initAsSpecimen() hardcodes socid = 1, which only exists on an instance still carrying the
		// demo data, so resolve an existing third party instead.
		$invoice->socid = $this->getAnyThirdpartyId();
		$this->assertGreaterThan(0, $invoice->create($user), $invoice->errorsToString());

		$this->addIncomingFlow((int) $invoice->id);

		return $invoice;
	}

	/**
	 * Book an incoming supplier invoice flow on an id, which is what makes the module answer yes to
	 * "is this supplier invoice an e-invoice" and the trigger take the branch under test.
	 *
	 * @param	int		$supplierInvoiceId	Supplier invoice the flow is booked on
	 * @return	void
	 */
	private function addIncomingFlow($supplierInvoiceId)
	{
		global $conf, $db;

		$now = $db->idate(dol_now());

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "einvoicing_document";
		$sql .= " (entity, fk_element_type, fk_element_id, flow_id, flow_type, flow_direction, date_creation, fk_user_creat, status, submittedat, provider)";
		$sql .= " VALUES (" . ((int) $conf->entity) . ", 'invoice_supplier', " . ((int) $supplierInvoiceId) . ", ";
		$sql .= "'PHPUNIT-DELSTATUS-" . uniqid() . "', 'SupplierInvoice', 'In', '" . $now . "', 1, 0, '" . $now . "', 'PHPUNIT')";

		$this->assertNotFalse($db->query($sql), (string) $db->lasterror());
	}

	/**
	 * Number of incoming flows still booked on a supplier invoice, to tell a flow that was detached
	 * by the trigger from one that was left alone.
	 *
	 * @param	int		$supplierInvoiceId	Supplier invoice the flows are booked on
	 * @return	int							How many flows still carry that id
	 */
	private function countFlowsOn($supplierInvoiceId)
	{
		global $db;

		$sql = "SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . "einvoicing_document";
		$sql .= " WHERE fk_element_type = 'invoice_supplier'";
		$sql .= " AND fk_element_id = " . ((int) $supplierInvoiceId);

		$resql = $db->query($sql);
		$this->assertNotFalse($resql, (string) $db->lasterror());
		$obj = $db->fetch_object($resql);

		return (int) $obj->nb;
	}

	/**
	 * The contract this trigger relies on, pinned here because it is the one thing that could differ
	 * between the Dolibarr versions the module supports (18 to 24): fetch() selects "fk_statut as
	 * status" and fills ->status, ->statut being only a backward compatibility alias (@deprecated
	 * since 19), and the draft constant is the value stored in the column.
	 *
	 * @return void
	 */
	public function testCoreFillsTheStatusPropertyAtFetch()
	{
		global $db;

		$invoice = $this->createEInvoiceSupplierInvoice();

		$reread = new FactureFournisseur($db);
		$this->assertGreaterThan(0, $reread->fetch((int) $invoice->id));

		$this->assertSame(0, FactureFournisseur::STATUS_DRAFT);
		$this->assertNotNull($reread->status, 'fetch() must fill ->status, which is the property the trigger reads');
		$this->assertSame(FactureFournisseur::STATUS_DRAFT, (int) $reread->status);
	}

	/**
	 * A draft is a local booking that says nothing to the platform, so it stays deletable, and the
	 * incoming flow it came from is detached rather than lost: it must remain in the flow list, with
	 * no invoice id, so the document can be imported again.
	 *
	 * @return void
	 */
	public function testDraftEInvoiceCanBeDeleted()
	{
		$invoice = $this->createEInvoiceSupplierInvoice();

		$this->assertSame(1, $this->countFlowsOn((int) $invoice->id));

		$this->assertSame(0, $this->runDeleteTrigger($invoice));
		$this->assertSame(array(), $this->lasttrigger->errors);

		$this->assertSame(0, $this->countFlowsOn((int) $invoice->id), 'The flow must have been detached, not deleted with the invoice');
	}

	/**
	 * Once validated the invoice is in the accounts and an answer has been owed to the vendor, so
	 * the deletion is refused with the message that says so - and the flow is left booked on it.
	 *
	 * @return void
	 */
	public function testValidatedEInvoiceCannotBeDeleted()
	{
		global $langs, $user;

		$invoice = $this->createEInvoiceSupplierInvoice();
		$this->assertGreaterThan(0, $invoice->validate($user), $invoice->errorsToString());

		$this->assertSame(-1, $this->runDeleteTrigger($invoice));
		$this->assertContains($langs->trans('EinvoicingCantDeleteAValidatedSupplierInvoice'), $this->lasttrigger->errors);

		$this->assertSame(1, $this->countFlowsOn((int) $invoice->id), 'A refused deletion must leave the flow booked on its invoice');
	}

	/**
	 * The reason the status is re-read at all: the object a trigger is handed is not always freshly
	 * fetched. An invoice validated in database is refused even when the object still says draft.
	 *
	 * @return void
	 */
	public function testStaleObjectSayingDraftIsStillRefused()
	{
		global $langs, $user;

		$invoice = $this->createEInvoiceSupplierInvoice();
		$this->assertGreaterThan(0, $invoice->validate($user), $invoice->errorsToString());

		// What a stale object looks like, on both the current property and the deprecated alias
		$invoice->status = FactureFournisseur::STATUS_DRAFT;
		$invoice->statut = FactureFournisseur::STATUS_DRAFT;

		$this->assertSame(-1, $this->runDeleteTrigger($invoice));
		$this->assertContains($langs->trans('EinvoicingCantDeleteAValidatedSupplierInvoice'), $this->lasttrigger->errors);
	}

	/**
	 * The same re-read the other way round: a draft in database stays deletable even when the object
	 * handed over carries a stale validated status.
	 *
	 * @return void
	 */
	public function testStaleObjectSayingValidatedIsStillDeletable()
	{
		$invoice = $this->createEInvoiceSupplierInvoice();

		$invoice->status = FactureFournisseur::STATUS_VALIDATED;
		$invoice->statut = FactureFournisseur::STATUS_VALIDATED;

		$this->assertSame(0, $this->runDeleteTrigger($invoice));
		$this->assertSame(array(), $this->lasttrigger->errors);
	}

	/**
	 * A supplier invoice that cannot be read back is not an e-invoice as far as this module is
	 * concerned - SupplierInvoiceHelper::isEInvoice() is asked to check the existence of the linked
	 * object and answers no - so the trigger keeps out of the deletion, exactly as it did when the
	 * status came from a query of its own that returned no row.
	 *
	 * @return void
	 */
	public function testUnknownSupplierInvoiceIsLeftToTheCore()
	{
		global $db;

		// An id no supplier invoice ever had, so the flow points at nothing readable
		$sql = "SELECT MAX(rowid) as maxid FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$resql = $db->query($sql);
		$this->assertNotFalse($resql, (string) $db->lasterror());
		$obj = $db->fetch_object($resql);
		$missingid = ((int) $obj->maxid) + 1000;

		$this->addIncomingFlow($missingid);

		$ghost = new FactureFournisseur($db);
		$ghost->id = $missingid;

		$this->assertSame(0, $this->runDeleteTrigger($ghost));
		$this->assertSame(array(), $this->lasttrigger->errors);
	}
}
