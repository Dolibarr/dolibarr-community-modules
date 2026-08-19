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
class SupplierInvoiceHelperTest extends CommonClassTest
{
	/**
	 * Several fixtures of this class validate a supplier invoice, and validating one that came from the
	 * platform now answers its vendor with the "Approved" (205) status - a real call to the Access Point
	 * configured on the instance the suite runs against. None of those tests is about that status, so it
	 * is switched off here; the tests that ARE about it turn it back on explicitly.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		parent::setUp();

		$conf->global->EINVOICING_SEND_APPROVED_ON_VALIDATION = '0';
	}

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
	 * @param	int		$elementId			Id of the related element
	 * @param	string	$elementType		Element type ('invoice_supplier', 'facture', ...)
	 * @param	int		$lcStatus			Lifecycle status code sent
	 * @param	string	$lcReasonCode		Reason code sent
	 * @param	string	$lcValidationStatus	What the platform answered: 'Pending' by default, 'Ok' for a status it confirmed
	 * @return	int							Id of the inserted row
	 */
	private function insertLifecycleMessageFixture($elementId, $elementType, $lcStatus, $lcReasonCode, $lcValidationStatus = 'Pending')
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
		$sql .= "'" . $db->escape($lcValidationStatus) . "', ";
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
	 * Insert an e-invoicing document row pointing at a supplier invoice. The whole class runs inside
	 * the transaction opened by CommonClassTest::setUpBeforeClass(), so nothing survives the run.
	 *
	 * @param	int		$supplierInvoiceId	Id of the supplier invoice the document describes
	 * @return	void
	 */
	private function addEInvoicingDocument($supplierInvoiceId)
	{
		global $db;

		$now = $db->idate(dol_now());

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "einvoicing_document";
		$sql .= " (fk_element_type, fk_element_id, flow_type, date_creation, fk_user_creat, status, submittedat, provider)";
		$sql .= " VALUES ('invoice_supplier', " . ((int) $supplierInvoiceId) . ", 'SupplierInvoice', '" . $now . "', 1, 0, '" . $now . "', 'PHPUNIT')";

		$this->assertNotFalse($db->query($sql), (string) $db->lasterror());
	}

	/**
	 * A supplier invoice with no e-invoicing document is not an e-invoice, and nothing is reported.
	 *
	 * @return void
	 */
	public function testNoEInvoicingDocumentIsNotAnEInvoice()
	{
		$invoice = $this->createSpecimenSupplierInvoice();

		$duplicate = false;
		$this->assertFalse(SupplierInvoiceHelper::isEInvoice($invoice->id, false, $duplicate));
		$this->assertFalse($duplicate);
	}

	/**
	 * One e-invoicing document: the answer is yes, with or without the existence check, and no
	 * duplicate is reported. The variant without the existence check is the one that used to fall
	 * through to false in the change this replaces.
	 *
	 * @return void
	 */
	public function testSingleEInvoicingDocumentIsAnEInvoice()
	{
		$invoice = $this->createSpecimenSupplierInvoice();
		$this->addEInvoicingDocument($invoice->id);

		$duplicate = false;
		$this->assertTrue(SupplierInvoiceHelper::isEInvoice($invoice->id, false, $duplicate));
		$this->assertFalse($duplicate);

		$duplicate = false;
		$this->assertTrue(SupplierInvoiceHelper::isEInvoice($invoice->id, true, $duplicate));
		$this->assertFalse($duplicate);
	}

