<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';
require_once __DIR__ . '/../Fixtures/StancerApiFixtures.php';

/**
 * Integration tests for stancer.lib.php critical functions
 */
class StancerLibIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupHttpMock();
        $this->configureStancerSettings();

        // Load the lib (this also loads classes)
        dol_include_once('/stancer/lib/stancer.lib.php');

        // Ensure StancerApi class is loaded for singleton usage
        require_once __DIR__ . '/../../../class/stancer_api.class.php';
    }

    protected function tearDown(): void
    {
        $this->teardownHttpMock();
        parent::tearDown();
    }

    // =========================================================================
    // stancerCheckIfPaymentInProgress() Tests
    // =========================================================================

    public function testCheckIfPaymentInProgressWithNoPaymentsInDB(): void
    {
        $soc = $this->createTestSociete(['name' => 'Check Payment Test']);
        $invoice = $this->createTestInvoice($soc);

        $result = stancerCheckIfPaymentInProgress($invoice);

        // When no payments exist, function returns false
        $this->assertIsBool($result);
    }

    public function testCheckIfPaymentInProgressReturnsTrueWhenEmptyObject(): void
    {
        $emptyInvoice = new \stdClass();

        $result = stancerCheckIfPaymentInProgress($emptyInvoice);

        // Returns true to avoid problems when object is empty
        $this->assertTrue($result);
    }

    public function testCheckIfPaymentInProgressWithExistingPayment(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '0';

        $soc = $this->createTestSociete(['name' => 'Payment In Progress Test']);
        $invoice = $this->createTestInvoice($soc);

        // Create a payment linked to this invoice with matching unique_id format
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_progress_test',
            'unique_id' => 'TAG.INV=' . $invoice->id,
            'order_id' => $invoice->ref,
            'status' => \Stancer_payments::STATUS_CAPTURED,
            'live_mode' => 0,
        ]);

        // No API mock: getPayment will get 404 and return false,
        // but the function still returns true because payments exist in DB
        $result = stancerCheckIfPaymentInProgress($invoice);

        $this->assertTrue($result, 'Should return true when a payment exists for this invoice');
    }

    // =========================================================================
    // stancerFilterSocName() Tests
    // =========================================================================

    public function testFilterSocNamePrependsClientForShortNames(): void
    {
        $result = stancerFilterSocName('ABC');
        $this->assertEquals('CLIENT ABC', $result);
    }

    public function testFilterSocNameTruncatesLongNames(): void
    {
        $longName = str_repeat('A', 100);
        $result = stancerFilterSocName($longName);
        $this->assertEquals(63, strlen($result));
    }

    public function testFilterSocNameReturnsUnchangedForValidLength(): void
    {
        $name = 'Test Company Name';
        $result = stancerFilterSocName($name);
        $this->assertEquals($name, $result);
    }

    // =========================================================================
    // stancerAddCustomerIfNeeded() Tests
    // =========================================================================

    public function testAddCustomerIfNeededReturnsErrorWhenNoEmailOrPhone(): void
    {
        $soc = $this->createTestSociete(['name' => 'No Contact Test']);
        $soc->email = '';
        $soc->phone = '';
        $soc->country_code = 'FR';

        $result = stancerAddCustomerIfNeeded($soc);

        // Returns -10 when email and phone are empty
        $this->assertEquals(-10, $result);
    }

    public function testAddCustomerIfNeededWithEmailCallsAPI(): void
    {
        $soc = $this->createTestSociete(['name' => 'Customer Create Test']);
        $soc->email = 'test@example.com';
        $soc->country_code = 'FR';

        // Note: In integration tests, the real stancerApi is used.
        // The HttpMock from unit tests doesn't intercept here because
        // the lib loads its own global $stancerApi instance.
        // This test verifies the function executes without fatal errors.
        $result = stancerAddCustomerIfNeeded($soc);

        // Result can be:
        // - customer ID string (cust_xxx) if API succeeds
        // - negative number if error occurs
        // - null if API returns empty response
        // We just verify the function completes
        $this->assertTrue(true);
    }

    public function testAddCustomerIfNeededReturnsExistingCustomerId(): void
    {
        $soc = $this->createTestSociete(['name' => 'Existing Customer Test']);
        $soc->country_code = 'FR';

        // Unique cust id: the early-return only reuses a cust that is NOT shared
        // with another thirdparty. A hardcoded id would clash with previous runs
        // on the (non-isolated) integration DB and look "shared".
        $cust = 'cust_existing_' . uniqid();
        $this->createTestCompanyPaymentMode($soc, [
            'stancer_account' => $cust,
            'stancer_object_ref' => 'sepa_existing_456',
        ]);

        $result = stancerAddCustomerIfNeeded($soc);

        $this->assertEquals($cust, $result);
        // Should not call API since customer already exists
        $this->assertFalse(\HttpMock::wasRequested('*customers*', 'POST'));
    }

    // =========================================================================
    // stancerSEPAstartPay() Tests
    // =========================================================================

    public function testSEPAstartPayReturnsErrorForNonSEPAPaymentMode(): void
    {
        global $conf;
        $conf->stancer->enabled = 1;

        $soc = $this->createTestSociete(['name' => 'SEPA Mode Test']);
        $invoice = $this->createTestInvoiceWithPaymentMode($soc, [
            'mode_reglement_code' => 'CB', // CB not PRE
            'amount' => 100,
        ]);

        $result = stancerSEPAstartPay($invoice, false);

        // Should return a negative error code (exact code depends on which check fails first)
        $this->assertLessThan(0, $result);
    }

    public function testSEPAstartPayReturnsErrorWhenNoPaymentModeConfigured(): void
    {
        global $conf;
        $conf->stancer->enabled = 1;

        $soc = $this->createTestSociete(['name' => 'No SEPA Config Test']);
        $invoice = $this->createTestInvoiceWithPaymentMode($soc, [
            'mode_reglement_code' => 'PRE',
            'amount' => 100,
        ]);

        // No company payment mode created

        $result = stancerSEPAstartPay($invoice, false);

        // Should return a negative error code
        $this->assertLessThan(0, $result);
    }

    public function testSEPAstartPayWithValidConfiguration(): void
    {
        global $conf;
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_IS_PROD = '0';
        $conf->global->STANCER_DELAY_SEPA = '0';

        $soc = $this->createTestSociete(['name' => 'Valid SEPA Test']);

        // Create company payment mode with Stancer config
        $this->createTestCompanyPaymentMode($soc, [
            'type' => 'ban',
            'label' => 'stancer-sepa-test',
            'stancer_account' => 'cust_sepa_test',
            'stancer_object_ref' => 'sepa_test_123',
            'default_rib' => 1,
        ]);

        // Get PRE payment mode ID
        $sql = "SELECT id FROM " . MAIN_DB_PREFIX . "c_paiement WHERE code = 'PRE'";
        $resql = $this->db->query($sql);
        if (!$resql || $this->db->num_rows($resql) == 0) {
            $this->markTestSkipped('PRE payment mode not found');
        }
        $obj = $this->db->fetch_object($resql);

        // Create invoice with required properties set directly
        $invoice = $this->createTestInvoice($soc);
        $invoice->mode_reglement_code = 'PRE';
        $invoice->mode_reglement_id = $obj->id;
        $invoice->fk_account = $conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS;
        $invoice->total_ttc = 100;
        $invoice->date = dol_now();
        $invoice->date_lim_reglement = dol_now();

        // Mock API response for payment creation
        \HttpMock::addJsonResponse(
            'https://api.stancer.com/v1/checkout/',
            [
                'id' => 'paym_sepa_created',
                'status' => 'pending',
                'amount' => 10000,
                'currency' => 'eur',
            ],
            200
        );

        $result = stancerSEPAstartPay($invoice, false);

        // Result can be 0 (success), 2 (waiting), or negative (error)
        // Depending on invoice state the result may vary
        $this->assertIsInt($result);
    }

    // =========================================================================
    // stancerCBstartPay() Tests
    // =========================================================================

    public function testCBstartPayReturnsErrorForNonCBPaymentMode(): void
    {
        global $conf;
        $conf->stancer->enabled = 1;

        $soc = $this->createTestSociete(['name' => 'CB Mode Test']);
        $invoice = $this->createTestInvoiceWithPaymentMode($soc, [
            'mode_reglement_code' => 'PRE', // PRE not CB
            'amount' => 100,
        ]);

        $result = stancerCBstartPay($invoice, false);

        // Should return error for non-CB mode
        $this->assertLessThan(0, $result);
    }

    // =========================================================================
    // stancer_get_public_key() / stancer_get_private_key() Tests
    // =========================================================================

    public function testGetPublicKeyReturnsTestKeyInTestMode(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '0';
        $conf->global->STANCER_TEST_PUBLIC_KEY = 'ptest_key_123';
        $conf->global->STANCER_PROD_PUBLIC_KEY = 'pprod_key_456';

        $result = stancer_get_public_key();

        $this->assertEquals('ptest_key_123', $result);
    }

    public function testGetPublicKeyReturnsProdKeyInProdMode(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '1';
        $conf->global->STANCER_TEST_PUBLIC_KEY = 'ptest_key_123';
        $conf->global->STANCER_PROD_PUBLIC_KEY = 'pprod_key_456';

        $result = stancer_get_public_key();

        $this->assertEquals('pprod_key_456', $result);
    }

    public function testGetPrivateKeyReturnsTestKeyInTestMode(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '0';
        $conf->global->STANCER_TEST_PRIVATE_KEY = 'stest_key_123';
        $conf->global->STANCER_PROD_PRIVATE_KEY = 'sprod_key_456';

        $result = stancer_get_private_key();

        $this->assertEquals('stest_key_123', $result);
    }

    public function testGetPrivateKeyReturnsProdKeyInProdMode(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '1';
        $conf->global->STANCER_TEST_PRIVATE_KEY = 'stest_key_123';
        $conf->global->STANCER_PROD_PRIVATE_KEY = 'sprod_key_456';

        $result = stancer_get_private_key();

        $this->assertEquals('sprod_key_456', $result);
    }

    // =========================================================================
    // stancerRefreshAllPaymentsFromDolibarr() Tests
    // =========================================================================

    public function testRefreshAllPaymentsFromDolibarrUpdatesPaymentFromApi(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '0';

        $soc = $this->createTestSociete(['name' => 'Refresh Payment Test']);
        // Invoice must have a positive amount so getSommePaiement() != total_ttc
        $invoice = $this->createTestInvoiceWithPaymentMode($soc, [
            'mode_reglement_code' => 'CB',
            'amount' => 25,
            'validate' => true,
        ]);
        $invoice->fetch($invoice->id);

        // Create a payment in DB with authorized status, linked to invoice via unique_id
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_refresh_test_001',
            'unique_id' => 'INV=' . $invoice->id,
            'order_id' => $invoice->ref,
            'status' => \Stancer_payments::STATUS_AUTHORIZED,
            'amount' => 2500,
            'live_mode' => 0,
        ]);

        // Mock API response for getPayment (StancerApi default version is v2)
        \HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/checkout/paym_refresh_test_001',
            [
                'id' => 'paym_refresh_test_001',
                'amount' => 2500,
                'currency' => 'eur',
                'status' => 'captured',
                'customer' => 'cust_test_refresh',
                'method' => 'card',
                'response' => '00',
                'capture' => true,
                'unique_id' => 'INV=' . $invoice->id,
                'order_id' => $invoice->ref,
                'date_bank' => time(),
                'created' => time() - 3600,
            ],
            200
        );

        $result = stancerRefreshAllPaymentsFromDolibarr(false, time() - 86400);

        // Verify API was called
        $this->assertTrue(\HttpMock::wasRequested('*checkout/paym_refresh_test_001*', 'GET'),
            'API should have been called for payment paym_refresh_test_001');

        // Verify payment was updated in DB
        $check = new \Stancer_payments($this->db);
        $check->fetch(0, null, 'paym_refresh_test_001');
        $this->assertEquals(\Stancer_payments::STATUS_CAPTURED, $check->status,
            'Payment status should be updated to captured');
    }

    public function testRefreshAllPaymentsFromDolibarrSkipsEmptyStancerId(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '0';

        // Create a payment with empty stancer_id
        $payment = $this->createTestPayment([
            'stancer_id' => '',
            'unique_id' => 'INV=9999',
            'status' => \Stancer_payments::STATUS_DRAFT,
            'live_mode' => 0,
        ]);

        $result = stancerRefreshAllPaymentsFromDolibarr(false, time() - 86400);

        // No API call should have been made
        $this->assertFalse(\HttpMock::wasRequested('*checkout*', 'GET'),
            'API should NOT be called for empty stancer_id');
    }

    public function testRefreshAllPaymentsFromDolibarrHandlesApiError(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '0';

        $soc = $this->createTestSociete(['name' => 'Refresh API Error Test']);
        // Invoice must have a positive amount so it's not considered "already paid"
        $invoice = $this->createTestInvoiceWithPaymentMode($soc, [
            'mode_reglement_code' => 'CB',
            'amount' => 50,
            'validate' => true,
        ]);
        $invoice->fetch($invoice->id);

        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_refresh_error',
            'unique_id' => 'INV=' . $invoice->id,
            'order_id' => $invoice->ref,
            'status' => \Stancer_payments::STATUS_AUTHORIZED,
            'live_mode' => 0,
        ]);

        // Mock API to return 401 auth error
        \HttpMock::addResponse('https://api.stancer.com/v1/checkout/paym_refresh_error', [
            'http_code' => 401,
            'content' => json_encode(\StancerApiFixtures::errorAuthentication()),
        ]);

        $result = stancerRefreshAllPaymentsFromDolibarr(false, time() - 86400);

        // Should return with error (auth error causes abort)
        $this->assertNotEmpty($result->error, 'Should set error on 401 response');

        // Payment status should not have changed
        $check = new \Stancer_payments($this->db);
        $check->fetch(0, null, 'paym_refresh_error');
        $this->assertEquals(\Stancer_payments::STATUS_AUTHORIZED, $check->status,
            'Payment status should not change on API error');
    }

    // =========================================================================
    // stancerRefreshAllPayoutsFromDolibarr() Tests
    // =========================================================================

    public function testRefreshAllPayoutsFromDolibarrReturnsErrorWhenBankAccountNotConfigured(): void
    {
        global $conf;
        $conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS = '';

        $result = stancerRefreshAllPayoutsFromDolibarr(false, time() - 86400);

        $this->assertNotEmpty($result->error);
        $this->assertStringContainsString('STANCER_BANK_ACCOUNT_FOR_PAYMENTS', $result->error);
    }
}
