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
 *      \file       test/phpunit/EInvoicingTriggersTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the triggers of the module: DOCUMENT_DELETE and
 *                  BILL_SUPPLIER_DELETE, which refuse a deletion the module must protect, the gates
 *                  of the payment status (212) PAYMENT_CUSTOMER_CREATE shares with the invoice card, and
 *                  COMPANY_MODIFY, which stores the default product of a vendor.
 *                  Covers issues #766, #791 and the delete guard of a received e-invoice.
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
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
dol_include_once('einvoicing/class/einvoicing.class.php');
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
class EInvoicingTriggersTest extends CommonClassTest
{
	/** @var int Element id of the in-memory invoices of the payment status tests: no real invoice uses it */
	const CASH_IN_ELEMENT_ID = 999999212;

	/** @var array<string,?string> Setup of the instance a test changed, given back by tearDown() */
	private $savedConsts = array();

	/**
	 * Forget the field of the form between two tests, so a test never reads what another one posted.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		unset($_POST['routing_product_id']);
		unset($_POST['routing_product_id_shown']);
		unset($_GET['routing_product_id']);
		unset($_GET['routing_product_id_shown']);

		// Give the setup of the instance back, whatever a test of the payment status set
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
	 * A user that owns a row of its own, to create the objects of the test with.
	 *
	 * The global $user of the suite does not always hold one, and Dolibarr 18 declares a foreign key
	 * on the author of a product price: the creation would then fail on the database instead of
	 * telling anything about the module.
	 *
	 * @return User		Author of the objects created by the test
	 */
	private function author()
	{
		global $db;

		$resql = $db->query('SELECT rowid FROM ' . $db->prefix() . 'user ORDER BY rowid ASC LIMIT 1');
		$obj = ($resql ? $db->fetch_object($resql) : null);
		$this->assertNotEmpty($obj, 'No user in the base to create the objects of the test with');

		$author = new User($db);
		$author->fetch($obj->rowid);

		return $author;
	}

	/**
	 * A vendor holding a default product for the import of its invoices.
	 *
	 * @param	string	$productRouting	Value posted by the combo for the default product
	 * @return	int						Id of the created thirdparty
	 */
	private function createVendorWithDefaultProduct($productRouting)
	{
		global $db;

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Vendor of the default product test';
		$thirdparty->country_code = 'FR';
		$thirdparty->fournisseur = 1;
		$thirdparty->code_fournisseur = 'auto';

		$socid = $thirdparty->create($this->author());
		$this->assertGreaterThan(0, $socid, 'Could not create the vendor of the test: ' . $thirdparty->error . ' ' . implode(', ', $thirdparty->errors));

		$einvoicing = new EInvoicing($db);
		$this->assertGreaterThan(0, $einvoicing->addRouting($socid, $productRouting, '', 'product'), 'Could not set the default product of the test: ' . $einvoicing->error);
		$this->assertEquals($productRouting, $einvoicing->fetchDefaultRouting($socid, 'product'), 'The default product of the test was not stored');

		return $socid;
	}

	/**
	 * Save the thirdparty the way the card does, with the field of the form filled as given.
	 *
	 * @param	int			$socid		Id of the thirdparty
	 * @param	string|null	$posted		Value posted for routing_product_id, null to not post the field
	 * @param	string		$shown		Value the field held when it was drawn (routing_product_id_shown)
	 * @return	string|int				Default product of the vendor once saved
	 */
	private function saveThirdparty($socid, $posted, $shown = '')
	{
		global $db, $user, $langs, $conf;

		if ($posted === null) {
			unset($_POST['routing_product_id']);
			unset($_POST['routing_product_id_shown']);
		} else {
			$_POST['routing_product_id'] = $posted;
			$_POST['routing_product_id_shown'] = $shown;
		}

		$thirdparty = new Societe($db);
		$thirdparty->fetch($socid);

		$trigger = new InterfaceEInvoicingTriggers($db);
		$res = $trigger->runTrigger('COMPANY_MODIFY', $thirdparty, $user, $langs, $conf);
		$this->assertGreaterThanOrEqual(0, $res, 'The trigger refused the save: ' . implode(', ', $trigger->errors));

		$einvoicing = new EInvoicing($db);

		return $einvoicing->fetchDefaultRouting($socid, 'product');
	}

