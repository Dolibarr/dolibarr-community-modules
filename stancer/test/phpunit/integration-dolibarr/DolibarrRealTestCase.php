<?php

namespace Stancer\Tests\IntegrationDolibarr;

use PHPUnit\Framework\TestCase;

/**
 * Base class for integration tests with real Dolibarr instance
 */
abstract class DolibarrRealTestCase extends TestCase
{
    protected $db;
    protected $testUser;
    protected $conf;

    protected function setUp(): void
    {
        global $db, $conf, $user;

        $this->db = $db;
        $this->conf = $conf;
        $this->testUser = $user;

        // Ensure user has rights needed for invoice operations
        if (!isset($user->rights->facture)) {
            $user->rights->facture = new \stdClass();
        }
        $user->rights->facture->creer = 1;
        $user->rights->facture->valider = 1;

        // Ensure facture module config exists (needed for validate)
        if (!isset($conf->facture) || !is_object($conf->facture)) {
            $conf->facture = new \stdClass();
        }
        if (!isset($conf->facture->dir_output)) {
            $conf->facture->dir_output = DOL_DATA_ROOT . '/facture';
        }

        // Clean module tables between each test
        $this->cleanModuleTables();
    }

    /**
     * Clean module-specific tables
     */
    protected function cleanModuleTables(): void
    {
        $tables = [
            'stancer_stancer_payments',
            'stancer_stancer_payouts',
            'stancer_stancer_refunds',
        ];

        foreach ($tables as $table) {
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . $table);
        }
    }

    /**
     * Create a test Societe (company/thirdparty)
     */
    protected function createTestSociete(array $data = []): \Societe
    {
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

        $soc = new \Societe($this->db);
        $soc->name = $data['name'] ?? 'Test Company ' . uniqid();
        $soc->client = $data['client'] ?? 1;
        $soc->fournisseur = $data['fournisseur'] ?? 0;
        $soc->create($this->testUser);

        return $soc;
    }

    /**
     * Create a test invoice
     */
    protected function createTestInvoice(\Societe $soc, array $data = []): \Facture
    {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

        $invoice = new \Facture($this->db);
        $invoice->socid = $soc->id;
        $invoice->date = $data['date'] ?? dol_now();
        $invoice->create($this->testUser);

        return $invoice;
    }

    /**
     * Assert that a record exists in the database
     */
    protected function assertDatabaseHas(string $table, array $conditions): void
    {
        $where = [];
        foreach ($conditions as $column => $value) {
            $where[] = "$column = '" . $this->db->escape($value) . "'";
        }

        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . $table;
        $sql .= " WHERE " . implode(' AND ', $where);

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertGreaterThan(
            0,
            (int)$obj->cnt,
            "Table '$table' should contain: " . json_encode($conditions)
        );
    }

    /**
     * Assert that no record exists in the database with given conditions
     */
    protected function assertDatabaseMissing(string $table, array $conditions): void
    {
        $where = [];
        foreach ($conditions as $column => $value) {
            $where[] = "$column = '" . $this->db->escape($value) . "'";
        }

        $sql = "SELECT COUNT(*) as cnt FROM " . MAIN_DB_PREFIX . $table;
        $sql .= " WHERE " . implode(' AND ', $where);

        $result = $this->db->query($sql);
        $obj = $this->db->fetch_object($result);

        $this->assertEquals(
            0,
            (int)$obj->cnt,
            "Table '$table' should NOT contain: " . json_encode($conditions)
        );
    }

    /**
     * Create a test Stancer payment
     */
    protected function createTestPayment(array $data = []): \Stancer_payments
    {
        require_once __DIR__ . '/../../../class/stancer_payments.class.php';

        $payment = new \Stancer_payments($this->db);
        $payment->stancer_id = $data['stancer_id'] ?? 'paym_test_' . uniqid();
        $payment->amount = $data['amount'] ?? 2500;
        $payment->currency = $data['currency'] ?? 'eur';
        $payment->status = $data['status'] ?? \Stancer_payments::STATUS_DRAFT;
        $payment->method = $data['method'] ?? 'card';
        $payment->description = $data['description'] ?? 'Test payment';
        $payment->fk_facture = $data['fk_facture'] ?? 0;
        $payment->fk_soc = $data['fk_soc'] ?? 0;
        $payment->live_mode = $data['live_mode'] ?? 0;
        $payment->unique_id = $data['unique_id'] ?? 'TEST_' . uniqid();
        $payment->order_id = $data['order_id'] ?? '';

        $result = $payment->create($this->testUser);
        if ($result < 0) {
            $this->fail('Failed to create test payment: ' . $payment->error);
        }

        return $payment;
    }

    /**
     * Create a test Stancer payout
     */
    protected function createTestPayout(array $data = []): \Stancer_payouts
    {
        require_once __DIR__ . '/../../../class/stancer_payouts.class.php';

        $payout = new \Stancer_payouts($this->db);
        $payout->payout_id = $data['payout_id'] ?? 'pout_test_' . uniqid();
        $payout->amount = $data['amount'] ?? 100000;
        $payout->currency = $data['currency'] ?? 'eur';
        $payout->status = $data['status'] ?? \Stancer_payouts::STATUS_DRAFT;
        $payout->fees = $data['fees'] ?? 0;
        $payout->live_mode = $data['live_mode'] ?? 0;

        $result = $payout->create($this->testUser);
        if ($result < 0) {
            $this->fail('Failed to create test payout: ' . $payout->error);
        }

        return $payout;
    }

    /**
     * Create a test Stancer refund
     */
    protected function createTestRefund(array $data = []): \Stancer_refunds
    {
        require_once __DIR__ . '/../../../class/stancer_refunds.class.php';

        $refund = new \Stancer_refunds($this->db);
        $refund->refund_id = $data['refund_id'] ?? 'rfnd_test_' . uniqid();
        $refund->payment_id = $data['payment_id'] ?? 'paym_test_' . uniqid();
        $refund->amount = $data['amount'] ?? 1000;
        $refund->currency = $data['currency'] ?? 'eur';
        $refund->status = $data['status'] ?? \Stancer_refunds::STATUS_DRAFT;
        $refund->fk_soc = $data['fk_soc'] ?? 0;
        $refund->live_mode = $data['live_mode'] ?? 0;

        $result = $refund->create($this->testUser);
        if ($result < 0) {
            $errors = implode(', ', $refund->errors);
            $this->fail('Failed to create test refund: ' . $refund->error . ' | ' . $errors);
        }

        return $refund;
    }

    /**
     * Create a test company payment mode (societe_rib) with Stancer configuration
     */
    protected function createTestCompanyPaymentMode(\Societe $soc, array $data = []): int
    {
        $type = $data['type'] ?? 'ban'; // ban = SEPA, card = CB

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "societe_rib (";
        $sql .= "fk_soc, label, type, iban_prefix, bic, rum, ";
        $sql .= "stancer_account, stancer_object_ref, default_rib, status, datec";
        $sql .= ") VALUES (";
        $sql .= (int)$soc->id . ", ";
        $sql .= "'" . $this->db->escape($data['label'] ?? 'stancer-sepa-test') . "', ";
        $sql .= "'" . $this->db->escape($type) . "', ";
        $sql .= "'" . $this->db->escape($data['iban'] ?? 'FR7630001007941234567890185') . "', ";
        $sql .= "'" . $this->db->escape($data['bic'] ?? 'BNPAFRPP') . "', ";
        $sql .= "'" . $this->db->escape($data['rum'] ?? 'RUM' . uniqid()) . "', ";
        $sql .= "'" . $this->db->escape($data['stancer_account'] ?? 'cust_test_' . uniqid()) . "', ";
        $sql .= "'" . $this->db->escape($data['stancer_object_ref'] ?? 'sepa_test_' . uniqid()) . "', ";
        $sql .= (int)($data['default_rib'] ?? 1) . ", ";
        $sql .= "1, ";
        $sql .= "'" . date('Y-m-d H:i:s') . "'";
        $sql .= ")";

        $result = $this->db->query($sql);
        if (!$result) {
            $this->fail('Failed to create company payment mode: ' . $this->db->lasterror());
        }

        return $this->db->last_insert_id(MAIN_DB_PREFIX . "societe_rib");
    }

    /**
     * Create a validated test invoice with payment mode
     */
    protected function createTestInvoiceWithPaymentMode(\Societe $soc, array $data = []): \Facture
    {
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

        $invoice = new \Facture($this->db);
        $invoice->socid = $soc->id;
        $invoice->date = $data['date'] ?? dol_now();
        $invoice->date_lim_reglement = $data['date_lim_reglement'] ?? dol_now();
        $invoice->mode_reglement_code = $data['mode_reglement_code'] ?? 'PRE';
        $invoice->mode_reglement_id = $data['mode_reglement_id'] ?? 0;
        $invoice->fk_account = $data['fk_account'] ?? 0;
        $invoice->create($this->testUser);

        // Add a line to have an amount
        if (!empty($data['amount'])) {
            $invoice->addline(
                'Test product',
                $data['amount'],
                1,
                0, // VAT
                0,
                0,
                0,
                0,
                '',
                '',
                0,
                0,
                '',
                'HT'
            );
            $invoice->fetch($invoice->id);
        }

        // Validate if requested
        if (!empty($data['validate'])) {
            $invoice->validate($this->testUser);
        }

        return $invoice;
    }

    /**
     * Setup HTTP mock for API tests
     */
    protected function setupHttpMock(): void
    {
        require_once __DIR__ . '/../Mocks/HttpMock.php';
        \HttpMock::reset();
    }

    /**
     * Teardown HTTP mock
     */
    protected function teardownHttpMock(): void
    {
        if (class_exists('HttpMock')) {
            \HttpMock::disable();
        }
    }

    /**
     * Configure Stancer module settings for tests
     */
    protected function configureStancerSettings(array $settings = []): void
    {
        global $conf;

        // Default test settings
        $defaults = [
            'STANCER_IS_PROD' => '0',
            'STANCER_TEST_PUBLIC_KEY' => 'ptest_xxx',
            'STANCER_TEST_PRIVATE_KEY' => 'stest_xxx',
            'STANCER_BANK_ACCOUNT_FOR_PAYMENTS' => '1',
            'STANCER_ENABLE_SEPA' => '1',
            'STANCER_ENABLE_CB' => '1',
            'STANCER_DELAY_SEPA' => '0',
            'MAIN_USE_ADVANCED_PERMS' => '0',
        ];

        $allSettings = array_merge($defaults, $settings);

        foreach ($allSettings as $key => $value) {
            $conf->global->$key = $value;
        }
    }
}