	/**
	 * Two e-invoicing documents for the same supplier invoice: still an e-invoice - the question the
	 * predicate is asked has not changed - and the duplicate is reported so the caller can refuse the
	 * operation with an actionable message instead of the predicate throwing from inside a trigger.
	 *
	 * @return void
	 */
	public function testDuplicateEInvoicingDocumentsAreReported()
	{
		$invoice = $this->createSpecimenSupplierInvoice();
		$this->addEInvoicingDocument($invoice->id);
		$this->addEInvoicingDocument($invoice->id);

		$duplicate = false;
		$this->assertTrue(SupplierInvoiceHelper::isEInvoice($invoice->id, false, $duplicate));
		$this->assertTrue($duplicate);

		$duplicate = false;
		$this->assertTrue(SupplierInvoiceHelper::isEInvoice($invoice->id, true, $duplicate));
		$this->assertTrue($duplicate);
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
	 * Build a replacement supplier invoice pointing at a source invoice.
	 *
	 * The type and the source are set on the object rather than written back: they are what the
	 * validation trigger hands over, and what the helper reads to take its decision.
	 *
	 * @param	int	$sourceId	Id of the invoice it replaces
	 * @return	FactureFournisseur
	 */
	private function createReplacementFor($sourceId)
	{
		$replacement = $this->createSpecimenSupplierInvoice();
		$replacement->type = FactureFournisseur::TYPE_REPLACEMENT;
		$replacement->fk_facture_source = $sourceId;

		return $replacement;
	}

	/**
	 * The invoice a replacement e-invoice replaces is closed with the close code the core reserves
	 * for it, so the card stops offering to pay it (issue #549).
	 *
	 * @return void
	 */
	public function testReplacedEInvoiceIsClosed()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$source = $this->createSpecimenSupplierInvoice();
		$this->addEInvoicingDocument($source->id);
		$this->assertGreaterThan(0, $source->validate($user), $source->errorsToString());

		$replacement = $this->createReplacementFor($source->id);

		$this->assertEquals(1, SupplierInvoiceHelper::closeReplacedSupplierInvoice($replacement, $user));

		$source->fetch($source->id);
		$this->assertEquals(FactureFournisseur::STATUS_ABANDONED, $source->status);
		$this->assertEquals(FactureFournisseur::CLOSECODE_REPLACED, $source->close_code);
	}

	/**
	 * A replacement recorded between two ordinary supplier invoices is none of this module's
	 * business and is left exactly as the core leaves it.
	 *
	 * @return void
	 */
	public function testReplacedPlainSupplierInvoiceIsLeftAlone()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$source = $this->createSpecimenSupplierInvoice();
		$this->assertGreaterThan(0, $source->validate($user), $source->errorsToString());

		$replacement = $this->createReplacementFor($source->id);

		$this->assertEquals(0, SupplierInvoiceHelper::closeReplacedSupplierInvoice($replacement, $user));

