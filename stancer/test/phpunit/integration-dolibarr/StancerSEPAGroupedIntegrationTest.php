<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';
require_once __DIR__ . '/../Fixtures/StancerApiFixtures.php';

/**
 * Integration tests for the same-day SEPA grouping feature.
 *  - stancerSEPAstartPayGrouped()           : single Stancer payment for N same-day invoices
 *  - processInvoicesForPaymentMode() pre-pass : groups invoices by (socid, datef, mandate)
 *  - stancerReopenInvoiceFromPayment()      : multi-invoice reopen via grouped_invoice_ids
 */
class StancerSEPAGroupedIntegrationTest extends DolibarrRealTestCase
{
    /** @var int max llx_facture.rowid before the current test ran (used to clean up only OUR rows) */
    private $maxFactureBefore = 0;
    /** @var int max llx_societe.rowid before the current test ran */
    private $maxSocieteBefore = 0;
    /** @var int max llx_societe_rib.rowid before the current test ran */
    private $maxSocieteRibBefore = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupHttpMock();
        $this->configureStancerSettings();

        dol_include_once('/stancer/lib/stancer.lib.php');
        require_once __DIR__ . '/../../../class/stancer_api.class.php';

        // Snapshot DB before so tearDown can wipe only what THIS test creates.
        // The integration framework keeps documents/database_dolibarr.sdb across runs
        // and only resets Stancer-module tables in cleanModuleTables(). Without this
        // per-test cleanup our fixtures would persist and pollute sibling tests
        // (notably StancerClassIntegrationTest::testProcessInvoicesForPaymentModeSEPAWithNoInvoices).
        $this->maxFactureBefore = (int) $this->fetchScalar("SELECT COALESCE(MAX(rowid),0) AS v FROM " . MAIN_DB_PREFIX . "facture");
        $this->maxSocieteBefore = (int) $this->fetchScalar("SELECT COALESCE(MAX(rowid),0) AS v FROM " . MAIN_DB_PREFIX . "societe");
        $this->maxSocieteRibBefore = (int) $this->fetchScalar("SELECT COALESCE(MAX(rowid),0) AS v FROM " . MAIN_DB_PREFIX . "societe_rib");
    }

    protected function tearDown(): void
    {
        // Wipe ONLY rows created during this test, oldest dependent first.
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "paiement_facture WHERE fk_facture > " . $this->maxFactureBefore);
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "facturedet WHERE fk_facture > " . $this->maxFactureBefore);
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "facture_extrafields WHERE fk_object > " . $this->maxFactureBefore);
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "facture WHERE rowid > " . $this->maxFactureBefore);
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "societe_rib WHERE rowid > " . $this->maxSocieteRibBefore);
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "societe WHERE rowid > " . $this->maxSocieteBefore);
        $this->teardownHttpMock();
        parent::tearDown();
    }

    private function fetchScalar(string $sql)
    {
        $r = $this->db->query($sql);
        if (!$r) {
            return 0;
        }
        $row = $this->db->fetch_object($r);
        return $row && isset($row->v) ? $row->v : 0;
    }

    /**
     * Build a validated PRE invoice with a non-zero amount, ready to be paid.
     * Mirrors production where processInvoicesForPaymentMode() only feeds
     * validated invoices to the SEPA start functions (SQL filter fk_statut = STATUS_VALIDATED).
     */
    private function buildPREInvoice(\Societe $soc, int $preModeId, float $amount, ?int $datef = null): \Facture
    {
        global $conf;
        $invoice = $this->createTestInvoiceWithPaymentMode($soc, [
            'mode_reglement_code' => 'PRE',
            'mode_reglement_id' => $preModeId,
            'fk_account' => (int) $conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS,
            'amount' => $amount,
            'validate' => true,
            'date' => $datef ?? dol_now(),
            'date_lim_reglement' => $datef ?? dol_now(),
        ]);
        // Re-fetch to materialize totals and paye=0 in memory.
        $invoice->fetch($invoice->id);
        // Some properties get reset by fetch(); restore them so our function uses them directly.
        $invoice->mode_reglement_code = 'PRE';
        $invoice->mode_reglement_id = $preModeId;
        $invoice->fk_account = $conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS;
        $invoice->date = $datef ?? $invoice->date;
        $invoice->date_lim_reglement = $datef ?? $invoice->date_lim_reglement;
        return $invoice;
    }

    private function fetchPRECPaiementId(): int
    {
        $sql = "SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement WHERE code = 'PRE'";
        $resql = $this->db->query($sql);
        if (!$resql || $this->db->num_rows($resql) == 0) {
            $this->markTestSkipped('PRE payment mode not found in dictionary');
        }
        $obj = $this->db->fetch_object($resql);
        return (int) $obj->id;
    }

    // =========================================================================
    // stancerSEPAstartPayGrouped() : golden path
    // =========================================================================

    public function testGroupedPayGoldenPathCreatesSinglePaymentRow(): void
    {
        global $conf;
        $conf->stancer->enabled = 1;

        $soc = $this->createTestSociete(['name' => 'Grouped Pay Customer']);
        $companypaymentmodeid = $this->createTestCompanyPaymentMode($soc, [
            'type' => 'ban',
            'label' => 'stancer-sepa-grouped',
            'stancer_account' => 'cust_grouped_test',
            'stancer_object_ref' => 'sepa_grouped_test',
            'default_rib' => 1,
        ]);

        $preId = $this->fetchPRECPaiementId();
        $today = dol_now();

        // Build 3 invoices of the same customer, same day, same mandate.
        $inv1 = $this->buildPREInvoice($soc, $preId, 100.0, $today);
        $inv2 = $this->buildPREInvoice($soc, $preId, 250.0, $today);
        $inv3 = $this->buildPREInvoice($soc, $preId, 50.0, $today);

        // Mock Stancer API
        \HttpMock::addJsonResponse('https://api.stancer.com/v2/sepa/sepa_grouped_test', [
            'id' => 'sepa_grouped_test', 'last4' => '1234',
        ], 200);
        \HttpMock::addJsonResponse('https://api.stancer.com/v2/checkout/', [
            'id' => 'paym_grouped_001',
            'status' => 'authorized',
            'amount' => 40000, // 400.00 EUR in cents
            'currency' => 'eur',
            'method' => 'sepa',
            'sepa' => 'sepa_grouped_test',
            'customer' => 'cust_grouped_test',
        ], 200);

        $result = stancerSEPAstartPayGrouped([$inv1, $inv2, $inv3], (int) $companypaymentmodeid, false);

        $this->assertSame(0, $result, 'Grouped pay should succeed (0)');

        // Verify a Stancer_payments row exists with grouped_invoice_ids set.
        $sp = new \Stancer_payments($this->db);
        $resFetch = $sp->fetch(0, null, 'paym_grouped_001');
        $this->assertTrue((bool) $resFetch, 'Stancer_payments row must exist for paym_grouped_001');
        $this->assertNotEmpty($sp->grouped_invoice_ids, 'grouped_invoice_ids must be set');

        $storedIds = array_map('intval', explode(',', (string) $sp->grouped_invoice_ids));
        sort($storedIds);
        $expectedIds = array_map('intval', [$inv1->id, $inv2->id, $inv3->id]);
        sort($expectedIds);
        $this->assertSame($expectedIds, $storedIds, 'grouped_invoice_ids must contain all 3 invoice ids');

        // unique_id must follow GRP=<hash8>.CUS=<socid> pattern.
        $this->assertMatchesRegularExpression('/^GRP=[a-f0-9]{8}\.CUS=' . $soc->id . '$/', $sp->unique_id);

        // Description must concatenate refs with '+'.
        $expectedDescription = $inv1->ref . '+' . $inv2->ref . '+' . $inv3->ref;
        $this->assertSame($expectedDescription, $sp->description);

        // The Stancer API was called exactly once for createPayment.
        $this->assertTrue(\HttpMock::wasRequested('*checkout/*', 'POST'));
    }

    // =========================================================================
    // stancerSEPAstartPayGrouped() : validation errors
    // =========================================================================

    public function testGroupedPayRejectsGroupOfOne(): void
    {
        $soc = $this->createTestSociete(['name' => 'Grouped Reject Solo']);
        $companypaymentmodeid = $this->createTestCompanyPaymentMode($soc, [
            'stancer_account' => 'cust_solo', 'stancer_object_ref' => 'sepa_solo',
        ]);
        $preId = $this->fetchPRECPaiementId();
        $inv = $this->buildPREInvoice($soc, $preId, 100.0);

        $result = stancerSEPAstartPayGrouped([$inv], (int) $companypaymentmodeid, false);

        $this->assertSame(-10, $result, 'Group of 1 must be rejected with -10');
        $this->assertFalse(\HttpMock::wasRequested('*checkout/*', 'POST'), 'No API call should have happened');
    }

    public function testGroupedPayRejectsMismatchedSocid(): void
    {
        $soc1 = $this->createTestSociete(['name' => 'Customer A']);
        $soc2 = $this->createTestSociete(['name' => 'Customer B']);
        $cpm = $this->createTestCompanyPaymentMode($soc1, [
            'stancer_account' => 'cust_a', 'stancer_object_ref' => 'sepa_a',
        ]);
        $this->createTestCompanyPaymentMode($soc2, [
            'stancer_account' => 'cust_b', 'stancer_object_ref' => 'sepa_b',
        ]);

        $preId = $this->fetchPRECPaiementId();
        $invA = $this->buildPREInvoice($soc1, $preId, 100.0);
        $invB = $this->buildPREInvoice($soc2, $preId, 100.0);

        $result = stancerSEPAstartPayGrouped([$invA, $invB], (int) $cpm, false);

        $this->assertSame(-10, $result, 'Mixed socids must be rejected with -10');
        $this->assertFalse(\HttpMock::wasRequested('*checkout/*', 'POST'));
    }

    public function testGroupedPayRejectsMixedPaymentModes(): void
    {
        $soc = $this->createTestSociete(['name' => 'Mixed Mode Customer']);
        $cpm = $this->createTestCompanyPaymentMode($soc, [
            'stancer_account' => 'cust_mixed', 'stancer_object_ref' => 'sepa_mixed',
        ]);
        $preId = $this->fetchPRECPaiementId();

        $invPre = $this->buildPREInvoice($soc, $preId, 100.0);
        $invCb = $this->buildPREInvoice($soc, $preId, 100.0);
        $invCb->mode_reglement_code = 'CB';  // not PRE -> must trigger -4

        $result = stancerSEPAstartPayGrouped([$invPre, $invCb], (int) $cpm, false);

        $this->assertSame(-4, $result, 'Non-PRE invoice in group must be rejected with -4');
    }

    public function testGroupedPayRejectsDifferentDates(): void
    {
        $soc = $this->createTestSociete(['name' => 'Different Dates Customer']);
        $cpm = $this->createTestCompanyPaymentMode($soc, [
            'stancer_account' => 'cust_dates', 'stancer_object_ref' => 'sepa_dates',
        ]);
        $preId = $this->fetchPRECPaiementId();

        $today = dol_now();
        $yesterday = $today - 86400;
        $inv1 = $this->buildPREInvoice($soc, $preId, 100.0, $today);
        $inv2 = $this->buildPREInvoice($soc, $preId, 100.0, $yesterday);

        $result = stancerSEPAstartPayGrouped([$inv1, $inv2], (int) $cpm, false);

        $this->assertSame(-10, $result, 'Different invoice dates must be rejected with -10');
    }

    // =========================================================================
    // stancerSEPAstartPayGrouped() : idempotence
    // =========================================================================

    public function testGroupedPayIsIdempotent(): void
    {
        global $conf;
        $conf->stancer->enabled = 1;

        $soc = $this->createTestSociete(['name' => 'Idempotent Customer']);
        $cpm = $this->createTestCompanyPaymentMode($soc, [
            'stancer_account' => 'cust_idem', 'stancer_object_ref' => 'sepa_idem',
        ]);
        $preId = $this->fetchPRECPaiementId();

        $today = dol_now();
        $inv1 = $this->buildPREInvoice($soc, $preId, 100.0, $today);
        $inv2 = $this->buildPREInvoice($soc, $preId, 200.0, $today);

        \HttpMock::addJsonResponse('https://api.stancer.com/v2/sepa/sepa_idem', [
            'id' => 'sepa_idem',
        ], 200);
        \HttpMock::addJsonResponse('https://api.stancer.com/v2/checkout/', [
            'id' => 'paym_idem_001',
            'status' => 'authorized',
            'amount' => 30000,
            'currency' => 'eur',
            'method' => 'sepa',
            'sepa' => 'sepa_idem',
            'customer' => 'cust_idem',
        ], 200);

        $first = stancerSEPAstartPayGrouped([$inv1, $inv2], (int) $cpm, false);
        $this->assertSame(0, $first);

        // Second call with the same group: must NOT create a second row, returns -1 (already in progress).
        $second = stancerSEPAstartPayGrouped([$inv1, $inv2], (int) $cpm, false);
        $this->assertSame(-1, $second, 'Second call with the same group must be rejected as already in progress');

        // Exactly one Stancer_payments row matches that paymentId.
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments WHERE stancer_id = 'paym_idem_001'";
        $resql = $this->db->query($sql);
        $obj = $this->db->fetch_object($resql);
        $this->assertEquals(1, (int) $obj->cnt, 'Only one Stancer_payments row must exist for paym_idem_001');
    }

    // =========================================================================
    // stancerReopenInvoiceFromPayment() : grouped path returns array<Facture>
    // =========================================================================

    public function testReopenGroupedPaymentReturnsArrayOfInvoices(): void
    {
        $soc = $this->createTestSociete(['name' => 'Reopen Grouped Customer']);
        $preId = $this->fetchPRECPaiementId();

        $inv1 = $this->buildPREInvoice($soc, $preId, 100.0);
        $inv2 = $this->buildPREInvoice($soc, $preId, 200.0);
        $inv1->validate($this->testUser);
        $inv2->validate($this->testUser);

        // Create a Stancer_payments row simulating a successful grouped capture.
        $sp = new \Stancer_payments($this->db);
        $sp->stancer_id = 'paym_grouped_reopen';
        $sp->amount = 30000;
        $sp->currency = 'eur';
        $sp->status = \Stancer_payments::STATUS_CAPTURED;
        $sp->method = 'sepa';
        $sp->description = $inv1->ref . '+' . $inv2->ref;
        $sp->fk_soc = $soc->id;
        $sp->live_mode = 0;
        $sp->unique_id = 'GRP=deadbeef.CUS=' . $soc->id;
        $sp->order_id = $inv1->ref . '+' . $inv2->ref;
        $sp->grouped_invoice_ids = $inv1->id . ',' . $inv2->id;
        $sp->create($this->testUser);

        // Mark both invoices as paid (status 2) so reopen has something to do.
        $inv1->setPaid($this->testUser);
        $inv2->setPaid($this->testUser);

        $reopenResult = stancerReopenInvoiceFromPayment('paym_grouped_reopen', 'integration-test-reopen');

        $this->assertIsArray($reopenResult, 'Reopen on grouped payment must return an array of Facture');
        $this->assertCount(2, $reopenResult, 'Both invoices must have been reopened');
        foreach ($reopenResult as $f) {
            $this->assertInstanceOf(\Facture::class, $f);
        }

        $reopenedIds = array_map(function ($f) { return $f->id; }, $reopenResult);
        sort($reopenedIds);
        $expected = [$inv1->id, $inv2->id];
        sort($expected);
        $this->assertSame($expected, $reopenedIds);
    }

    public function testReopenSoloPaymentStillReturnsSingleFacture(): void
    {
        // Backward compatibility: solo payments must still return a single Facture (not array).
        $soc = $this->createTestSociete(['name' => 'Reopen Solo Customer']);
        $preId = $this->fetchPRECPaiementId();
        $inv = $this->buildPREInvoice($soc, $preId, 100.0);
        $inv->validate($this->testUser);

        $sp = new \Stancer_payments($this->db);
        $sp->stancer_id = 'paym_solo_reopen';
        $sp->amount = 10000;
        $sp->currency = 'eur';
        $sp->status = \Stancer_payments::STATUS_CAPTURED;
        $sp->method = 'sepa';
        $sp->description = $inv->ref;
        $sp->fk_soc = $soc->id;
        $sp->live_mode = 0;
        $sp->unique_id = 'INV=' . $inv->id . '.CUS=' . $soc->id;
        $sp->order_id = $inv->ref;
        $sp->grouped_invoice_ids = null;  // solo
        $sp->create($this->testUser);

        $inv->setPaid($this->testUser);

        $result = stancerReopenInvoiceFromPayment('paym_solo_reopen', 'integration-test-solo-reopen');

        $this->assertNotInstanceOf(\Generator::class, $result);
        // Either a Facture object (success) or 0 (skipped). Never an array for solo.
        $this->assertFalse(is_array($result), 'Solo reopen must NOT return an array');
        if (is_object($result)) {
            $this->assertInstanceOf(\Facture::class, $result);
            $this->assertSame($inv->id, $result->id);
        }
    }
}