	/**
	 * Picking the empty entry of the combo removes the default product of the vendor.
	 *
	 * @return void
	 */
	public function testEmptyEntryOfTheComboRemovesTheDefaultProduct()
	{
		$socid = $this->createVendorWithDefaultProduct('idprod_1234');

		$this->assertEquals(0, $this->saveThirdparty($socid, '-1', 'idprod_1234'), 'The default product survived the empty entry of the combo');
	}

	/**
	 * Clearing the ajax search field (PRODUIT_USE_SEARCH_TO_SELECT) posts an empty value, and removes
	 * the default product just as the empty entry of the combo does.
	 *
	 * @return void
	 */
	public function testClearedSearchFieldRemovesTheDefaultProduct()
	{
		$socid = $this->createVendorWithDefaultProduct('idprod_1234');

		$this->assertEquals(0, $this->saveThirdparty($socid, '', 'idprod_1234'), 'The default product survived the cleared search field');
	}

	/**
	 * A save that does not carry the field at all (REST API, mass action, import...) must leave the
	 * default product of the vendor untouched.
	 *
	 * @return void
	 */
	public function testSaveWithoutTheFieldKeepsTheDefaultProduct()
	{
		$socid = $this->createVendorWithDefaultProduct('idprod_1234');

		$this->assertEquals('idprod_1234', $this->saveThirdparty($socid, null), 'A save without the field lost the default product');
	}

	/**
	 * Replacing the default product with another one still works.
	 *
	 * @return void
	 */
	public function testAnotherProductReplacesTheDefaultProduct()
	{
		$socid = $this->createVendorWithDefaultProduct('idprod_1234');

		$this->assertEquals('idprod_5678', $this->saveThirdparty($socid, 'idprod_5678', 'idprod_1234'), 'The default product was not replaced');
	}

	/**
	 * A save whose combo could not show the current value - the default product of the vendor is not
	 * among the products the combo lists - must not be read as a removal.
	 *
	 * @return void
	 */
	public function testEmptyFieldThatShowedNothingKeepsTheDefaultProduct()
	{
		$socid = $this->createVendorWithDefaultProduct('idprod_1234');

		$this->assertEquals('idprod_1234', $this->saveThirdparty($socid, '-1', ''), 'A field that showed nothing was read as a removal');
	}

	/**
	 * The combo has to show the default product of the vendor as its selected entry. Before Dolibarr 22
	 * the core only marks an option whose value is the id of a supplier price, so a product without one
	 * ('idprod_ID') came back unmarked: the field showed nothing, and the save that follows would then
	 * read an untouched field as a removal.
	 *
	 * @return void
	 */
	public function testTheComboShowsTheDefaultProductOfTheVendor()
	{
		global $db, $conf;

		$author = $this->author();

		$conf->global->PRODUIT_USE_SEARCH_TO_SELECT = 0;		// The combo, not the ajax search field

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Vendor of the combo test';
		$thirdparty->country_code = 'FR';
		$thirdparty->fournisseur = 1;
		$thirdparty->code_fournisseur = 'auto';
		$socid = $thirdparty->create($author);
		$this->assertGreaterThan(0, $socid, 'Could not create the vendor of the test: ' . $thirdparty->error . ' ' . implode(', ', $thirdparty->errors));

		// A product to buy with no supplier price of its own: this is the case the cores mishandle
		$product = new Product($db);
		$product->ref = 'EINV791' . dol_print_date(dol_now(), '%y%m%d%H%M%S');
		$product->label = 'Product of the combo test';
		$product->type = 0;
		$product->status = 0;
		$product->status_buy = 1;
		$pid = $product->create($author);
		$this->assertGreaterThan(0, $pid, 'Could not create the product of the test: ' . $product->error . ' ' . implode(', ', $product->errors));

		$method = new ReflectionMethod(EInvoicing::class, 'selectVendorProduct');
		$method->setAccessible(true);

		// Dolibarr 18 and 19 read $objp->barcode in that combo although their own query only selects it
		// when the barcode module is on. The notice belongs to the core, but PHPUnit turns it into a
		// failure: silence that one, nothing else.
		set_error_handler(
			/**
			 * @param	int		$errno	Level of the error
			 * @param	string	$errstr	Message of the error
			 * @return	bool			True to swallow the error, false to hand it back to PHP
			 */
			static function ($errno, $errstr) {
				return strpos($errstr, 'barcode') !== false;
			},
			E_WARNING | E_NOTICE
		);
		try {
			$out = $method->invoke(new EInvoicing($db), new Form($db), $socid, 'idprod_' . $pid, 'routing_product_id');
		} finally {
			restore_error_handler();
		}

		$this->assertStringContainsString('<option value="idprod_' . $pid . '" selected', $out, 'The combo does not show the default product of the vendor');
		$this->assertStringNotContainsString('<option value="-1" selected', $out, 'The combo shows its empty entry although a default product is set');
		$this->assertStringContainsString('name="routing_product_id_shown" value="idprod_' . $pid . '"', $out, 'The combo does not tell the save what it shows');
	}

