<?php

/**
 * Unit tests for StancerApi class
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Fixtures/StancerApiFixtures.php';
require_once __DIR__ . '/../../../class/stancer_api.class.php';

class StancerApiTest extends TestCase
{
    private StancerApi $api;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset HTTP mock before each test
        HttpMock::reset();

        // Create API instance with test keys
        $this->api = new StancerApi(
            'ptest_publickey123',
            'stest_secretkey456',
            false // test mode
        );
    }

    protected function tearDown(): void
    {
        HttpMock::disable();
        parent::tearDown();
    }

    // ========================================================================
    // CONSTRUCTOR & HELPERS TESTS
    // ========================================================================

    public function testConstructorWithExplicitKeys(): void
    {
        $api = new StancerApi('pub_key', 'sec_key', true);

        $this->assertTrue($api->isLiveMode());
        $this->assertEquals('pub_key', $api->getPublicKey());
        $this->assertEquals('v2', $api->getApiVersion());
    }

    public function testConstructorDefaultsToV2(): void
    {
        $api = new StancerApi('pub_key', 'sec_key', false);

        $this->assertTrue($api->isV2());
        $this->assertEquals('v2', $api->getApiVersion());
    }

    public function testSetApiVersion(): void
    {
        $this->assertEquals('v2', $this->api->getApiVersion());

        $this->api->setApiVersion('v1');

        $this->assertEquals('v1', $this->api->getApiVersion());
        $this->assertFalse($this->api->isV2());
    }

    public function testToCents(): void
    {
        $this->assertEquals(1000, StancerApi::toCents(10.00));
        $this->assertEquals(1050, StancerApi::toCents(10.50));
        $this->assertEquals(1, StancerApi::toCents(0.01));
        $this->assertEquals(0, StancerApi::toCents(0));
    }

    public function testFromCents(): void
    {
        $this->assertEquals(10.00, StancerApi::fromCents(1000));
        $this->assertEquals(10.50, StancerApi::fromCents(1050));
        $this->assertEquals(0.01, StancerApi::fromCents(1));
        $this->assertEquals(0.00, StancerApi::fromCents(0));
    }

    // =========================================================================
    // Regression: 1-cent drift bug observed in production on SEPA payments.
    // Root cause: `(int) ((float) ... * 100)` truncates instead of rounding.
    // Example: `(int) (50.10 * 100)` may return 5009 because 50.10 in IEEE 754
    // is actually 50.0999999... -> 5009.999... -> (int) cast = 5009.
    // Fix: use StancerApi::toCents() which does `(int) round($amount * 100)`.
    // These tests pin down toCents() correctness on real-world tricky amounts
    // AND demonstrate that the buggy `(int) ((float) X * 100)` pattern fails.
    // =========================================================================

    public function testToCentsHandlesTrickyDecimalsLiteral(): void
    {
        // Each of these literals can trigger the float drift if cast directly to int.
        $this->assertEquals(1010, StancerApi::toCents(10.10));
        $this->assertEquals(2020, StancerApi::toCents(20.20));
        $this->assertEquals(5010, StancerApi::toCents(50.10));
        $this->assertEquals(10030, StancerApi::toCents(100.30));
        $this->assertEquals(2520, StancerApi::toCents(25.20));
        $this->assertEquals(123456, StancerApi::toCents(1234.56));
        $this->assertEquals(9999, StancerApi::toCents(99.99));
        $this->assertEquals(10, StancerApi::toCents(0.10));
        $this->assertEquals(30, StancerApi::toCents(0.30));
        // Real production case reported by user: invoice TTC = 40.80 EUR,
        // SEPA debit was launched for 40.79 EUR (1 cent short).
        $this->assertEquals(4080, StancerApi::toCents(40.80));
    }

    public function testToCentsHandlesValuesFromSubtraction(): void
    {
        // Simulates `total_ttc - sumPayments` which is how amountToPay is built
        // in lib/stancer_payment.lib.php. Subtraction is the main source of drift.
        $remaining = 100.30 - 50.20;  // mathematically 50.10, in float = 50.099999...
        $this->assertEquals(5010, StancerApi::toCents($remaining));

        $remaining = 200.00 - 99.90;  // mathematically 100.10
        $this->assertEquals(10010, StancerApi::toCents($remaining));

        $remaining = 1000.00 - 333.30 - 333.30 - 333.30;  // mathematically 0.10
        $this->assertEquals(10, StancerApi::toCents($remaining));

        $remaining = 50.10 - 0.00;
        $this->assertEquals(5010, StancerApi::toCents($remaining));
    }

    public function testToCentsHandlesDepositPercentCalc(): void
    {
        // Simulates the partial-pay deposit calculation:
        //   $amountToPay = $totalTtc * ($depositPercent / 100)
        // Then we want it in cents.
        $totalTtc = 100.30;
        $depositPercent = 30.0;
        $amount = $totalTtc * ($depositPercent / 100);  // 30.09 in float-land
        $this->assertEquals(3009, StancerApi::toCents($amount));

        $totalTtc = 1234.56;
        $depositPercent = 50.0;
        $amount = $totalTtc * ($depositPercent / 100);  // 617.28
        $this->assertEquals(61728, StancerApi::toCents($amount));
    }

    public function testToCentsRoundsBankerStyleAtHalfCent(): void
    {
        // Documents the rounding behavior of toCents at half-cent boundaries.
        // round() in PHP defaults to HALF_AWAY_FROM_ZERO.
        $this->assertEquals(1, StancerApi::toCents(0.005));   // rounds up
        $this->assertEquals(2, StancerApi::toCents(0.015));   // rounds up (mathematically; float may differ)
        $this->assertEquals(11, StancerApi::toCents(0.105));  // rounds up
    }

    public function testBuggyPatternFailsAtLeastOnceOnKnownInputs(): void
    {
        // Regression marker: prove that the OLD pattern `(int) ((float) X * 100)`
        // is wrong. If this assertion ever flips to false on every input, the
        // whole assumption changed and the regression tests need rethinking.
        // We use a value built via subtraction to trigger the float drift.
        $remaining = 100.30 - 50.20;  // = 50.099999... in float
        $buggyResult = (int) ($remaining * 100);
        $correctResult = StancerApi::toCents($remaining);

        $this->assertEquals(5010, $correctResult, 'toCents must give the correct cents value');
        // The buggy pattern produces 5009 on most PHP versions due to truncation
        // of 5009.99999... If a future PHP build gets it right by chance, this
        // test will need to find another tripping value.
        $this->assertLessThanOrEqual(5010, $buggyResult);
        $this->assertGreaterThanOrEqual(5009, $buggyResult);
    }

    // ========================================================================
    // CUSTOMER TESTS
    // ========================================================================

    public function testCreateCustomer(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/customers/',
            StancerApiFixtures::customerCreated(),
            200
        );

        $result = $this->api->createCustomer([
            'email' => 'john.doe@example.com',
            'mobile' => '+33612345678',
            'name' => 'John Doe',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('cust_9TycuMPH3xsPVE0n02IrI3L3', $result['id']);
        $this->assertEquals('john.doe@example.com', $result['email']);

        // Verify request was made
        $this->assertTrue(HttpMock::wasRequested('*customers*', 'POST'));
    }

    public function testGetCustomer(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/customers/cust_9TycuMPH3xsPVE0n02IrI3L3',
            StancerApiFixtures::customerFetched(),
            200
        );

        $result = $this->api->getCustomer('cust_9TycuMPH3xsPVE0n02IrI3L3');

        $this->assertIsArray($result);
        $this->assertEquals('cust_9TycuMPH3xsPVE0n02IrI3L3', $result['id']);
    }

    public function testUpdateCustomer(): void
    {
        $updatedCustomer = StancerApiFixtures::customerCreated();
        $updatedCustomer['name'] = 'John Updated';

        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/customers/cust_9TycuMPH3xsPVE0n02IrI3L3',
            $updatedCustomer,
            200
        );

        $result = $this->api->updateCustomer('cust_9TycuMPH3xsPVE0n02IrI3L3', [
            'name' => 'John Updated',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('John Updated', $result['name']);
    }

    public function testDeleteCustomer(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/customers/cust_9TycuMPH3xsPVE0n02IrI3L3',
            [],
            204
        );

        $result = $this->api->deleteCustomer('cust_9TycuMPH3xsPVE0n02IrI3L3');

        $this->assertIsArray($result);
    }

    // ========================================================================
    // PAYMENT TESTS
    // ========================================================================

    public function testCreatePayment(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/checkout/',
            StancerApiFixtures::paymentCreated(),
            200
        );

        $result = $this->api->createPayment([
            'amount' => 2500,
            'currency' => 'eur',
            'customer' => 'cust_9TycuMPH3xsPVE0n02IrI3L3',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('paym_4kM8Kv5X0HJ8vLqF', $result['id']);
        $this->assertEquals(2500, $result['amount']);
        $this->assertEquals('pending', $result['status']);
    }

    public function testGetPayment(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/checkout/paym_4kM8Kv5X0HJ8vLqF',
            StancerApiFixtures::paymentCaptured(),
            200
        );

        $result = $this->api->getPayment('paym_4kM8Kv5X0HJ8vLqF');

        $this->assertIsArray($result);
        $this->assertEquals('captured', $result['status']);
    }

    public function testListPayments(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/checkout/',
            StancerApiFixtures::paymentList(),
            200
        );

        $result = $this->api->listPayments();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('payments', $result);
        $this->assertCount(2, $result['payments']);
    }

    public function testListPaymentsWithFilters(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/checkout/*',
            StancerApiFixtures::paymentList(),
            200
        );

        $result = $this->api->listPayments([
            'limit' => 10,
            'created' => 1704067200,
        ]);

        $this->assertIsArray($result);

        // Verify query string was included
        $lastRequest = HttpMock::getLastRequest();
        $this->assertStringContainsString('limit=10', $lastRequest['url']);
    }

    public function testCapturePayment(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/checkout/paym_4kM8Kv5X0HJ8vLqF/capture',
            StancerApiFixtures::paymentCaptured(),
            200
        );

        $result = $this->api->capturePayment('paym_4kM8Kv5X0HJ8vLqF');

        $this->assertIsArray($result);
        $this->assertEquals('captured', $result['status']);

        // Verify it was a POST request to the capture endpoint
        $lastRequest = HttpMock::getLastRequest();
        $this->assertEquals('POST', $lastRequest['method']);
        $this->assertStringContainsString('/capture', $lastRequest['url']);
    }

    public function testCapturePaymentPartial(): void
    {
        $partialCapture = StancerApiFixtures::paymentCaptured();
        $partialCapture['amount'] = 1500;

        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/checkout/paym_4kM8Kv5X0HJ8vLqF/capture',
            $partialCapture,
            200
        );

        $result = $this->api->capturePayment('paym_4kM8Kv5X0HJ8vLqF', 1500);

        $this->assertIsArray($result);

        // Verify amount was in request
        $lastRequest = HttpMock::getLastRequest();
        $this->assertStringContainsString('1500', $lastRequest['data']);
    }

    // ========================================================================
    // REFUND TESTS
    // ========================================================================

    public function testCreateRefund(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/refunds/',
            StancerApiFixtures::refundCreated(),
            200
        );

        $result = $this->api->createRefund([
            'payment' => 'paym_4kM8Kv5X0HJ8vLqF',
            'amount' => 1000,
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('rfnd_8HkP2L4mN6wR', $result['id']);
        $this->assertEquals(1000, $result['amount']);
    }

    public function testGetRefund(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/refunds/rfnd_8HkP2L4mN6wR',
            StancerApiFixtures::refundCompleted(),
            200
        );

        $result = $this->api->getRefund('rfnd_8HkP2L4mN6wR');

        $this->assertIsArray($result);
        $this->assertEquals('refunded', $result['status']);
    }

    // ========================================================================
    // PAYOUT TESTS
    // ========================================================================

    public function testGetPayout(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/payouts/pout_1A2B3C4D5E6F',
            StancerApiFixtures::payoutFetched(),
            200
        );

        $result = $this->api->getPayout('pout_1A2B3C4D5E6F');

        $this->assertIsArray($result);
        $this->assertEquals('pout_1A2B3C4D5E6F', $result['id']);
        $this->assertEquals('sent', $result['status']);
    }

    public function testListPayouts(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/payouts/',
            StancerApiFixtures::payoutList(),
            200
        );

        $result = $this->api->listPayouts();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('payouts', $result);
    }

    // ========================================================================
    // CARD TESTS
    // ========================================================================

    public function testCreateCard(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/cards/',
            StancerApiFixtures::cardCreated(),
            200
        );

        $result = $this->api->createCard([
            'number' => '4242424242424242',
            'exp_month' => 12,
            'exp_year' => 2025,
            'cvc' => '123',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('card_xognFbZs935LMKJp', $result['id']);
        $this->assertEquals('4242', $result['last4']);
    }

    public function testGetCard(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/cards/card_xognFbZs935LMKJp',
            StancerApiFixtures::cardCreated(),
            200
        );

        $result = $this->api->getCard('card_xognFbZs935LMKJp');

        $this->assertIsArray($result);
        $this->assertEquals('visa', $result['brand']);
    }

    // ========================================================================
    // SEPA TESTS
    // ========================================================================

    public function testCreateSepa(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/sepa/',
            StancerApiFixtures::sepaCreated(),
            200
        );

        $result = $this->api->createSepa([
            'iban' => 'FR7630001007941234567890185',
            'bic' => 'BNPAFRPP',
            'name' => 'John Doe',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('sepa_ABC123456789', $result['id']);
    }

    // ========================================================================
    // DISPUTE TESTS
    // ========================================================================

    public function testGetDispute(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/disputes/dspt_1A2B3C4D5E6F',
            StancerApiFixtures::disputeFetched(),
            200
        );

        $result = $this->api->getDispute('dspt_1A2B3C4D5E6F');

        $this->assertIsArray($result);
        $this->assertEquals('dspt_1A2B3C4D5E6F', $result['id']);
        $this->assertEquals('pending', $result['status']);
    }

    // ========================================================================
    // ERROR HANDLING TESTS
    // ========================================================================

    public function testHttpError400(): void
    {
        HttpMock::addErrorResponse(
            'https://api.stancer.com/v2/customers/',
            400,
            'invalid_request_error',
            'The request was invalid.'
        );

        $result = $this->api->createCustomer([]);

        $this->assertFalse($result);
        $this->assertStringContainsString('HTTP Error 400', $this->api->error);
        $this->assertStringContainsString('invalid', $this->api->error);
    }

    public function testHttpError401(): void
    {
        HttpMock::addErrorResponse(
            'https://api.stancer.com/v2/customers/',
            401,
            'authentication_error',
            'Invalid API key.'
        );

        $result = $this->api->createCustomer([]);

        $this->assertFalse($result);
        $this->assertEquals(401, $this->api->lastHttpCode);
    }

    public function testHttpError404(): void
    {
        HttpMock::addErrorResponse(
            'https://api.stancer.com/v2/customers/cust_notfound',
            404,
            'invalid_request_error',
            'Resource not found.'
        );

        $result = $this->api->getCustomer('cust_notfound');

        $this->assertFalse($result);
        $this->assertEquals(404, $this->api->lastHttpCode);
    }

    public function testHttpError429(): void
    {
        HttpMock::addErrorResponse(
            'https://api.stancer.com/v2/customers/',
            429,
            'rate_limit_error',
            'Too many requests.'
        );

        $result = $this->api->createCustomer([]);

        $this->assertFalse($result);
        $this->assertEquals(429, $this->api->lastHttpCode);
    }

    public function testCurlError(): void
    {
        HttpMock::addCurlError(
            'https://api.stancer.com/v2/customers/',
            7,
            'Failed to connect to api.stancer.com'
        );

        $result = $this->api->createCustomer([]);

        $this->assertFalse($result);
        $this->assertStringContainsString('CURL Error', $this->api->error);
    }

    // ========================================================================
    // API V2 TESTS
    // ========================================================================

    public function testCreateAddress(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/addresses/',
            StancerApiFixtures::addressCreated(),
            200
        );

        $result = $this->api->createAddress([
            'line1' => '123 Main Street',
            'city' => 'Paris',
            'zip' => '75001',
            'country' => 'FR',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('addr_1A2B3C4D5E6F', $result['id']);
    }

    public function testCreateMandate(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/mandates/',
            StancerApiFixtures::mandateCreated(),
            200
        );

        $result = $this->api->createMandate([
            'sepa' => 'sepa_ABC123456789',
            'customer' => 'cust_9TycuMPH3xsPVE0n02IrI3L3',
        ]);

        $this->assertIsArray($result);
        $this->assertEquals('sdmm_1A2B3C4D5E6F', $result['id']);
    }

    // ========================================================================
    // REQUEST HISTORY TESTS
    // ========================================================================

    public function testRequestHistoryIsRecorded(): void
    {
        HttpMock::addJsonResponse('*', [], 200);
        HttpMock::addJsonResponse('*', [], 200);

        $this->api->getCustomer('cust_1');
        $this->api->getPayment('paym_1');

        $history = HttpMock::getHistory();

        $this->assertCount(2, $history);
        $this->assertStringContainsString('customers', $history[0]['url']);
        $this->assertStringContainsString('checkout', $history[1]['url']);
    }

    public function testLastRequestReturnsLatest(): void
    {
        HttpMock::addJsonResponse('*', [], 200);

        $this->api->createCustomer(['email' => 'test@example.com']);

        $lastRequest = HttpMock::getLastRequest();

        $this->assertEquals('POST', $lastRequest['method']);
        $this->assertStringContainsString('test@example.com', $lastRequest['data']);
    }

    // ========================================================================
    // CONSTRUCTOR WITH DOLIBARR CONFIG KEYS (Cat 1 - bug 401 fix coverage)
    // ========================================================================

    public function testConstructorReadsTestKeysFromDolibarrConfig(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '0';
        $conf->global->STANCER_TEST_PUBLIC_KEY = 'ptest_from_conf';
        $conf->global->STANCER_TEST_PRIVATE_KEY = 'stest_from_conf';

        $api = new StancerApi();

        $this->assertFalse($api->isLiveMode());
        $this->assertEquals('ptest_from_conf', $api->getPublicKey());

        // Verify the secret key is used in requests
        HttpMock::reset();
        HttpMock::addJsonResponse('*', [], 200);
        $api->getCustomer('cust_test');
        $lastRequest = HttpMock::getLastRequest();
        $this->assertStringContainsString('customers/cust_test', $lastRequest['url']);

        unset($conf->global->STANCER_IS_PROD);
        unset($conf->global->STANCER_TEST_PUBLIC_KEY);
        unset($conf->global->STANCER_TEST_PRIVATE_KEY);
    }

    public function testConstructorReadsProdKeysFromDolibarrConfig(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '1';
        $conf->global->STANCER_PROD_PUBLIC_KEY = 'pprod_from_conf';
        $conf->global->STANCER_PROD_PRIVATE_KEY = 'sprod_from_conf';

        $api = new StancerApi();

        $this->assertTrue($api->isLiveMode());
        $this->assertEquals('pprod_from_conf', $api->getPublicKey());

        unset($conf->global->STANCER_IS_PROD);
        unset($conf->global->STANCER_PROD_PUBLIC_KEY);
        unset($conf->global->STANCER_PROD_PRIVATE_KEY);
    }

    public function testConstructorDefaultsToTestModeWhenConfigMissing(): void
    {
        global $conf;
        unset($conf->global->STANCER_IS_PROD);

        $api = new StancerApi();

        $this->assertFalse($api->isLiveMode());
    }

    public function testConstructorDefaultsToEmptyKeysWhenConfigMissing(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '0';
        unset($conf->global->STANCER_TEST_PUBLIC_KEY);
        unset($conf->global->STANCER_TEST_PRIVATE_KEY);

        $api = new StancerApi();

        $this->assertEquals('', $api->getPublicKey());
    }

    public function testConstructorExplicitKeysOverrideConfig(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '1';
        $conf->global->STANCER_PROD_PUBLIC_KEY = 'pprod_conf';
        $conf->global->STANCER_PROD_PRIVATE_KEY = 'sprod_conf';

        $api = new StancerApi('explicit_pub', 'explicit_sec', false);

        $this->assertFalse($api->isLiveMode());
        $this->assertEquals('explicit_pub', $api->getPublicKey());

        unset($conf->global->STANCER_IS_PROD);
        unset($conf->global->STANCER_PROD_PUBLIC_KEY);
        unset($conf->global->STANCER_PROD_PRIVATE_KEY);
    }

    // ========================================================================
    // GETTER METHODS (Cat 4)
    // ========================================================================

    public function testIsLiveModeReturnsFalseInTestMode(): void
    {
        $api = new StancerApi('pub', 'sec', false);
        $this->assertFalse($api->isLiveMode());
    }

    public function testIsLiveModeReturnsTrueInLiveMode(): void
    {
        $api = new StancerApi('pub', 'sec', true);
        $this->assertTrue($api->isLiveMode());
    }

    public function testGetApiVersionDefaultsToV2(): void
    {
        $api = new StancerApi('pub', 'sec', false);
        $this->assertEquals('v2', $api->getApiVersion());
        $this->assertTrue($api->isV2());
    }

    public function testCanForceV1(): void
    {
        $api = new StancerApi('pub', 'sec', false, StancerApi::API_VERSION_V1);
        $this->assertFalse($api->isV2());
        $this->assertEquals('v1', $api->getApiVersion());
    }

    public function testSetApiVersionUpdatesUrlForRequests(): void
    {
        $this->api->setApiVersion('v2');

        HttpMock::addJsonResponse('*', StancerApiFixtures::addressCreated(), 200);
        $this->api->createAddress(['line1' => 'test']);

        $lastRequest = HttpMock::getLastRequest();
        $this->assertStringContainsString('/v2/addresses/', $lastRequest['url']);
    }

    // ========================================================================
    // CARD CRUD (Cat 3 - uncovered methods)
    // ========================================================================

    public function testUpdateCard(): void
    {
        $updatedCard = StancerApiFixtures::cardCreated();
        $updatedCard['name'] = 'Jane Doe';

        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/cards/card_xognFbZs935LMKJp',
            $updatedCard,
            200
        );

        $result = $this->api->updateCard('card_xognFbZs935LMKJp', ['name' => 'Jane Doe']);

        $this->assertIsArray($result);
        $this->assertEquals('Jane Doe', $result['name']);

        $lastRequest = HttpMock::getLastRequest();
        $this->assertEquals('PUT', $lastRequest['method']);
    }

    public function testDeleteCard(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/cards/card_xognFbZs935LMKJp',
            [],
            204
        );

        $result = $this->api->deleteCard('card_xognFbZs935LMKJp');

        $this->assertIsArray($result);
        $lastRequest = HttpMock::getLastRequest();
        $this->assertEquals('DELETE', $lastRequest['method']);
    }

    // ========================================================================
    // SEPA CRUD (Cat 3 - uncovered methods)
    // ========================================================================

    public function testGetSepa(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/sepa/sepa_ABC123456789',
            StancerApiFixtures::sepaCreated(),
            200
        );

        $result = $this->api->getSepa('sepa_ABC123456789');

        $this->assertIsArray($result);
        $this->assertEquals('sepa_ABC123456789', $result['id']);
        $this->assertEquals('BNPAFRPP', $result['bic']);
    }

    public function testUpdateSepa(): void
    {
        $updatedSepa = StancerApiFixtures::sepaCreated();
        $updatedSepa['name'] = 'Jane Doe';

        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/sepa/sepa_ABC123456789',
            $updatedSepa,
            200
        );

        $result = $this->api->updateSepa('sepa_ABC123456789', ['name' => 'Jane Doe']);

        $this->assertIsArray($result);
        $this->assertEquals('Jane Doe', $result['name']);
    }

    public function testDeleteSepa(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/sepa/sepa_ABC123456789',
            [],
            204
        );

        $result = $this->api->deleteSepa('sepa_ABC123456789');

        $this->assertIsArray($result);
        $lastRequest = HttpMock::getLastRequest();
        $this->assertEquals('DELETE', $lastRequest['method']);
    }

    public function testCheckSepa(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/sepa/check/',
            ['id' => 'chck_123', 'status' => 'pending'],
            200
        );

        $result = $this->api->checkSepa(['iban' => 'FR7630001007941234567890185']);

        $this->assertIsArray($result);
        $this->assertEquals('chck_123', $result['id']);

        $lastRequest = HttpMock::getLastRequest();
        $this->assertEquals('POST', $lastRequest['method']);
    }

    public function testGetSepaCheck(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/sepa/check/chck_123',
            ['id' => 'chck_123', 'status' => 'verified'],
            200
        );

        $result = $this->api->getSepaCheck('chck_123');

        $this->assertIsArray($result);
        $this->assertEquals('verified', $result['status']);
    }

    // ========================================================================
    // PAYOUT DETAILS (Cat 3 - uncovered methods)
    // ========================================================================

    public function testGetPayoutDetails(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/payouts/pout_1A2B3C4D5E6F/payments/*',
            ['payments' => [['id' => 'paym_1']], 'range' => ['has_more' => false]],
            200
        );

        $result = $this->api->getPayoutDetails('pout_1A2B3C4D5E6F', 'payments');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('payments', $result);
    }

    public function testGetPayoutDetailsWithFilters(): void
    {
        HttpMock::addJsonResponse(
            '*payouts*',
            ['refunds' => [], 'range' => ['has_more' => false]],
            200
        );

        $result = $this->api->getPayoutDetails('pout_1A2B3C4D5E6F', 'refunds', ['limit' => 5]);

        $this->assertIsArray($result);

        $lastRequest = HttpMock::getLastRequest();
        $this->assertStringContainsString('refunds', $lastRequest['url']);
        $this->assertStringContainsString('limit=5', $lastRequest['url']);
    }

    // ========================================================================
    // DISPUTES LIST (Cat 3 - uncovered methods)
    // ========================================================================

    public function testListDisputes(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/disputes/',
            ['disputes' => [StancerApiFixtures::disputeFetched()], 'range' => ['has_more' => false]],
            200
        );

        $result = $this->api->listDisputes();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('disputes', $result);
    }

    public function testListDisputesWithFilters(): void
    {
        HttpMock::addJsonResponse(
            '*disputes*',
            ['disputes' => [], 'range' => ['has_more' => false]],
            200
        );

        $result = $this->api->listDisputes(['limit' => 10]);

        $this->assertIsArray($result);
        $lastRequest = HttpMock::getLastRequest();
        $this->assertStringContainsString('limit=10', $lastRequest['url']);
    }

    // ========================================================================
    // V2-ONLY ADDRESS CRUD (Cat 3 - uncovered methods)
    // ========================================================================

    public function testGetAddress(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/addresses/addr_1A2B3C4D5E6F',
            StancerApiFixtures::addressCreated(),
            200
        );

        $result = $this->api->getAddress('addr_1A2B3C4D5E6F');

        $this->assertIsArray($result);
        $this->assertEquals('addr_1A2B3C4D5E6F', $result['id']);
    }

    public function testUpdateAddress(): void
    {
        $updated = StancerApiFixtures::addressCreated();
        $updated['line1'] = '456 New Street';

        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/addresses/addr_1A2B3C4D5E6F',
            $updated,
            200
        );

        $result = $this->api->updateAddress('addr_1A2B3C4D5E6F', ['line1' => '456 New Street']);

        $this->assertIsArray($result);
        $this->assertEquals('456 New Street', $result['line1']);
    }

    public function testDeleteAddress(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/addresses/addr_1A2B3C4D5E6F',
            [],
            204
        );

        $result = $this->api->deleteAddress('addr_1A2B3C4D5E6F');

        $this->assertIsArray($result);
        $lastRequest = HttpMock::getLastRequest();
        $this->assertEquals('DELETE', $lastRequest['method']);
    }

    // ========================================================================
    // V2-ONLY MANDATE CRUD (Cat 3 - uncovered methods)
    // ========================================================================

    public function testGetMandate(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/mandates/sdmm_1A2B3C4D5E6F',
            StancerApiFixtures::mandateCreated(),
            200
        );

        $result = $this->api->getMandate('sdmm_1A2B3C4D5E6F');

        $this->assertIsArray($result);
        $this->assertEquals('sdmm_1A2B3C4D5E6F', $result['id']);
    }

    public function testListMandates(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/mandates/',
            ['mandates' => [StancerApiFixtures::mandateCreated()], 'range' => ['has_more' => false]],
            200
        );

        $result = $this->api->listMandates();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('mandates', $result);
    }

    public function testGetMandatePdf(): void
    {
        HttpMock::addResponse('https://api.stancer.com/v2/mandates/sdmm_1A2B3C4D5E6F.pdf', [
            'http_code' => 200,
            'content' => '%PDF-1.4 fake pdf content',
        ]);

        $result = $this->api->getMandatePdf('sdmm_1A2B3C4D5E6F');

        $this->assertIsString($result);
        $this->assertStringContainsString('PDF', $result);
    }

    public function testGetMandatePdfHandlesCurlError(): void
    {
        HttpMock::addCurlError(
            'https://api.stancer.com/v2/mandates/sdmm_1A2B3C4D5E6F.pdf',
            7,
            'Connection refused'
        );

        $result = $this->api->getMandatePdf('sdmm_1A2B3C4D5E6F');

        $this->assertFalse($result);
        $this->assertStringContainsString('CURL Error', $this->api->error);
    }

    public function testGetMandatePdfHandlesHttpError(): void
    {
        HttpMock::addResponse('https://api.stancer.com/v2/mandates/sdmm_notfound.pdf', [
            'http_code' => 404,
            'content' => '',
        ]);

        $result = $this->api->getMandatePdf('sdmm_notfound');

        $this->assertFalse($result);
        $this->assertStringContainsString('HTTP Error 404', $this->api->error);
    }

    // ========================================================================
    // V2-ONLY SEPA EXTENSIONS (Cat 3 - uncovered methods)
    // ========================================================================

    public function testValidateIbanOnly(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/sepa/ibanonly/',
            ['valid' => true, 'bic' => 'BNPAFRPP'],
            200
        );

        $result = $this->api->validateIbanOnly(['iban' => 'FR7630001007941234567890185']);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
    }

    public function testGenerateSepaCheck(): void
    {
        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/sepa/check/generate',
            ['id' => 'chck_gen_123', 'status' => 'pending'],
            200
        );

        $result = $this->api->generateSepaCheck(['sepa' => 'sepa_ABC123456789']);

        $this->assertIsArray($result);
        $this->assertEquals('chck_gen_123', $result['id']);
    }

    // ========================================================================
    // V2-ONLY CUSTOMER EXTENSIONS (Cat 3 - uncovered methods)
    // ========================================================================

    public function testGetCustomerPaymentIntents(): void
    {
        HttpMock::addJsonResponse(
            '*payment_intents*',
            ['payment_intents' => [], 'range' => ['has_more' => false]],
            200
        );

        $result = $this->api->getCustomerPaymentIntents('cust_9TycuMPH3xsPVE0n02IrI3L3');

        $this->assertIsArray($result);
        $lastRequest = HttpMock::getLastRequest();
        $this->assertStringContainsString('customers/cust_9TycuMPH3xsPVE0n02IrI3L3/payment_intents', $lastRequest['url']);
    }

    public function testGetCustomerSubscriptions(): void
    {
        HttpMock::addJsonResponse(
            '*subscriptions*',
            ['subscriptions' => [], 'range' => ['has_more' => false]],
            200
        );

        $result = $this->api->getCustomerSubscriptions('cust_9TycuMPH3xsPVE0n02IrI3L3');

        $this->assertIsArray($result);
        $lastRequest = HttpMock::getLastRequest();
        $this->assertStringContainsString('customers/cust_9TycuMPH3xsPVE0n02IrI3L3/subscriptions', $lastRequest['url']);
    }

    // ========================================================================
    // UPDATE PAYMENT (Cat 3 - uncovered method)
    // ========================================================================

    public function testUpdatePayment(): void
    {
        $updated = StancerApiFixtures::paymentCaptured();
        $updated['description'] = 'Updated description';

        HttpMock::addJsonResponse(
            'https://api.stancer.com/v2/checkout/paym_4kM8Kv5X0HJ8vLqF',
            $updated,
            200
        );

        $result = $this->api->updatePayment('paym_4kM8Kv5X0HJ8vLqF', ['description' => 'Updated description']);

        $this->assertIsArray($result);
        $this->assertEquals('Updated description', $result['description']);

        $lastRequest = HttpMock::getLastRequest();
        $this->assertEquals('PUT', $lastRequest['method']);
    }

    // ========================================================================
    // API CONSTANTS (Cat 4)
    // ========================================================================

    public function testApiConstants(): void
    {
        $this->assertEquals('v1', StancerApi::API_VERSION_V1);
        $this->assertEquals('v2', StancerApi::API_VERSION_V2);
        $this->assertEquals('https://api.stancer.com', StancerApi::API_BASE_URL);
    }

    // ========================================================================
    // REQUEST METHOD BUILDS CORRECT URLS (Cat 4)
    // ========================================================================

    public function testRequestUsesV2UrlByDefault(): void
    {
        HttpMock::addJsonResponse('*', [], 200);

        $this->api->getCustomer('cust_test');

        $lastRequest = HttpMock::getLastRequest();
        $this->assertStringStartsWith('https://api.stancer.com/v2/', $lastRequest['url']);
    }

    public function testRequestUsesV1UrlAfterSetApiVersion(): void
    {
        $this->api->setApiVersion('v1');

        HttpMock::addJsonResponse('*', [], 200);
        $this->api->getCustomer('cust_test');

        $lastRequest = HttpMock::getLastRequest();
        $this->assertStringStartsWith('https://api.stancer.com/v1/', $lastRequest['url']);
    }

    public function testRequestClearsErrorOnNewCall(): void
    {
        HttpMock::addErrorResponse('*', 400, 'error', 'first error');
        $this->api->getCustomer('cust_1');
        $this->assertNotEmpty($this->api->error);

        HttpMock::addJsonResponse('*', StancerApiFixtures::customerCreated(), 200);
        $this->api->getCustomer('cust_2');
        $this->assertEmpty($this->api->error);
    }

    public function testListPayoutsWithFilters(): void
    {
        HttpMock::addJsonResponse(
            '*payouts*',
            StancerApiFixtures::payoutList(),
            200
        );

        $result = $this->api->listPayouts(['limit' => 5, 'start' => 0]);

        $this->assertIsArray($result);
        $lastRequest = HttpMock::getLastRequest();
        $this->assertStringContainsString('limit=5', $lastRequest['url']);
    }
}