		$source->fetch($source->id);
		$this->assertEquals(FactureFournisseur::STATUS_VALIDATED, $source->status);
	}

	/**
	 * An invoice that has been paid is never closed: abandoning it would contradict the payment
	 * already recorded against it.
	 *
	 * @return void
	 */
	public function testReplacedPaidInvoiceIsLeftAlone()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$source = $this->createSpecimenSupplierInvoice();
		$this->addEInvoicingDocument($source->id);
		$this->assertGreaterThan(0, $source->validate($user), $source->errorsToString());
		$this->assertGreaterThan(0, $source->setPaid($user), $source->errorsToString());

		$replacement = $this->createReplacementFor($source->id);

		$this->assertEquals(0, SupplierInvoiceHelper::closeReplacedSupplierInvoice($replacement, $user));

		$source->fetch($source->id);
		$this->assertNotEquals(FactureFournisseur::STATUS_ABANDONED, $source->status);
	}

	/**
	 * A draft cannot be paid nor transferred to accountancy, so it is left as it is rather than
	 * being validated only to be cancelled.
	 *
	 * @return void
	 */
	public function testReplacedDraftInvoiceIsLeftAlone()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$source = $this->createSpecimenSupplierInvoice();
		$this->addEInvoicingDocument($source->id);

		$replacement = $this->createReplacementFor($source->id);

		$this->assertEquals(0, SupplierInvoiceHelper::closeReplacedSupplierInvoice($replacement, $user));

		$source->fetch($source->id);
		$this->assertEquals(FactureFournisseur::STATUS_DRAFT, $source->status);
	}

	/**
	 * An invoice that is not a replacement, or one with no source recorded, has nothing to close.
	 *
	 * @return void
	 */
	public function testNonReplacementInvoiceClosesNothing()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$source = $this->createSpecimenSupplierInvoice();
		$this->addEInvoicingDocument($source->id);
		$this->assertGreaterThan(0, $source->validate($user), $source->errorsToString());

		// Standard type, even though it does carry a source.
		$standard = $this->createSpecimenSupplierInvoice();
		$standard->fk_facture_source = $source->id;
		$this->assertEquals(0, SupplierInvoiceHelper::closeReplacedSupplierInvoice($standard, $user));

		// Replacement type, but no source recorded.
		$orphan = $this->createSpecimenSupplierInvoice();
		$orphan->type = FactureFournisseur::TYPE_REPLACEMENT;
		$this->assertEquals(0, SupplierInvoiceHelper::closeReplacedSupplierInvoice($orphan, $user));

		$source->fetch($source->id);
		$this->assertEquals(FactureFournisseur::STATUS_VALIDATED, $source->status);
	}

	/**
	 * Closing the same replaced invoice a second time changes nothing.
	 *
	 * @return void
	 */
	public function testCloseReplacedIsIdempotent()
	{
		global $conf, $user, $langs, $db;
		$conf = $this->savconf;
		$user = $this->savuser;
		$langs = $this->savlangs;
		$db = $this->savdb;

		$source = $this->createSpecimenSupplierInvoice();
		$this->addEInvoicingDocument($source->id);
		$this->assertGreaterThan(0, $source->validate($user), $source->errorsToString());

		$replacement = $this->createReplacementFor($source->id);

		$this->assertEquals(1, SupplierInvoiceHelper::closeReplacedSupplierInvoice($replacement, $user));
		$this->assertEquals(0, SupplierInvoiceHelper::closeReplacedSupplierInvoice($replacement, $user));

		$source->fetch($source->id);
		$this->assertEquals(FactureFournisseur::STATUS_ABANDONED, $source->status);
		$this->assertEquals(FactureFournisseur::CLOSECODE_REPLACED, $source->close_code);
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

	/**
	 * A received e-invoice with nothing answered yet: validating it has to tell the vendor "Approved"
	 * (205), which is what the buyer owes on an invoice it accepts (issue #572 follow-up, 205 on
	 * validation).
	 *
	 * @return void
	 */
	public function testValidatingAReceivedEInvoiceAnswersApproved()
	{
		global $conf, $db;

		$conf->global->EINVOICING_SEND_APPROVED_ON_VALIDATION = '1';

		$invoice = $this->createSpecimenSupplierInvoice();
		$this->addEInvoicingDocument($invoice->id);

		$einvoicing = new EInvoicing($db);
		$this->assertTrue(SupplierInvoiceHelper::shouldSendApprovedOnValidation($einvoicing, (int) $invoice->id, 'invoice_supplier'));
	}

	/**
	 * An invoice that never came from the platform has no vendor waiting for a status: nothing is sent,
	 * whatever the setup says.
	 *
	 * @return void
	 */
	public function testValidatingAPlainSupplierInvoiceAnswersNothing()
	{
		global $conf, $db;

		$conf->global->EINVOICING_SEND_APPROVED_ON_VALIDATION = '1';

		$invoice = $this->createSpecimenSupplierInvoice();

		$einvoicing = new EInvoicing($db);
		$this->assertFalse(SupplierInvoiceHelper::shouldSendApprovedOnValidation($einvoicing, (int) $invoice->id, 'invoice_supplier'));
	}

	/**
	 * The status is sent once. A second validation - a draft reopened and validated again - must not
	 * repeat it, since the lifecycle is already closed on the platform side.
	 *
	 * @return void
	 */
	public function testApprovedIsNotSentTwice()
	{
		global $conf, $db;

		$conf->global->EINVOICING_SEND_APPROVED_ON_VALIDATION = '1';

		$invoice = $this->createSpecimenSupplierInvoice();
		$this->addEInvoicingDocument($invoice->id);
		$this->insertLifecycleMessageFixture($invoice->id, 'invoice_supplier', EInvoicing::STATUS_APPROVED, '');

		$einvoicing = new EInvoicing($db);
		$this->assertFalse(SupplierInvoiceHelper::shouldSendApprovedOnValidation($einvoicing, (int) $invoice->id, 'invoice_supplier'));
	}

	/**
	 * An invoice already refused is not approved afterwards by a validation: 205 and 210 both close the
	 * lifecycle, and answering both would contradict what the vendor was already told.
	 *
	 * @return void
	 */
	public function testARefusedInvoiceIsNotApprovedLater()
	{
		global $conf, $db;

		$conf->global->EINVOICING_SEND_APPROVED_ON_VALIDATION = '1';

		$invoice = $this->createSpecimenSupplierInvoice();
		$this->addEInvoicingDocument($invoice->id);
		$this->insertLifecycleMessageFixture($invoice->id, 'invoice_supplier', EInvoicing::STATUS_REFUSED, 'REJ_LIT');

		$einvoicing = new EInvoicing($db);
		$this->assertFalse(SupplierInvoiceHelper::shouldSendApprovedOnValidation($einvoicing, (int) $invoice->id, 'invoice_supplier'));
	}

	/**
	 * The two switches that turn it off: the dedicated one, for an instance where validating an invoice
	 * does not mean approving it, and the general "do not send anything to the platform".
	 *
	 * @return void
	 */
	public function testTheSetupCanTurnTheAnswerOff()
	{
		global $conf, $db;

		$invoice = $this->createSpecimenSupplierInvoice();
		$this->addEInvoicingDocument($invoice->id);
		$einvoicing = new EInvoicing($db);

		$conf->global->EINVOICING_SEND_APPROVED_ON_VALIDATION = '0';
		$this->assertFalse(SupplierInvoiceHelper::shouldSendApprovedOnValidation($einvoicing, (int) $invoice->id, 'invoice_supplier'));
		$conf->global->EINVOICING_SEND_APPROVED_ON_VALIDATION = '1';

		$conf->global->EINVOICING_DISABLE_SYNC_DOLI_TO_AP = '1';
		$this->assertFalse(SupplierInvoiceHelper::shouldSendApprovedOnValidation($einvoicing, (int) $invoice->id, 'invoice_supplier'));
		unset($conf->global->EINVOICING_DISABLE_SYNC_DOLI_TO_AP);

		// And back to the default, to be sure the two lines above are what changed the answer.
		$this->assertTrue(SupplierInvoiceHelper::shouldSendApprovedOnValidation($einvoicing, (int) $invoice->id, 'invoice_supplier'));
	}

	/**
	 * Create a received document correcting another one: a credit note, or a replacement invoice.
	 *
	 * @param	int		$sourceId	Id of the supplier invoice it corrects
	 * @param	int		$type		FactureFournisseur::TYPE_CREDIT_NOTE or TYPE_REPLACEMENT
	 * @return	FactureFournisseur
	 */
	private function createCorrectingSupplierInvoice(int $sourceId, int $type)
	{
		global $db;

		$correcting = $this->createSpecimenSupplierInvoice();

		// initAsSpecimen() builds a standard invoice, and the type is not something create() takes from
		// the object on every version this module supports: set both columns where they are read from.
		$sql = "UPDATE " . MAIN_DB_PREFIX . "facture_fourn";
		$sql .= " SET type = " . (int) $type . ", fk_facture_source = " . (int) $sourceId;
		$sql .= " WHERE rowid = " . (int) $correcting->id;
		$this->assertNotFalse($db->query($sql), (string) $db->lasterror());

		return $correcting;
	}

	/**
	 * The rule of issue #594: an invoice we refused is cancelled and owes nothing, so the credit note
	 * the vendor issues to close the matter cannot be accepted in its turn.
	 *
	 * @return void
	 */
	public function testCreditNoteOfARefusedInvoiceCannotBeAccepted()
	{
		global $conf, $db;

		$source = $this->createSpecimenSupplierInvoice();
		$this->insertLifecycleMessageFixture($source->id, 'invoice_supplier', EInvoicing::STATUS_REFUSED, 'NON_CONFORME', 'Ok');
		$creditNote = $this->createCorrectingSupplierInvoice((int) $source->id, FactureFournisseur::TYPE_CREDIT_NOTE);
		$this->addEInvoicingDocument($creditNote->id);

		$this->assertSame((int) $source->id, SupplierInvoiceHelper::refusedSourceOfCreditNote((int) $creditNote->id));

		$einvoicing = new EInvoicing($db);
		$offered = array_map('intval', array_keys($einvoicing->getSendableStatusesForReceivedInvoice($creditNote->id, 'invoice_supplier')));

		$this->assertNotContains(EInvoicing::STATUS_APPROVED, $offered, 'the credit note of a refused invoice cannot be approved');
		$this->assertNotContains(EInvoicing::STATUS_PARTIALLY_APPROVED, $offered, 'nor partially approved, which accepts it too');
		$this->assertContains(EInvoicing::STATUS_REFUSED, $offered, 'refusing it is exactly what is left to do');

		// Validating it in the accounts is the other way of accepting it, and it must not answer 205.
		$conf->global->EINVOICING_SEND_APPROVED_ON_VALIDATION = '1';
		$this->assertFalse(SupplierInvoiceHelper::shouldSendApprovedOnValidation($einvoicing, (int) $creditNote->id, 'invoice_supplier'));
	}

	/**
	 * A refusal the platform has not confirmed yet can still be rejected, so it does not close
	 * anything: the credit note stays open to every answer until then.
	 *
	 * @return void
	 */
	public function testCreditNoteOfAnInvoiceRefusedButNotConfirmedIsStillOpen()
	{
		global $db;

		$source = $this->createSpecimenSupplierInvoice();
		$this->insertLifecycleMessageFixture($source->id, 'invoice_supplier', EInvoicing::STATUS_REFUSED, 'NON_CONFORME', 'Pending');
		$creditNote = $this->createCorrectingSupplierInvoice((int) $source->id, FactureFournisseur::TYPE_CREDIT_NOTE);
		$this->addEInvoicingDocument($creditNote->id);

		$this->assertSame(0, SupplierInvoiceHelper::refusedSourceOfCreditNote((int) $creditNote->id));

		$einvoicing = new EInvoicing($db);
		$offered = array_map('intval', array_keys($einvoicing->getSendableStatusesForReceivedInvoice($creditNote->id, 'invoice_supplier')));
		$this->assertContains(EInvoicing::STATUS_APPROVED, $offered);
	}

	/**
	 * The nuance that makes the rule right rather than merely strict: a replacement invoice also
	 * references the document it corrects, and it is the corrected invoice the vendor sends *after* a
	 * refusal. Refusing that one too would leave the operation with no acceptable document at all.
	 *
	 * @return void
	 */
	public function testReplacementInvoiceOfARefusedInvoiceStaysAcceptable()
	{
		global $db;

		$source = $this->createSpecimenSupplierInvoice();
		$this->insertLifecycleMessageFixture($source->id, 'invoice_supplier', EInvoicing::STATUS_REFUSED, 'NON_CONFORME', 'Ok');
		$replacement = $this->createCorrectingSupplierInvoice((int) $source->id, FactureFournisseur::TYPE_REPLACEMENT);
		$this->addEInvoicingDocument($replacement->id);

		$this->assertSame(0, SupplierInvoiceHelper::refusedSourceOfCreditNote((int) $replacement->id));

		$einvoicing = new EInvoicing($db);
		$offered = array_map('intval', array_keys($einvoicing->getSendableStatusesForReceivedInvoice($replacement->id, 'invoice_supplier')));
		$this->assertContains(EInvoicing::STATUS_APPROVED, $offered, 'a replacement invoice is what one accepts after refusing the first one');
	}

	/**
	 * And an ordinary credit note, on an invoice nobody refused, is answered like any other document.
	 *
	 * @return void
	 */
	public function testCreditNoteOfAnInvoiceNobodyRefusedIsUnaffected()
	{
		global $db;

		$source = $this->createSpecimenSupplierInvoice();
		$creditNote = $this->createCorrectingSupplierInvoice((int) $source->id, FactureFournisseur::TYPE_CREDIT_NOTE);
		$this->addEInvoicingDocument($creditNote->id);

		$this->assertSame(0, SupplierInvoiceHelper::refusedSourceOfCreditNote((int) $creditNote->id));

		$einvoicing = new EInvoicing($db);
		$offered = array_map('intval', array_keys($einvoicing->getSendableStatusesForReceivedInvoice($creditNote->id, 'invoice_supplier')));
		$this->assertContains(EInvoicing::STATUS_APPROVED, $offered);
	}
}