	/**
	 * A vendor with no default product yet gets one on the first save, and stays without one when the
	 * empty entry is left as it is.
	 *
	 * @return void
	 */
	public function testFirstSaveOfADefaultProduct()
	{
		global $db;

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Vendor without default product';
		$thirdparty->country_code = 'FR';
		$thirdparty->fournisseur = 1;
		$thirdparty->code_fournisseur = 'auto';
		$socid = $thirdparty->create($this->author());
		$this->assertGreaterThan(0, $socid, 'Could not create the vendor of the test: ' . $thirdparty->error . ' ' . implode(', ', $thirdparty->errors));

		$this->assertEquals(0, $this->saveThirdparty($socid, '-1', ''), 'A vendor without default product got one out of the empty entry');
		$this->assertEquals('idprod_1234', $this->saveThirdparty($socid, 'idprod_1234', ''), 'The default product was not stored on the first save');
	}

	/** @var InterfaceEInvoicingTriggers Trigger of the last delete call above, for its errors */
	private $lasttrigger;

	/**
	 * Ask the trigger whether a flow may be deleted, the way Document::delete() does.
	 *
	 * @param	Document	$doc	Flow being deleted
	 * @return	int					0 if the deletion is allowed, -1 if the trigger refuses it
	 */
	private function runFlowDeleteTrigger($doc)
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
		$this->assertSame(0, $this->runFlowDeleteTrigger($doc));
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

		$this->assertSame(0, $this->runFlowDeleteTrigger($doc));
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

