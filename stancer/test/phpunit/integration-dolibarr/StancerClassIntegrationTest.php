<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';
require_once __DIR__ . '/../Fixtures/StancerApiFixtures.php';

/**
 * Integration tests for Stancer class (CRON methods)
 */
class StancerClassIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupHttpMock();
        $this->configureStancerSettings();

        // Load the Stancer class
        require_once __DIR__ . '/../../../class/stancer.class.php';
    }

    protected function tearDown(): void
    {
        $this->teardownHttpMock();
        parent::tearDown();
    }

    // =========================================================================
    // doScheduledJob() Tests
    // =========================================================================

    public function testDoScheduledJobReturnsErrorWhenModuleDisabled(): void
    {
        global $conf;
        $conf->stancer->enabled = 0;

        $stancer = new \Stancer($this->db);
        $result = $stancer->doScheduledJob();

        $this->assertEquals(-1, $result);
        $this->assertStringContainsString('not enabled', $stancer->error);
    }

    public function testDoScheduledJobReturnsErrorWhenNotInProduction(): void
    {
        global $conf;
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_IS_PROD = '0';

        $stancer = new \Stancer($this->db);
        $result = $stancer->doScheduledJob();

        $this->assertEquals(-1, $result);
        $this->assertStringContainsString('not in production', $stancer->error);
    }

    // =========================================================================
    // doTakePaymentStancer() Tests
    // =========================================================================

    public function testDoTakePaymentStancerReturnsErrorWhenModuleDisabled(): void
    {
        global $conf;
        $conf->stancer->enabled = 0;

        $stancer = new \Stancer($this->db);
        $result = $stancer->doTakePaymentStancer();

        $this->assertEquals(-1, $result);
        $this->assertStringContainsString('not enabled', $stancer->error);
    }

    public function testDoTakePaymentStancerReturnsErrorWhenNotInProduction(): void
    {
        global $conf;
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_IS_PROD = '0';

        $stancer = new \Stancer($this->db);
        $result = $stancer->doTakePaymentStancer();

        $this->assertEquals(-1, $result);
        $this->assertStringContainsString('not in production', $stancer->error);
    }

    // =========================================================================
    // doCheckInvoicesPaid() Tests
    // =========================================================================

    public function testDoCheckInvoicesPaidReturnsErrorWhenModuleDisabled(): void
    {
        global $conf;
        $conf->stancer->enabled = 0;

        $stancer = new \Stancer($this->db);
        $result = $stancer->doCheckInvoicesPaid();

        $this->assertEquals(-1, $result);
        $this->assertStringContainsString('not enabled', $stancer->error);
    }

    public function testDoCheckInvoicesPaidReturnsErrorWhenNotInProduction(): void
    {
        global $conf;
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_IS_PROD = '0';

        $stancer = new \Stancer($this->db);
        $result = $stancer->doCheckInvoicesPaid();

        $this->assertEquals(-1, $result);
        $this->assertStringContainsString('not in production', $stancer->error);
    }

    // =========================================================================
    // createEvent() Tests
    // =========================================================================

    public function testCreateEventCreatesActionComm(): void
    {
        // Create a test societe
        $soc = $this->createTestSociete(['name' => 'Event Test Company']);

        // Create a test invoice
        $invoice = $this->createTestInvoice($soc);

        $stancer = new \Stancer($this->db);
        $result = $stancer->createEvent(
            $invoice,
            'STANCER_PAY_TEST',
            'Test Payment Event',
            'Test payment message'
        );

        $this->assertEquals(1, $result);
    }

    // =========================================================================
    // processInvoicesForPaymentMode() Tests - SEPA
    // =========================================================================

    public function testProcessInvoicesForPaymentModeSEPAWithNoInvoices(): void
    {
        global $conf;
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_IS_PROD = '1';
        $conf->global->STANCER_ENABLE_SEPA = '1';

        $stancer = new \Stancer($this->db);

        $invoiceprocessed = [];
        $invoiceprocessedok = [];
        $invoiceprocessedko = [];
        $invoiceprocessedinfo = [];
        $invoiceprocessedwaitingduedate = [];

        // Get SEPA payment mode ID (PRE)
        $sql = "SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement WHERE code = 'PRE'";
        $resql = $this->db->query($sql);
        if (!$resql || $this->db->num_rows($resql) == 0) {
            $this->markTestSkipped('PRE payment mode not found in database');
        }
        $obj = $this->db->fetch_object($resql);
        $idpaiementpre = $obj->id;

        // The SQLite test DB is shared across the whole test suite and accumulates fixtures
        // (factures + societe_rib) created by other SEPA tests. To genuinely test "no invoices
        // match", target a thirdparty_id that cannot exist: MAX(rowid) + 100 in llx_societe.
        $rMax = $this->db->query("SELECT COALESCE(MAX(rowid), 0) AS m FROM " . MAIN_DB_PREFIX . "societe");
        $oMax = $this->db->fetch_object($rMax);
        $nonExistentSocId = ((int) $oMax->m) + 100;

        $result = $stancer->processInvoicesForPaymentMode(
            'ban',
            $idpaiementpre,
            $invoiceprocessed,
            $invoiceprocessedok,
            $invoiceprocessedko,
            $invoiceprocessedinfo,
            $invoiceprocessedwaitingduedate,
            0,
            $nonExistentSocId
        );

        // No error when no invoices to process
        $this->assertEquals(0, $result);
        $this->assertEmpty($invoiceprocessed);
    }

    // =========================================================================
    // doCheckInvoicesPaid() - Happy path (pure DB)
    // =========================================================================

    public function testDoCheckInvoicesPaidMarksFullyPaidInvoiceAsPaid(): void
    {
        global $conf;
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_IS_PROD = '1';

        // Create a company and an invoice
        $soc = $this->createTestSociete(['name' => 'CheckPaid Test Company']);
        $invoice = $this->createTestInvoiceWithPaymentMode($soc, [
            'mode_reglement_code' => 'CB',
            'amount' => 100,
            'validate' => true,
        ]);
        $invoice->fetch($invoice->id);

        // Simulate a payment that covers total_ttc by inserting into paiement_facture
        // First create a paiement entry
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "paiement (datep, amount, fk_paiement, num_paiement, entity) ";
        $sql .= "VALUES ('" . date('Y-m-d') . "', " . $invoice->total_ttc . ", 6, 'STANCER_TEST', 1)";
        $this->db->query($sql);
        $paiementId = $this->db->last_insert_id(MAIN_DB_PREFIX . 'paiement');

        // Link payment to invoice
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "paiement_facture (fk_paiement, fk_facture, amount, multicurrency_amount) ";
        $sql .= "VALUES (" . $paiementId . ", " . $invoice->id . ", " . $invoice->total_ttc . ", " . $invoice->total_ttc . ")";
        $this->db->query($sql);

        // Verify getSommePaiement returns total_ttc
        $invoice->fetch($invoice->id);
        $paid = $invoice->getSommePaiement();
        $this->assertEquals($invoice->total_ttc, $paid, 'Invoice should be fully paid via paiement_facture');

        // Call doCheckInvoicesPaid
        $stancer = new \Stancer($this->db);
        $result = $stancer->doCheckInvoicesPaid();

        $this->assertEquals(0, $result);

        // Verify the invoice is now marked as paid
        $check = new \Facture($this->db);
        $check->fetch($invoice->id);
        $this->assertEquals(1, $check->paye, 'Invoice should be marked as paye after doCheckInvoicesPaid');
    }

    public function testDoCheckInvoicesPaidDoesNotMarkPartiallyPaidInvoice(): void
    {
        global $conf;
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_IS_PROD = '1';

        $soc = $this->createTestSociete(['name' => 'Partial Paid Test']);
        $invoice = $this->createTestInvoiceWithPaymentMode($soc, [
            'mode_reglement_code' => 'CB',
            'amount' => 200,
            'validate' => true,
        ]);
        $invoice->fetch($invoice->id);

        // Only pay half
        $halfAmount = $invoice->total_ttc / 2;
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "paiement (datep, amount, fk_paiement, num_paiement, entity) ";
        $sql .= "VALUES ('" . date('Y-m-d') . "', " . $halfAmount . ", 6, 'STANCER_HALF', 1)";
        $this->db->query($sql);
        $paiementId = $this->db->last_insert_id(MAIN_DB_PREFIX . 'paiement');

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "paiement_facture (fk_paiement, fk_facture, amount, multicurrency_amount) ";
        $sql .= "VALUES (" . $paiementId . ", " . $invoice->id . ", " . $halfAmount . ", " . $halfAmount . ")";
        $this->db->query($sql);

        $stancer = new \Stancer($this->db);
        $result = $stancer->doCheckInvoicesPaid();

        $this->assertEquals(0, $result);

        // Invoice should NOT be marked as paid
        $check = new \Facture($this->db);
        $check->fetch($invoice->id);
        $this->assertEquals(0, $check->paye, 'Partially paid invoice should not be marked as paye');
    }

    public function testDoCheckInvoicesPaidWithNoUnpaidInvoices(): void
    {
        global $conf;
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_IS_PROD = '1';

        // No invoices created - just check no error
        $stancer = new \Stancer($this->db);
        $result = $stancer->doCheckInvoicesPaid();

        $this->assertEquals(0, $result);
        $this->assertEmpty($stancer->error);
    }
}
