<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Tests pinning the double-billing bugs revealed by the NITD incident
 * (5 invoices charged twice on 13/05 and 14/05, total 50.40 EUR over-paid).
 *
 * Root cause split in two parts that REINFORCE each other:
 *  - Bug A: processInvoicesForPaymentMode() grouped branch
 *    (class/stancer.class.php:264-307) jumps straight to
 *    stancerSEPAstartPayGrouped() WITHOUT calling stancerCheckIfPaymentInProgress
 *    for each invoice of the group. The legacy single-invoice loop further
 *    down DOES call it, but groups of size >= 2 never reach that loop.
 *  - Bug B: stancerCheckIfPaymentInProgress() itself only matches on
 *    unique_id LIKE '%INV=ID' / order_id LIKE '%REF%'. Grouped payments
 *    store unique_id = 'GRP=<hash>.CUS=<id>' and put the per-invoice ids
 *    in the separate grouped_invoice_ids column. So even when called, the
 *    check is blind to grouped payments and returns false.
 *
 * Both tests below are EXPECTED TO FAIL on the current code base and to
 * PASS after the fix.
 */
class StancerGroupingDoubleBillIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupHttpMock();
        $this->configureStancerSettings();

        dol_include_once('/stancer/lib/stancer.lib.php');
        dol_include_once('/stancer/lib/stancer_refresh.lib.php');
        dol_include_once('/stancer/lib/stancer_bank.lib.php');
        dol_include_once('/stancer/lib/stancer_payment.lib.php');

        require_once __DIR__ . '/../../../class/stancer_api.class.php';
        require_once __DIR__ . '/../../../class/stancer_payments.class.php';
        require_once __DIR__ . '/../../../class/stancer.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
        require_once DOL_DOCUMENT_ROOT . '/societe/class/companypaymentmode.class.php';

        global $conf;
        if (!isset($conf->modules) || !is_array($conf->modules)) {
            $conf->modules = [];
        }
        $conf->modules['facture'] = 'facture';
        $conf->modules['banque'] = 'banque';
        if (!isset($conf->banque) || !is_object($conf->banque)) {
            $conf->banque = new \stdClass();
        }
        $conf->banque->enabled = 1;

        if (!isset($conf->stancer) || !is_object($conf->stancer)) {
            $conf->stancer = new \stdClass();
        }
        $conf->stancer->enabled = 1;
    }

    protected function tearDown(): void
    {
        global $conf;
        // The grouping flag is toggled by some tests; reset to a safe default
        // so the suite stays order-independent.
        if (isset($conf->global->STANCER_SEPA_GROUP_SAME_DAY)) {
            unset($conf->global->STANCER_SEPA_GROUP_SAME_DAY);
        }
        $this->teardownHttpMock();
        parent::tearDown();
    }

    private function fetchPRECPaiementId(): int
    {
        $sql = "SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement WHERE code = 'PRE'";
        $r = $this->db->query($sql);
        if (!$r || $this->db->num_rows($r) == 0) {
            $this->markTestSkipped('PRE payment mode not found');
        }
        $obj = $this->db->fetch_object($r);
        return (int) $obj->id;
    }

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
        $invoice->fetch($invoice->id);
        $invoice->mode_reglement_code = 'PRE';
        $invoice->mode_reglement_id = $preModeId;
        $invoice->fk_account = $conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS;
        return $invoice;
    }

    // =========================================================================
    // Bug B: stancerCheckIfPaymentInProgress must see invoices listed in
    // grouped_invoice_ids of any "live" Stancer payment.
    // =========================================================================
    public function testCheckIfPaymentInProgressFindsInvoiceInGroupedIds(): void
    {
        $soc = $this->createTestSociete(['name' => 'GroupedInProgress']);
        $preId = $this->fetchPRECPaiementId();

        $inv1 = $this->buildPREInvoice($soc, $preId, 100.0);
        $inv2 = $this->buildPREInvoice($soc, $preId, 200.0);

        // Real-world reproduction: Stancer truncates order_id (max 36 chars)
        // so when a group covers >= 2 invoices, only one ref typically fits in.
        // The OTHER invoices of the group are then invisible to the current
        // anti-doublon SQL which only matches on unique_id ('GRP=...') and
        // order_id ('FA...'). Hence we deliberately put inv1->ref ONLY into
        // order_id and assert on inv2, which has no anchor outside
        // grouped_invoice_ids.
        $stancerId = 'paym_grouped_inprogress_' . uniqid();
        $sp = new \Stancer_payments($this->db);
        $sp->stancer_id = $stancerId;
        $sp->amount = 30000;
        $sp->currency = 'eur';
        $sp->status = \Stancer_payments::STATUS_TO_CAPTURE;
        $sp->method = 'sepa';
        $sp->description = $inv1->ref . '+' . $inv2->ref;
        $sp->fk_soc = $soc->id;
        $sp->live_mode = 0;
        $sp->unique_id = 'GRP=abc12345.CUS=' . $soc->id;
        $sp->order_id = $inv1->ref;  // truncated to inv1 ref only (mimics prod)
        $sp->grouped_invoice_ids = $inv1->id . ',' . $inv2->id;
        $sp->create($this->testUser);

        \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
            'id' => $stancerId,
            'status' => 'to_capture',
            'amount' => 30000,
            'currency' => 'eur',
            'method' => 'sepa',
        ]);

        $result = stancerCheckIfPaymentInProgress($inv2);

        $this->assertTrue(
            $result,
            'stancerCheckIfPaymentInProgress must report "in progress" for inv2 even though only inv1->ref is '
                . 'in order_id: inv2 is listed in grouped_invoice_ids of the live grouped payment. '
                . 'Without this, the cron retries an already-engaged payment and the customer is billed twice.'
        );
    }

    // =========================================================================
    // Same bug, exhaustive on the position of the id within the comma list:
    // "ID", "ID,X", "X,ID", "X,ID,Y". A naive LIKE '%,ID,%' would miss
    // start/end/solo positions.
    // =========================================================================
    public function testCheckIfPaymentInProgressGroupedIdsAtAllPositions(): void
    {
        $soc = $this->createTestSociete(['name' => 'GroupedPositions']);
        $preId = $this->fetchPRECPaiementId();
        $inv = $this->buildPREInvoice($soc, $preId, 100.0);
        $otherId1 = $inv->id + 999;
        $otherId2 = $inv->id + 998;

        $positions = [
            'solo'   => (string) $inv->id,
            'start'  => $inv->id . ',' . $otherId1,
            'end'    => $otherId1 . ',' . $inv->id,
            'middle' => $otherId2 . ',' . $inv->id . ',' . $otherId1,
        ];

        foreach ($positions as $label => $list) {
            \HttpMock::reset();
            // Wipe local payments to keep each iteration independent.
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments");

            $stancerId = 'paym_pos_' . $label . '_' . uniqid();
            $sp = new \Stancer_payments($this->db);
            $sp->stancer_id = $stancerId;
            $sp->amount = 1000;
            $sp->currency = 'eur';
            $sp->status = \Stancer_payments::STATUS_TO_CAPTURE;
            $sp->method = 'sepa';
            $sp->fk_soc = $soc->id;
            $sp->live_mode = 0;
            $sp->unique_id = 'GRP=' . substr(md5($label), 0, 8) . '.CUS=' . $soc->id;
            $sp->grouped_invoice_ids = $list;
            $sp->create($this->testUser);

            \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
                'id' => $stancerId,
                'status' => 'to_capture',
                'amount' => 1000,
                'currency' => 'eur',
                'method' => 'sepa',
            ]);

            $this->assertTrue(
                stancerCheckIfPaymentInProgress($inv),
                "stancerCheckIfPaymentInProgress must match when invoice id is at the '$label' position of "
                    . "grouped_invoice_ids='$list'. Otherwise the SEPA-group cron retries and the customer is billed twice."
            );
        }
    }

    // =========================================================================
    // Bug A: the grouped branch of processInvoicesForPaymentMode must NOT
    // create a new grouped payment when at least one invoice of the group
    // already has a live Stancer payment.
    //
    // Reproduces the NITD scenario:
    //  - Day 1, 23:00: cron starts paym_A on invoice X (status to_capture)
    //  - Day 2, 23:00: cron runs again. The grouped branch picks up X (still paye=0)
    //    and groups it with another same-day invoice. Without an anti-doublon
    //    check, stancerSEPAstartPayGrouped() is called and a SECOND payment is
    //    created at Stancer, leading to double billing.
    // =========================================================================
    public function testGroupedPathMustNotRetryAnInvoiceAlreadyInProgress(): void
    {
        global $conf;
        $conf->global->STANCER_SEPA_GROUP_SAME_DAY = '1';

        $soc = $this->createTestSociete(['name' => 'GroupBypassCheck']);
        $cpm = $this->createTestCompanyPaymentMode($soc, [
            'type' => 'ban',
            'stancer_account' => 'cust_bypasscheck',
            'stancer_object_ref' => 'sepa_bypasscheck',
        ]);

        $preId = $this->fetchPRECPaiementId();
        $today = dol_now();
        $inv1 = $this->buildPREInvoice($soc, $preId, 100.0, $today);
        $inv2 = $this->buildPREInvoice($soc, $preId, 200.0, $today);

        // Pre-existing live payment for inv1 (the bug scenario)
        $existingStancerId = 'paym_already_inprogress_' . uniqid();
        $sp = new \Stancer_payments($this->db);
        $sp->stancer_id = $existingStancerId;
        $sp->amount = 10000;
        $sp->currency = 'eur';
        $sp->status = \Stancer_payments::STATUS_TO_CAPTURE;
        $sp->method = 'sepa';
        $sp->fk_soc = $soc->id;
        $sp->live_mode = 0;
        $sp->unique_id = 'CUS=' . $soc->id . '.INV=' . $inv1->id;
        $sp->order_id = $inv1->ref;
        $sp->create($this->testUser);

        // API confirms it is still live.
        \HttpMock::addJsonResponse('*checkout/' . $existingStancerId . '*', [
            'id' => $existingStancerId,
            'status' => 'to_capture',
            'amount' => 10000,
            'currency' => 'eur',
            'method' => 'sepa',
        ]);

        // Mocks the grouped path *would* use if the bug let it through.
        // Sentinel id 'paym_should_not_happen': if we ever see it in the
        // base afterwards, the anti-doublon was bypassed.
        \HttpMock::addJsonResponse('*sepa/sepa_bypasscheck*', [
            'id' => 'sepa_bypasscheck', 'last4' => '1234',
        ]);
        \HttpMock::addJsonResponse('*checkout/', [
            'id' => 'paym_should_not_happen',
            'status' => 'authorized',
            'amount' => 30000,
            'currency' => 'eur',
            'method' => 'sepa',
        ]);

        $invoiceprocessed = $invoiceprocessedok = $invoiceprocessedko = $invoiceprocessedinfo = $invoiceprocessedwaitingduedate = [];

        $stc = new \Stancer($this->db);
        $stc->processInvoicesForPaymentMode(
            'ban',
            $preId,
            $invoiceprocessed,
            $invoiceprocessedok,
            $invoiceprocessedko,
            $invoiceprocessedinfo,
            $invoiceprocessedwaitingduedate,
            0,
            $soc->id,
            true   // $isautomatic=true to match the cron path
        );

        $sqlCheck = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments"
            . " WHERE stancer_id = 'paym_should_not_happen'";
        $resCheck = $this->db->query($sqlCheck);
        $row = $this->db->fetch_object($resCheck);
        $this->assertEquals(
            0,
            (int) $row->cnt,
            'processInvoicesForPaymentMode grouped branch must NOT create a new payment when one of the '
                . 'invoices already has a Stancer payment in progress. This is the root cause of the NITD '
                . 'double-billing: the cron retried 12/05 and 13/05 because the grouped path bypasses '
                . 'stancerCheckIfPaymentInProgress.'
        );
    }
}