		$this->assertSame(-1, $this->runFlowDeleteTrigger($doc));
		$this->assertNotEmpty($this->lasttrigger->errors);
	}

	/**
	 * Ask the trigger whether a supplier invoice may be deleted, the way FactureFournisseur::delete()
	 * does.
	 *
	 * @param	FactureFournisseur	$invoice	Supplier invoice being deleted
	 * @return	int								0 if the deletion is allowed, -1 if the trigger refuses it
	 */
	private function runSupplierInvoiceDeleteTrigger($invoice)
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

		$this->assertSame(0, $this->runSupplierInvoiceDeleteTrigger($invoice));
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

		$this->assertSame(-1, $this->runSupplierInvoiceDeleteTrigger($invoice));
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

		$this->assertSame(-1, $this->runSupplierInvoiceDeleteTrigger($invoice));
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

		$this->assertSame(0, $this->runSupplierInvoiceDeleteTrigger($invoice));
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

		$this->assertSame(0, $this->runSupplierInvoiceDeleteTrigger($ghost));
		$this->assertSame(array(), $this->lasttrigger->errors);
	}

	/**
	 * Set the VAT mode of the company for goods and services, the one thing that decides a payment status.
	 *
	 * @param	string	$product	'invoice' (on debits) or 'payment' (on collection) for goods
	 * @param	string	$service	Same for services
	 * @return	void
	 */
	private function setVatMode($product, $service)
	{
		$this->setConst('TAX_MODE_SELL_PRODUCT', $product);
		$this->setConst('TAX_MODE_SELL_SERVICE', $service);
	}

	/**
	 * Change a constant of the setup for the test, tearDown() gives the value of the instance back.
	 *
	 * @param	string	$name	Name of the constant
	 * @param	string	$value	Value for the test
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
	 * An invoice of a French company billing one service, in memory, with the e-invoicing record given.
	 *
	 * @param	?string	$flowId		Flow id of the record, null for no record at all
	 * @param	int		$syncStatus	Status of the record
	 * @return	Facture				The invoice
	 */
	private function serviceInvoice($flowId, $syncStatus = EInvoicing::STATUS_AWAITING_VALIDATION)
	{
		global $db;

		// The record is read for the platform of the setup, and the scope must not depend on the instance
		$this->setConst('EINVOICING_PDP', 'SUPERPDP');
		$this->setConst('EINVOICING_SKIP_B2C', '0');
		$this->setConst('EINVOICING_USE_BILLING_CONTACT_AS_BUYER', '0');

		$db->query("DELETE FROM " . $db->prefix() . "einvoicing_extlinks WHERE element_id = " . self::CASH_IN_ELEMENT_ID);
		if ($flowId !== null) {
			$einvoicing = new EInvoicing($db);
			$einvoicing->insertOrUpdateExtLink(self::CASH_IN_ELEMENT_ID, 'facture', $flowId, $syncStatus, 'TEST-212-0001');
		}

		$invoice = new Facture($db);
		$invoice->id = self::CASH_IN_ELEMENT_ID;
		$invoice->ref = 'TEST-212-0001';
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->module_source = '';
		$invoice->thirdparty = new Societe($db);
		$invoice->thirdparty->country_code = 'FR';
		$invoice->thirdparty->tva_assuj = 1;
		$line = new FactureLigne($db);
		$line->product_type = 1;
		$invoice->lines = array($line);

		return $invoice;
	}

	/**
	 * A transmitted invoice whose VAT falls due on collection has its payments reported.
	 *
	 * @return void
	 */
	public function testTransmittedInvoiceWithVatOnCollectionHasItsPaymentsReported()
	{
		global $db;

		$this->setVatMode('invoice', 'payment');

		$einvoicing = new EInvoicing($db);
		$this->assertSame(1, $einvoicing->getCashInReportState($this->serviceInvoice('ie_2443476')));
	}

	/**
	 * Under the debits option no payment is reported: nothing is sent, by the trigger nor by hand.
	 *
	 * @return void
	 */
	public function testVatOnDebitsHasNoPaymentToReport()
	{
		global $db;

		$this->setVatMode('invoice', 'invoice');

		$einvoicing = new EInvoicing($db);
		$invoice = $this->serviceInvoice('ie_2443476');
		$this->assertSame(0, $einvoicing->getCashInReportState($invoice));
		$this->assertSame(0, $einvoicing->reportCashIn($invoice, 100.0)['res']);
	}

	/**
	 * An invoice the platform never received has no payment to report.
	 *
	 * @return void
	 */
	public function testNeverTransmittedInvoiceHasNoPaymentToReport()
	{
		global $db;

		$this->setVatMode('payment', 'payment');

		$einvoicing = new EInvoicing($db);
		$this->assertSame(0, $einvoicing->getCashInReportState($this->serviceInvoice(null)));
		$this->assertSame(0, $einvoicing->reportCashIn($this->serviceInvoice('', EInvoicing::STATUS_GENERATED), 100.0)['res']);
	}

	/**
	 * A deposit the platform refused holds no invoice to report on: said apart, so the card can say why.
	 *
	 * @return void
	 */
	public function testRefusedDepositBlocksThePaymentReport()
	{
		global $db;

		$this->setVatMode('payment', 'payment');

		$einvoicing = new EInvoicing($db);
		$invoice = $this->serviceInvoice('ie_2443476', EInvoicing::STATUS_ERROR);
		$this->assertSame(-1, $einvoicing->getCashInReportState($invoice));
		$this->assertSame(-2, $einvoicing->reportCashIn($invoice, 100.0)['res']);
	}

	/**
	 * The payments offered for a report by hand are those that moved money: a cash-in, a refund with the
	 * comment that says why, and not the line of a payment that moved nothing on this invoice.
	 *
	 * @return void
	 */
	public function testCashInPaymentsListTheMoneyMovedOnTheInvoice()
	{
		global $db;

		$thirdparty = new Societe($db);
		$thirdparty->name = 'Customer of the payment status test';
		$thirdparty->country_code = 'FR';
		$thirdparty->client = 1;
		$thirdparty->code_client = 'auto';
		$socid = $thirdparty->create($this->author());
		$this->assertGreaterThan(0, $socid, 'Could not create the customer of the test: ' . $thirdparty->error);

		$invoice = new Facture($db);
		$invoice->socid = $socid;
		$invoice->date = dol_mktime(12, 0, 0, 9, 1, 2026);
		$invoice->type = Facture::TYPE_STANDARD;
		$invoiceId = $invoice->create($this->author());
		$this->assertGreaterThan(0, $invoiceId, 'Could not create the invoice of the test: ' . $invoice->error);

		$resql = $db->query("SELECT id FROM " . $db->prefix() . "c_paiement ORDER BY id ASC LIMIT 1");
		$mode = $db->fetch_object($resql);
		$this->assertNotEmpty($mode, 'No payment mode in the base');

		$lines = array(array('PAYTEST-212-1', 20260902, 120.0, ''), array('PAYTEST-212-2', 20260903, 0.0, ''), array('PAYTEST-212-3', 20260905, -20.0, 'Goods sent back'));
		$ids = array();
		foreach ($lines as $line) {
			list($ref, $day, $amount, $note) = $line;
			$datep = $db->idate(dol_mktime(10, 0, 0, (int) substr((string) $day, 4, 2), (int) substr((string) $day, 6, 2), 2026));
			$sql = "INSERT INTO " . $db->prefix() . "paiement (entity, ref, datec, datep, amount, fk_paiement, note, fk_user_creat)";
			$sql .= " VALUES (1, '" . $db->escape($ref) . "', '" . $datep . "', '" . $datep . "', " . $amount . ", " . (int) $mode->id . ", '" . $db->escape($note) . "', " . (int) $this->author()->id . ")";
			$this->assertNotFalse($db->query($sql), (string) $db->lasterror());
			$ids[$ref] = (int) $db->last_insert_id($db->prefix() . "paiement");
			$sql = "INSERT INTO " . $db->prefix() . "paiement_facture (fk_paiement, fk_facture, amount) VALUES (" . $ids[$ref] . ", " . (int) $invoiceId . ", " . $amount . ")";
			$this->assertNotFalse($db->query($sql), (string) $db->lasterror());
		}

		$einvoicing = new EInvoicing($db);
		$payments = $einvoicing->getCashInPayments($invoiceId);

		$this->assertSame(array($ids['PAYTEST-212-1'], $ids['PAYTEST-212-3']), array_keys($payments), 'oldest first, the line of no amount left out');
		$this->assertSame(120.0, $payments[$ids['PAYTEST-212-1']]['amount']);
		$this->assertSame(-20.0, $payments[$ids['PAYTEST-212-3']]['amount']);
		$this->assertSame('Goods sent back', $payments[$ids['PAYTEST-212-3']]['note']);
	}

	/**
	 * The count shown in the confirmation is the statuses 212 this Dolibarr sent, and nothing else.
	 *
	 * @return void
	 */
	public function testSentPaymentStatusesAreCounted()
	{
		global $db;

		$db->query("DELETE FROM " . $db->prefix() . "einvoicing_lifecycle_msg WHERE element_id = " . self::CASH_IN_ELEMENT_ID);
		foreach (array(array('out', 212), array('Out', 212), array('In', 212), array('out', 211)) as $row) {
			$sql = "INSERT INTO " . $db->prefix() . "einvoicing_lifecycle_msg (element_id, element_type, provider, flow_id, direction, lc_status, lc_status_message, lc_validation_status, lc_validation_message, lc_reason_code, date_creation, fk_user_creat)";
			$sql .= " VALUES (" . self::CASH_IN_ELEMENT_ID . ", 'facture', 'SUPERPDP', 'ie_test', '" . $row[0] . "', " . $row[1] . ", '', 'Ok', '', '', '" . $db->idate(dol_now()) . "', " . (int) $this->author()->id . ")";
			$this->assertNotFalse($db->query($sql), (string) $db->lasterror());
		}

		$einvoicing = new EInvoicing($db);
		$this->assertSame(2, $einvoicing->countSentStatusMessages(self::CASH_IN_ELEMENT_ID, 'facture', 212));
	}
}
