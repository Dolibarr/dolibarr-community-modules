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
 *      \file       test/phpunit/SupplierInvoiceHelperTest.php
 *      \ingroup    test
 *      \brief      PHPUnit test for the "supplier invoice refused by the e-invoicing platform"
 *                  business rule (issue #286): SupplierInvoiceHelper::abandonRefusedSupplierInvoice(),
 *                  SupplierInvoiceHelper::onOutboundStatusMessageValidated() and their dispatch from
 *                  EInvoicing::updateStatusMessageValidation().
 *      \remarks    To run this script as CLI: phpunit filename.php
 */

global $conf, $user, $langs, $db;

// This module is deployed by symlinking this repository into htdocs/custom/einvoicing of one or
// several Dolibarr instances. Some test runners resolve the real (non-symlinked) path of this
// file before including it, which breaks a fixed "../../htdocs/master.inc.php" relative path.
// DOLIBARR_HTDOCS let's the developer/CI point explicitly at the Dolibarr instance to test
// against; otherwise we fall back to the standard relative path (valid when this file is reached
// through the htdocs/custom/einvoicing/test/phpunit symlink without realpath resolution).
$dolibarrHtdocs = getenv('DOLIBARR_HTDOCS');
if (!$dolibarrHtdocs) {
	$dolibarrHtdocs = dirname(__FILE__) . '/../../htdocs';
}
if (!file_exists($dolibarrHtdocs . '/master.inc.php')) {
	throw new \RuntimeException('Could not locate master.inc.php under "' . $dolibarrHtdocs . '/". Set the environment variable (export DOLIBARR_HTDOCS=...) to the htdocs directory of the Dolibarr instance to test against.');
}

require_once $dolibarrHtdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
dol_include_once('einvoicing/class/einvoicing.class.php');
dol_include_once('einvoicing/class/helpers/SupplierInvoiceHelper.class.php');
require_once DOL_DOCUMENT_ROOT . '/../test/phpunit/CommonClassTest.class.php';

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;

/**
 * Class for PHPUnit tests
 *
 * @backupGlobals disabled
 * @backupStaticAttributes enabled
 * @remarks	backupGlobals must be disabled to have db,conf,user and lang not erased.
 */
class SupplierInvoiceHelperTest extends CommonClassTest
{
	/**
	 * Create a draft specimen supplier invoice. ref_supplier is made unique per call: several
	 * test methods each create their own specimen inside the same class-wide transaction (see
	 * CommonClassTest::setUpBeforeClass()), and FactureFournisseur::initAsSpecimen() otherwise
	 * always sets the same fixed ref_supplier, which collides with the unique key on a second call.
	 *
	 * @return FactureFournisseur
	 */
	private function createSpecimenSupplierInvoice()
	{
		global $db, $user;

		$localobject = new FactureFournisseur($db);
		$localobject->initAsSpecimen();
		$localobject->ref_supplier = 'SUPPLIER_REF_SPECIMEN_' . uniqid();
		// initAsSpecimen() hardcodes socid = 1, which only exists on an instance still carrying the
		// demo data. Anywhere else the insert fails on the fk_facture_fourn_fk_soc foreign key, so
		// resolve an existing third party instead of assuming that id.
		$localobject->socid = $this->getAnyThirdpartyId();
		$result = $localobject->create($user);
		$this->assertGreaterThan(0, $result, $localobject->errorsToString());

		return $localobject;
	}

	/**
	 * Return the id of any existing third party, so the specimen fixtures do not depend on the
	 * demo data being present.
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
		$this->assertNotEquals(false, $resql, 'Cannot query third parties: ' . $db->lasterror());

		$obj = $db->fetch_object($resql);
		$this->assertNotEquals(null, $obj, 'No third party in database, cannot build the fixture');

		return (int) $obj->rowid;
	}

	/**
	 * Insert a lifecycle status message fixture row directly (bypassing
	 * EInvoicing::storeStatusMessage() so the test does not depend on the EINVOICING_PDP
	 * setup of the environment it runs in).
	 *
	 * @param	int		$elementId		Id of the related element
	 * @param	string	$elementType	Element type ('invoice_supplier', 'facture', ...)
	 * @param	int		$lcStatus		Lifecycle status code sent
	 * @param	string	$lcReasonCode	Reason code sent
	 * @return	int						Id of the inserted row
	 */
	private function insertLifecycleMessageFixture($elementId, $elementType, $lcStatus, $lcReasonCode)
	{
		global $db, $user;

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "einvoicing_lifecycle_msg";
		$sql .= " (element_id, element_type, provider, direction, lc_status, lc_status_message, lc_validation_status, lc_validation_message, lc_reason_code, date_creation, fk_user_creat)";
		$sql .= " VALUES (";
		$sql .= (int) $elementId . ", ";
		$sql .= "'" . $db->escape($elementType) . "', ";
		$sql .= "'TEST', ";
		$sql .= "'OUT', ";
		$sql .= (int) $lcStatus . ", ";
		$sql .= "'Test fixture', ";
		$sql .= "'Pending', ";
		$sql .= "'', ";
		$sql .= "'" . $db->escape($lcReasonCode) . "', ";
		$sql .= "'" . $db->idate(dol_now()) . "', ";
		$sql .= (int) $user->id;
		$sql .= ")";

		$resql = $db->query($sql);
		$this->assertNotFalse($resql, (string) $db->lasterror());

		return (int) $db->last_insert_id(MAIN_DB_PREFIX . 'einvoicing_lifecycle_msg');
	}

	/**
	 * A draft invoice, once refused and confirmed, is validated then abandoned with the
	 * dedicated close code and the refusal reason kept as the close note.
	 *
	 * @return void
	 */
	public function testAbandonDraftInvoiceBecomesAbandoned()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = $this->createSpecimenSupplierInvoice();

		$result = SupplierInvoiceHelper::abandonRefusedSupplierInvoice($localobject, $user, 'Wrong amount');
		$this->assertGreaterThan(0, $result, $localobject->errorsToString());

		$localobject->fetch($localobject->id);
		$this->assertEquals(FactureFournisseur::STATUS_ABANDONED, $localobject->status);
		$this->assertEquals(SupplierInvoiceHelper::CLOSECODE_PDPREFUSED, $localobject->close_code);
		$this->assertEquals('Wrong amount', $localobject->close_note);
	}

	/**
	 * An invoice already validated (not a draft anymore) is directly abandoned, without a
	 * second call to validate().
	 *
	 * @return void
	 */
	public function testAbandonValidatedInvoiceSkipsValidation()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = $this->createSpecimenSupplierInvoice();
		$resValidate = $localobject->validate($user);
		$this->assertGreaterThan(0, $resValidate, $localobject->errorsToString());

		$result = SupplierInvoiceHelper::abandonRefusedSupplierInvoice($localobject, $user, 'Duplicate invoice');
		$this->assertGreaterThan(0, $result, $localobject->errorsToString());

		$localobject->fetch($localobject->id);
		$this->assertEquals(FactureFournisseur::STATUS_ABANDONED, $localobject->status);
		$this->assertEquals(SupplierInvoiceHelper::CLOSECODE_PDPREFUSED, $localobject->close_code);
	}

	/**
	 * Calling the abandon a second time on an invoice already abandoned by this same rule is a
	 * no-op (idempotence, needed because both the AJAX polling and the hourly cron may process
	 * the same platform confirmation).
	 *
	 * @return void
	 */
	public function testAbandonAlreadyAbandonedByRuleIsIdempotent()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = $this->createSpecimenSupplierInvoice();
		$resFirst = SupplierInvoiceHelper::abandonRefusedSupplierInvoice($localobject, $user, 'First reason');
		$this->assertGreaterThan(0, $resFirst, $localobject->errorsToString());

		// setCanceled() only updates the database, not the in-memory object (existing core
		// behavior, unrelated to this feature) - re-fetch to reflect the real precondition
		// under which the real caller (onOutboundStatusMessageValidated()) always operates: a
		// freshly fetched invoice.
		$localobject->fetch($localobject->id);

		$resSecond = SupplierInvoiceHelper::abandonRefusedSupplierInvoice($localobject, $user, 'Second reason');
		$this->assertEquals(0, $resSecond);

		$localobject->fetch($localobject->id);
		$this->assertEquals(FactureFournisseur::STATUS_ABANDONED, $localobject->status);
		// Close note from the first call must not have been overwritten by the idempotent no-op call.
		$this->assertEquals('First reason', $localobject->close_note);
	}

	/**
	 * A supplier invoice already paid must never be abandoned.
	 *
	 * @return void
	 */
	public function testAbandonPaidInvoiceFails()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = $this->createSpecimenSupplierInvoice();
		$resValidate = $localobject->validate($user);
		$this->assertGreaterThan(0, $resValidate, $localobject->errorsToString());

		// Simulate a fully paid invoice without going through a real payment workflow: the
		// helper only reads these in-memory properties to take its decision.
		$localobject->paid = 1;
		$localobject->status = FactureFournisseur::STATUS_CLOSED;

		$result = SupplierInvoiceHelper::abandonRefusedSupplierInvoice($localobject, $user, 'Should not apply');
		$this->assertEquals(-1, $result);
		$this->assertNotEmpty($localobject->errors);

		$localobject->fetch($localobject->id);
		$this->assertNotEquals(FactureFournisseur::STATUS_ABANDONED, $localobject->status);
	}

	/**
	 * A 'Pending' validation status is not a confirmation: it must never trigger the abandon.
	 *
	 * @return void
	 */
	public function testOnOutboundStatusMessageValidatedPendingIsNoOp()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$result = SupplierInvoiceHelper::onOutboundStatusMessageValidated($db, $user, 999999, EInvoicing::STATUS_REFUSED, 'AUTRE', 'Pending');
		$this->assertEquals(0, $result);
	}

	/**
	 * A confirmed platform-side business error ('Error', e.g. the real BR-FR-CDV-13 case
	 * observed in production) must never trigger the abandon: the Dolibarr invoice keeps its
	 * current status, the error stays visible via the lifecycle message validation fields.
	 *
	 * @return void
	 */
	public function testOnOutboundStatusMessageValidatedErrorIsNoOp()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$result = SupplierInvoiceHelper::onOutboundStatusMessageValidated($db, $user, 999999, EInvoicing::STATUS_REFUSED, 'AUTRE', 'Error');
		$this->assertEquals(0, $result);
	}

	/**
	 * A confirmed ('Ok') message that is not a refusal (e.g. an "Approved" status confirmed)
	 * must never trigger the abandon: the rule only concerns refusals.
	 *
	 * @return void
	 */
	public function testOnOutboundStatusMessageValidatedOkOnOtherStatusIsNoOp()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$result = SupplierInvoiceHelper::onOutboundStatusMessageValidated($db, $user, 999999, EInvoicing::STATUS_APPROVED, '', 'Ok');
		$this->assertEquals(0, $result);
	}

	/**
	 * A confirmed ('Ok') refusal must abandon the related Dolibarr supplier invoice.
	 *
	 * @return void
	 */
	public function testOnOutboundStatusMessageValidatedOkOnRefusedAbandonsInvoice()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = $this->createSpecimenSupplierInvoice();

		$result = SupplierInvoiceHelper::onOutboundStatusMessageValidated($db, $user, $localobject->id, EInvoicing::STATUS_REFUSED, 'AUTRE', 'Ok');
		$this->assertGreaterThan(0, $result);

		$localobject->fetch($localobject->id);
		$this->assertEquals(FactureFournisseur::STATUS_ABANDONED, $localobject->status);
		$this->assertEquals(SupplierInvoiceHelper::CLOSECODE_PDPREFUSED, $localobject->close_code);
	}

	/**
	 * An unknown element id must return a clean error, never an uncaught exception.
	 *
	 * @return void
	 */
	public function testOnOutboundStatusMessageValidatedUnknownElementIdReturnsError()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$result = SupplierInvoiceHelper::onOutboundStatusMessageValidated($db, $user, 999999999, EInvoicing::STATUS_REFUSED, 'AUTRE', 'Ok');
		$this->assertEquals(-1, $result);
	}

	/**
	 * Integration test of the choke point: EInvoicing::updateStatusMessageValidation() must
	 * dispatch to the abandon rule by itself when the persisted message concerns a supplier
	 * invoice refusal confirmed 'Ok' - callers (providers, ajax) do not need to know this rule
	 * exists.
	 *
	 * @return void
	 */
	public function testUpdateStatusMessageValidationDispatchesForSupplierInvoice()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$localobject = $this->createSpecimenSupplierInvoice();
		$lcId = $this->insertLifecycleMessageFixture($localobject->id, 'invoice_supplier', EInvoicing::STATUS_REFUSED, 'AUTRE');

		$einvoicing = new EInvoicing($db);
		$result = $einvoicing->updateStatusMessageValidation($lcId, '', 'Ok', '');
		$this->assertEquals(1, $result);

		$localobject->fetch($localobject->id);
		$this->assertEquals(FactureFournisseur::STATUS_ABANDONED, $localobject->status);
		$this->assertEquals(SupplierInvoiceHelper::CLOSECODE_PDPREFUSED, $localobject->close_code);
	}

	/**
	 * Lifecycle messages for other element types (e.g. customer invoices) must never be
	 * impacted by this rule: updateStatusMessageValidation() must keep returning 1 (persistence
	 * succeeded) without any dispatch side effect.
	 *
	 * @return void
	 */
	public function testUpdateStatusMessageValidationIgnoresOtherElementTypes()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$lcId = $this->insertLifecycleMessageFixture(999999, 'facture', EInvoicing::STATUS_PAID, '');

		$einvoicing = new EInvoicing($db);
		$result = $einvoicing->updateStatusMessageValidation($lcId, '', 'Ok', '');
		$this->assertEquals(1, $result);
	}
}
