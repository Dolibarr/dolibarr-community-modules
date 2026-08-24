<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Integration tests for audit finding C2: per-payment Stancer fees booked 100x
 * too small (double division by 100).
 *
 * Contract: $data['FinalFees'] is ALWAYS in cents (the integer returned by the
 * Stancer API). Both consumers divide once by 100 before booking the fee bank
 * line (stancer_bank.lib.php: solo path ~923, group path ~1080). The GROUP
 * producer (stancer_refresh.lib.php:227) passes raw cents and is correct; the
 * SOLO producers passed fee/100 (euros), so the consumer's /100 booked
 * fee/10000 -> a 14-cent fee ended up as -0.0014 EUR instead of -0.14 EUR.
 *
 * These tests drive the REAL code against a live Dolibarr SQLite instance and
 * assert the amount of the fee bank line.
 */
class StancerPaymentFeesIntegrationTest extends DolibarrRealTestCase
{
    /** @var int */
    private $bankAccountId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        global $conf;

        $this->setupHttpMock();

        // Bank module must look enabled: the group path guards on it.
        if (!isset($conf->banque) || !is_object($conf->banque)) {
            $conf->banque = new \stdClass();
        }
        $conf->banque->enabled = 1;

        require_once __DIR__ . '/../../../class/stancer_api.class.php';
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
        dol_include_once('/stancer/lib/stancer.lib.php');
        dol_include_once('/stancer/lib/stancer_bank.lib.php');
        dol_include_once('/stancer/lib/stancer_repair.lib.php');

        $this->bankAccountId = $this->createBankAccount();
        $this->configureStancerSettings([
            'STANCER_BANK_ACCOUNT_FOR_PAYMENTS' => (string) $this->bankAccountId,
            'STANCER_ADD_FEES'                  => 'PAYMENT',
        ]);

        // Reset the StancerApi singleton so the HTTP mock is honoured.
        $ref = new \ReflectionClass('StancerApi');
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function tearDown(): void
    {
        global $conf;

        // This test creates a real bank account and enables per-payment fees.
        // The harness resets neither $conf nor the core tables between tests, and
        // several other tests assume no usable bank account is configured, so undo
        // everything here to avoid cross-test pollution.
        if ($this->bankAccountId > 0) {
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "bank WHERE fk_account = " . (int) $this->bankAccountId);
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "bank_account WHERE rowid = " . (int) $this->bankAccountId);
            $this->bankAccountId = 0;
        }
        unset($conf->global->STANCER_ADD_FEES);
        $conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS = '1';

        $this->teardownHttpMock();
        parent::tearDown();
    }

    // =========================================================================
    // SOLO path (stancerForcePostPayment -> stancerAddPaymentOnObject): a 14-cent
    // API fee must be booked as -0.14 EUR. Before the C2 fix the solo producer
    // fed fee/100 (euros) and the consumer divided by 100 again, booking
    // -0.0014 EUR. Regression guard for the fixed (cents) contract.
    // =========================================================================
    public function testSoloPaymentFeeIsBookedInEuros(): void
    {
        $soc = $this->createTestSociete(['name' => 'FeeSolo']);
        $invoice = $this->buildValidatedInvoice($soc, 50.00);

        \HttpMock::addJsonResponse('*checkout*', [
            'status'   => 'captured',
            'order_id' => $invoice->ref,
            'amount'   => (int) round(((float) $invoice->total_ttc) * 100),
            'fee'      => 14,   // 14 cents = 0.14 EUR
            'created'  => 1,
            'method'   => 'card',
        ]);

        $api = new \StancerApi();
        $res = stancerForcePostPayment('paym_fee_' . uniqid(), $this->db, $this->testUser, $api);
        $this->assertTrue($res['success'], 'Force-post should succeed: ' . $res['message']);

        $fee = $this->fetchFeeAmount();
        $this->assertNotNull($fee, 'A Stancer fee bank line must be created when STANCER_ADD_FEES=PAYMENT');

        fwrite(STDERR, "\n[C2 FIXED] Stancer API fee (cents) : 14\n");
        fwrite(STDERR, "[C2 FIXED] booked fee bank line    : " . $fee . " EUR (expected -0.14)\n\n");

        // Regression guard: 14 cents must be booked as -0.14 EUR (single /100).
        $this->assertEqualsWithDelta(
            -0.14,
            $fee,
            0.001,
            'C2: the solo fee must be -0.14 EUR (14 cents), not hundredfold smaller'
        );
    }

    // =========================================================================
    // GROUP path (stancerAddPaymentOnInvoices): the producer already passes raw
    // cents, so 14 cents is correctly booked as -0.14 EUR. This guards against
    // the C2 fix accidentally changing the group unit. Must pass before AND after.
    // =========================================================================
    public function testGroupedPaymentFeeIsBookedInEuros(): void
    {
        $soc = $this->createTestSociete(['name' => 'FeeGroup']);
        $inv1 = $this->buildValidatedInvoice($soc, 30.00);
        $inv2 = $this->buildValidatedInvoice($soc, 20.00);
        $total = (float) $inv1->total_ttc + (float) $inv2->total_ttc;

        $data = array(
            'payment_id'      => 'paym_grp_' . uniqid(),
            'date'            => dol_print_date(dol_now(), '%Y-%m-%d'),
            'FinalPaymentAmt' => $total,
            'FinalFees'       => 14,   // cents (group contract, already correct)
            'paymentTypeId'   => (int) dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1),
            'ipaddress'       => 'test',
            'TRANSACTIONID'   => 'paym_grp',
            'service'         => 'stancer',
            'paymentmethod'   => 'card',
        );

        $errorMessage = '';
        $res = stancerAddPaymentOnInvoices([$inv1, $inv2], $data, $errorMessage);
        $this->assertSame(0, $res, 'Grouped payment should succeed: ' . $errorMessage);

        $fee = $this->fetchFeeAmount();
        $this->assertNotNull($fee, 'A fee bank line must be created for the grouped payment');
        $this->assertEqualsWithDelta(-0.14, $fee, 0.001, 'Grouped fee must be -0.14 EUR (14 cents)');
    }

    // -------------------------------------------------------------------------

    /**
     * Create a real current bank account and return its rowid.
     */
    private function createBankAccount(): int
    {
        require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
        global $mysoc;

        $acc = new \Account($this->db);
        $acc->ref           = 'STCTEST' . uniqid();
        $acc->label         = 'Stancer Test Bank';
        $acc->country_id    = (int) (!empty($mysoc->country_id) ? $mysoc->country_id : 1);
        $acc->date_solde    = dol_now();
        $acc->solde         = 0;
        $acc->currency_code = 'EUR';
        $acc->type          = 1; // Account::TYPE_CURRENT
        $acc->courant       = 1;
        $acc->clos          = 0;
        $id = $acc->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'createBankAccount failed: ' . $acc->error);
        return (int) $id;
    }

    /**
     * The (single) negative bank line on the test account is the Stancer fee
     * line (the customer payment itself is a positive credit).
     */
    private function fetchFeeAmount(): ?float
    {
        $sql = "SELECT amount FROM " . MAIN_DB_PREFIX . "bank"
            . " WHERE fk_account = " . (int) $this->bankAccountId . " AND amount < 0"
            . " ORDER BY rowid DESC";
        $res = $this->db->query($sql);
        $this->assertNotFalse($res, 'fetchFeeAmount query failed: ' . $this->db->lasterror());
        $obj = $this->db->fetch_object($res);
        return $obj ? (float) $obj->amount : null;
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
}
