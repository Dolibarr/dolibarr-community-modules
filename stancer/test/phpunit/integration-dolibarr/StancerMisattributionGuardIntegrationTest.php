<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Tests pinning the defensive guards added to stancerAddPaymentOnObject after the
 * NITD/PICHINOV/BLUE HORSE GROUP incident where Dolibarr Paiement rows were created
 * on NITD invoices with paym_id, customer and amount of other Stancer customers.
 *
 * Root cause (out of scope for these tests): a paym_id of customer X gets associated
 * to a fulltag pointing at the invoice of customer Y, then paymentback.php / the
 * refresh cron create a Dolibarr Paiement that mixes data from both worlds.
 *
 * Defense: refuse to insert when ANY of the three invariants below is broken:
 *  - the Stancer payment's order_id does not match the Dolibarr invoice ref
 *  - the Stancer payment's customer (cust_xxx) does not match a societe_rib of the
 *    invoice's fk_soc
 *  - the Stancer payment amount exceeds the remaining-to-pay of the invoice (+1 cent
 *    tolerance for rounding)
 *
 * These tests are EXPECTED TO FAIL on the current code base (no validation), and to
 * PASS once stancerAddPaymentOnObject enforces the invariants.
 */
class StancerMisattributionGuardIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupHttpMock();
        $this->configureStancerSettings();

        dol_include_once('/stancer/lib/stancer.lib.php');
        dol_include_once('/stancer/lib/stancer_bank.lib.php');
        dol_include_once('/stancer/lib/stancer_payment.lib.php');

        require_once __DIR__ . '/../../../class/stancer_api.class.php';
        require_once __DIR__ . '/../../../class/stancer_payments.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';

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
    }

    protected function tearDown(): void
    {
        $this->teardownHttpMock();
        parent::tearDown();
    }

    private function buildValidatedInvoice(\Societe $soc, float $amountHT): \Facture
    {
        $invoice = new \Facture($this->db);
        $invoice->socid = $soc->id;
        $invoice->date = dol_now();
        $invoice->create($this->testUser);
        $invoice->addline('Test line', $amountHT, 1, 0, 0, 0, 0, 0, '', '', 0, 0, '', 'HT');
        $invoice->fetch($invoice->id);
        $invoice->validate($this->testUser);
        $invoice->fetch($invoice->id);
        return $invoice;
    }

    private function countDolibarrPaymentsForStancerId(string $stancerId): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "paiement WHERE num_paiement = '" . $this->db->escape($stancerId) . "'";
        $res = $this->db->query($sql);
        $row = $this->db->fetch_object($res);
        return (int) $row->cnt;
    }

    private function baseData(string $stancerId, string $invoiceRef, float $amountEur): array
    {
        return [
            'payment_id' => $stancerId,
            'date' => date('Y-m-d'),
            'FinalPaymentAmt' => $amountEur,
            'paymentType' => 'CB',
            'paymentTypeId' => (int) dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1),
            'ipaddress' => '127.0.0.1',
            'TRANSACTIONID' => $stancerId,
            'service' => 'stancer',
            'paymentmethod' => 'stancer',
            'label' => '(CustomerInvoicePayment)',
            'FinalFees' => 0,
            'ref' => $invoiceRef,
        ];
    }

    // =========================================================================
    // Invariant 1: order_id from the Stancer API must match the invoice ref.
    // Reproduces paym_8u0KrgzGwLDnSsQZS9jyIg3z scenario: Stancer order_id was
    // 'FA2603-4940' (PICHINOV), but Dolibarr posted the Paiement on NITD's
    // 'FA2603-4900' (one digit different).
    // =========================================================================
    public function testGuardRejectsPaymentWhenApiOrderIdMismatchesInvoiceRef(): void
    {
        $soc = $this->createTestSociete(['name' => 'OrderIdMismatch']);
        $invoice = $this->buildValidatedInvoice($soc, 4.20);

        $stancerId = 'paym_orderid_mismatch_' . uniqid();
        $data = $this->baseData($stancerId, $invoice->ref, 4.20);
        // Stancer side actually belongs to another invoice.
        $data['api_order_id'] = 'FA-OTHER-CLIENT-1234';

        $errorMessage = '';
        $res = stancerAddPaymentOnObject($invoice, $data, $errorMessage);

        $this->assertLessThan(
            0,
            $res,
            "Guard must REFUSE when api_order_id ('FA-OTHER-CLIENT-1234') does not match "
                . "the invoice ref ('" . $invoice->ref . "'). Got result=$res, errorMessage='$errorMessage'"
        );
        $this->assertEquals(
            0,
            $this->countDolibarrPaymentsForStancerId($stancerId),
            'No Paiement row should have been written when order_id mismatches'
        );
    }

    // =========================================================================
    // Invariant 2: customer.id from the Stancer API must match a societe_rib
    // row of the invoice's fk_soc. Reproduces paym_c2P scenario where Stancer
    // customer was BLUE HORSE GROUP but Dolibarr posted on NITD's invoice.
    // =========================================================================
    public function testGuardRejectsPaymentWhenApiCustomerMismatchesInvoiceSocid(): void
    {
        $nitd = $this->createTestSociete(['name' => 'NITD']);
        $bhg = $this->createTestSociete(['name' => 'BLUE HORSE GROUP']);

        // Seed a societe_rib for BHG (cust_zzz on Stancer side).
        $this->createTestCompanyPaymentMode($bhg, [
            'type' => 'card',
            'label' => 'stancer-card-bhg',
            'stancer_account' => 'cust_bhg_test',
            'stancer_object_ref' => 'card_bhg_test',
        ]);

        $nitdInvoice = $this->buildValidatedInvoice($nitd, 33.60);

        $stancerId = 'paym_custmismatch_' . uniqid();
        $data = $this->baseData($stancerId, $nitdInvoice->ref, 12.00);
        // order_id matches the invoice ref (the bug came in via the FULLTAG)
        $data['api_order_id'] = $nitdInvoice->ref;
        // But the Stancer customer is BHG, not NITD.
        $data['api_customer_id'] = 'cust_bhg_test';

        $errorMessage = '';
        $res = stancerAddPaymentOnObject($nitdInvoice, $data, $errorMessage);

        $this->assertLessThan(
            0,
            $res,
            "Guard must REFUSE when api_customer_id is mapped to a Dolibarr socid "
                . "different from the invoice's fk_soc. Got result=$res, errorMessage='$errorMessage'"
        );
        $this->assertEquals(
            0,
            $this->countDolibarrPaymentsForStancerId($stancerId),
            'No Paiement row should have been written when customer mismatches'
        );
    }

    // =========================================================================
    // Invariant 3: FinalPaymentAmt must not exceed remaining-to-pay (+ 0.01 EUR
    // tolerance for rounding). Reproduces the over-billing seen on FA2603-4900
    // (total 4.20 EUR, paid amount 12 EUR).
    // =========================================================================
    public function testGuardRejectsPaymentWhenAmountExceedsRemaining(): void
    {
        $soc = $this->createTestSociete(['name' => 'AmountExceeds']);
        $invoice = $this->buildValidatedInvoice($soc, 4.20);

        $stancerId = 'paym_overpay_' . uniqid();
        $data = $this->baseData($stancerId, $invoice->ref, 12.00);
        $data['api_order_id'] = $invoice->ref;

        $errorMessage = '';
        $res = stancerAddPaymentOnObject($invoice, $data, $errorMessage);

        $this->assertLessThan(
            0,
            $res,
            "Guard must REFUSE when FinalPaymentAmt (12.00 EUR) exceeds the invoice's "
                . "remaining-to-pay (4.20 EUR). Got result=$res, errorMessage='$errorMessage'"
        );
        $this->assertEquals(
            0,
            $this->countDolibarrPaymentsForStancerId($stancerId),
            'No Paiement row should have been written when amount overflows remaining'
        );
    }

    // =========================================================================
    // Happy path: all three invariants satisfied -> creation succeeds.
    // =========================================================================
    public function testGuardLetsThroughLegitimatePayment(): void
    {
        $soc = $this->createTestSociete(['name' => 'LegitimateClient']);
        // Unique stancer_account to avoid cross-pollution with other tests in the suite
        $custId = 'cust_legit_' . uniqid();
        $this->createTestCompanyPaymentMode($soc, [
            'type' => 'card',
            'label' => 'stancer-card-legit',
            'stancer_account' => $custId,
            'stancer_object_ref' => 'card_legit_' . uniqid(),
        ]);
        $invoice = $this->buildValidatedInvoice($soc, 25.00);

        $stancerId = 'paym_legit_' . uniqid();
        $data = $this->baseData($stancerId, $invoice->ref, $invoice->total_ttc);
        $data['api_order_id'] = $invoice->ref;
        $data['api_customer_id'] = $custId;

        $errorMessage = '';
        $res = stancerAddPaymentOnObject($invoice, $data, $errorMessage);

        // We assert the new guards (-10/-11/-12) did NOT fire. The actual return code
        // can still be non-zero in the test environment (eg addPaymentToBank may fail
        // because no Account row exists), which is unrelated to misattribution.
        $this->assertNotEquals(-10, $res, "Order-id guard must not fire: errorMessage='$errorMessage'");
        $this->assertNotEquals(-11, $res, "Customer guard must not fire: errorMessage='$errorMessage'");
        $this->assertNotEquals(-12, $res, "Amount guard must not fire: errorMessage='$errorMessage'");
    }

    // =========================================================================
    // Backward compatibility: when api_order_id / api_customer_id are absent
    // from $data (callers that haven't been updated yet), the guard must
    // degrade gracefully and STILL enforce the amount invariant.
    // =========================================================================
    public function testGuardWithoutApiFieldsStillEnforcesAmountInvariant(): void
    {
        $soc = $this->createTestSociete(['name' => 'AmountOnlyClient']);
        $invoice = $this->buildValidatedInvoice($soc, 10.00);

        $stancerId = 'paym_amount_only_' . uniqid();
        $data = $this->baseData($stancerId, $invoice->ref, 50.00); // overpay, no api_* fields

        $errorMessage = '';
        $res = stancerAddPaymentOnObject($invoice, $data, $errorMessage);

        $this->assertLessThan(0, $res, "Amount invariant must fire even without api_* fields. errorMessage='$errorMessage'");
        $this->assertEquals(0, $this->countDolibarrPaymentsForStancerId($stancerId));
    }

    // =========================================================================
    // Supervised force-post: the 4th arg ($bypassCustomerGuard=true) must skip
    // ONLY Guard 2 (customer), while Guard 1 (order_id) and Guard 3 (amount) stay
    // enforced. Same setup as the BHG customer-mismatch test.
    // =========================================================================
    public function testBypassFlagSkipsCustomerGuardOnly(): void
    {
        $nitd = $this->createTestSociete(['name' => 'NITDBypass']);
        $bhg  = $this->createTestSociete(['name' => 'BHGBypass']);
        $custBhg = 'cust_bhg_bypass_' . uniqid();
        $this->createTestCompanyPaymentMode($bhg, [
            'type' => 'card',
            'label' => 'stancer-card-bhgbypass',
            'stancer_account' => $custBhg,
        ]);

        $invoice = $this->buildValidatedInvoice($nitd, 25.00);
        $stancerId = 'paym_bypass_' . uniqid();
        $data = $this->baseData($stancerId, $invoice->ref, $invoice->total_ttc);
        $data['api_order_id']    = $invoice->ref; // order_id matches -> Guard 1 OK
        $data['api_customer_id'] = $custBhg;       // customer maps to BHG -> Guard 2 would fire

        // Default (no bypass): customer guard fires.
        $err = '';
        $res = stancerAddPaymentOnObject($invoice, $data, $err, false);
        $this->assertEquals(-11, $res, "Without bypass, customer guard must fire. err=$err");

        // With bypass: customer guard is skipped (never -11). It may still fail on
        // the missing bank account (-5) in the test env, but never on Guard 2.
        $err2 = '';
        $res2 = stancerAddPaymentOnObject($invoice, $data, $err2, true);
        $this->assertNotEquals(-11, $res2, "With bypass, customer guard must NOT fire. err=$err2");

        // Guard 1 is still enforced even with bypass: a wrong order_id is refused.
        $data['api_order_id'] = 'FA-WRONG-' . uniqid();
        $err3 = '';
        $res3 = stancerAddPaymentOnObject($invoice, $data, $err3, true);
        $this->assertEquals(-10, $res3, "With bypass, order_id guard must STILL fire. err=$err3");
    }

    // =========================================================================
    // The 5th arg ($bypassAmountGuard=true) must skip ONLY Guard 3 (amount),
    // for the supervised "add anyway / over-pay" action on a double payment.
    // =========================================================================
    public function testBypassFlagSkipsAmountGuard(): void
    {
        $soc = $this->createTestSociete(['name' => 'OverpayBypass']);
        $invoice = $this->buildValidatedInvoice($soc, 10.00);

        $stancerId = 'paym_overbypass_' . uniqid();
        $data = $this->baseData($stancerId, $invoice->ref, 50.00); // 50 > 10 ttc -> over-pay
        $data['api_order_id'] = $invoice->ref;

        // Default: amount guard fires (-12).
        $err = '';
        $res = stancerAddPaymentOnObject($invoice, $data, $err, false, false);
        $this->assertEquals(-12, $res, "Without bypass, amount guard must fire. err=$err");

        // With amount bypass: guard skipped (never -12; may be -5 for missing bank).
        $err2 = '';
        $res2 = stancerAddPaymentOnObject($invoice, $data, $err2, false, true);
        $this->assertNotEquals(-12, $res2, "With amount bypass, guard must NOT fire. err=$err2");
    }
}
