<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Stancer_payments class
 */
class StancerPaymentsTest extends TestCase
{
    private $db;
    private $payment;

    protected function setUp(): void
    {
        global $conf;
        $conf->entity = 1;

        $this->db = $this->createMock(\DoliDB::class);

        require_once __DIR__ . '/../../../class/stancer_payments.class.php';

        $this->payment = new \Stancer_payments($this->db);
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    public function testConstructorSetsProperties(): void
    {
        $this->assertEquals('stancer', $this->payment->module);
        $this->assertEquals('stancer_payments', $this->payment->element);
        $this->assertEquals('stancer_stancer_payments', $this->payment->table_element);
        $this->assertSame($this->db, $this->payment->db);
    }

    // =========================================================================
    // Status constants tests
    // =========================================================================

    public function testStatusConstantsAreDefined(): void
    {
        $this->assertEquals(-10, \Stancer_payments::STATUS_ERROR);
        $this->assertEquals(-1, \Stancer_payments::STATUS_HIDDEN);
        $this->assertEquals(0, \Stancer_payments::STATUS_DRAFT);
        $this->assertEquals(1, \Stancer_payments::STATUS_AUTHORIZED);
        $this->assertEquals(2, \Stancer_payments::STATUS_CAPTURED);
        $this->assertEquals(3, \Stancer_payments::STATUS_CAPTURE_SENT);
        $this->assertEquals(4, \Stancer_payments::STATUS_DISPUTED);
        $this->assertEquals(5, \Stancer_payments::STATUS_EXPIRED);
        $this->assertEquals(6, \Stancer_payments::STATUS_FAILED);
        $this->assertEquals(7, \Stancer_payments::STATUS_REFUSED);
        $this->assertEquals(8, \Stancer_payments::STATUS_TO_CAPTURE);
        $this->assertEquals(9, \Stancer_payments::STATUS_CANCELED);
        $this->assertEquals(10, \Stancer_payments::STATUS_VALIDATED);
    }

    // =========================================================================
    // convert_status_code() tests
    // =========================================================================

    /**
     * @dataProvider statusStringProvider
     */
    public function testConvertStatusCodeFromString(string $statusString, int $expectedCode): void
    {
        $result = $this->payment->convert_status_code($statusString);
        $this->assertEquals($expectedCode, $result);
    }

    public static function statusStringProvider(): array
    {
        return [
            'draft' => ['draft', 0],
            'authorized' => ['authorized', 1],
            'captured' => ['captured', 2],
            'capture_sent' => ['capture_sent', 3],
            'disputed' => ['disputed', 4],
            'expired' => ['expired', 5],
            'failed' => ['failed', 6],
            'refused' => ['refused', 7],
            'to_capture' => ['to_capture', 8],
            'canceled' => ['canceled', 9],
            'validated' => ['validated', 10],
            'error' => ['error', -10],
            'hidden' => ['hidden', -1],
        ];
    }

    public function testConvertStatusCodeFromUnknownStringReturnsError(): void
    {
        $result = $this->payment->convert_status_code('unknown_status');
        $this->assertEquals(\Stancer_payments::STATUS_ERROR, $result);
    }

    public function testConvertStatusCodeFromEmptyStringReturnsError(): void
    {
        $result = $this->payment->convert_status_code('');
        $this->assertEquals(\Stancer_payments::STATUS_ERROR, $result);
    }

    // =========================================================================
    // isInitPaid() tests
    // =========================================================================

    /**
     * @dataProvider initPaidStatusProvider
     */
    public function testIsInitPaid(int $status, bool $expected): void
    {
        $this->payment->status = $status;
        $this->assertEquals($expected, $this->payment->isInitPaid());
    }

    public static function initPaidStatusProvider(): array
    {
        return [
            'draft is not init paid' => [0, false],
            'authorized is init paid' => [1, true],
            'captured is init paid' => [2, true],
            'capture_sent is init paid' => [3, true],
            'disputed is not init paid' => [4, false],
            'expired is not init paid' => [5, false],
            'failed is not init paid' => [6, false],
            'refused is not init paid' => [7, false],
            'to_capture is init paid' => [8, true],
            'canceled is not init paid' => [9, false],
        ];
    }

    // =========================================================================
    // isDefinitivePaid() tests
    // =========================================================================

    /**
     * @dataProvider definitivePaidStatusProvider
     */
    public function testIsDefinitivePaid(int $status, bool $expected): void
    {
        $this->payment->status = $status;
        $this->assertEquals($expected, $this->payment->isDefinitivePaid());
    }

    public static function definitivePaidStatusProvider(): array
    {
        return [
            'draft is not definitive paid' => [0, false],
            'authorized is not definitive paid' => [1, false],
            'captured is definitive paid' => [2, true],
            'capture_sent is definitive paid' => [3, true],
            'disputed is not definitive paid' => [4, false],
            'expired is not definitive paid' => [5, false],
            'failed is not definitive paid' => [6, false],
            'refused is not definitive paid' => [7, false],
            'to_capture is not definitive paid' => [8, false],
        ];
    }

    public function testIsDefinitivePaidWithStatusCodeParameter(): void
    {
        $this->payment->status = \Stancer_payments::STATUS_DRAFT;

        // Override with parameter
        $this->assertTrue($this->payment->isDefinitivePaid(\Stancer_payments::STATUS_CAPTURED));
        $this->assertTrue($this->payment->isDefinitivePaid(\Stancer_payments::STATUS_CAPTURE_SENT));
        $this->assertFalse($this->payment->isDefinitivePaid(\Stancer_payments::STATUS_AUTHORIZED));
    }

    // =========================================================================
    // canBeReused() tests
    // =========================================================================

    /**
     * @dataProvider canBeReusedStatusProvider
     */
    public function testCanBeReused(int $status, bool $expected): void
    {
        $this->payment->status = $status;
        $this->assertEquals($expected, $this->payment->canBeReused());
    }

    public static function canBeReusedStatusProvider(): array
    {
        return [
            'draft can be reused' => [0, true],
            'authorized cannot be reused' => [1, false],
            'captured cannot be reused' => [2, false],
            'failed cannot be reused' => [6, false],
        ];
    }

    // =========================================================================
    // fillDataArray() tests
    // =========================================================================

    public function testFillDataArrayWithBasicValues(): void
    {
        $data = [
            'stancer_id' => 'paym_test123',
            'amount' => 1000,
            'currency' => 'eur',
            'description' => 'Test payment',
            'method' => 'card',
        ];

        $this->payment->fillDataArray($data);

        $this->assertEquals('paym_test123', $this->payment->stancer_id);
        $this->assertEquals(1000, $this->payment->amount);
        $this->assertEquals('eur', $this->payment->currency);
        $this->assertEquals('Test payment', $this->payment->description);
        $this->assertEquals('card', $this->payment->method);
    }

    public function testFillDataArrayWithStatusConversion(): void
    {
        $data = [
            'status' => 'captured',
        ];

        $this->payment->fillDataArray($data);

        $this->assertEquals(\Stancer_payments::STATUS_CAPTURED, $this->payment->status);
    }

    public function testFillDataArrayWithArrayValue(): void
    {
        $refunds = ['refund1', 'refund2'];
        $data = [
            'refunds' => $refunds,
        ];

        $this->payment->fillDataArray($data);

        $this->assertEquals(json_encode($refunds), $this->payment->refunds);
    }

    public function testFillDataArrayWithBooleanValue(): void
    {
        $data = [
            'capture' => true,
            'live_mode' => false,
        ];

        $this->payment->fillDataArray($data);

        $this->assertTrue($this->payment->capture);
        $this->assertFalse($this->payment->live_mode);
    }

    public function testFillDataArraySkipsEmptyValuesUnlessForced(): void
    {
        $this->payment->amount = 500;

        $data = [
            'amount' => '',
        ];

        $this->payment->fillDataArray($data, false);
        $this->assertEquals(500, $this->payment->amount);

        $this->payment->fillDataArray($data, true);
        $this->assertEquals('', $this->payment->amount);
    }

    public function testFillDataArrayWithNullStatus(): void
    {
        $this->payment->status = \Stancer_payments::STATUS_DRAFT;

        $data = [
            'status' => null,
        ];

        $this->payment->fillDataArray($data);

        // Status should remain unchanged when null
        $this->assertEquals(\Stancer_payments::STATUS_DRAFT, $this->payment->status);
    }

    // =========================================================================
    // Tab status mapping tests
    // =========================================================================

    public function testTabStatusContainsAllStatuses(): void
    {
        $expectedStatuses = [
            '-10' => 'error',
            '-1' => 'hidden',
            '0' => 'draft',
            '1' => 'authorized',
            '2' => 'captured',
            '3' => 'capture_sent',
            '4' => 'disputed',
            '5' => 'expired',
            '6' => 'failed',
            '7' => 'refused',
            '8' => 'to_capture',
            '9' => 'canceled',
            '10' => 'validated',
        ];

        $this->assertEquals($expectedStatuses, \Stancer_payments::$tab_status);
    }

    // =========================================================================
    // Tab response mapping tests
    // =========================================================================

    public function testTabResponseContainsCommonCodes(): void
    {
        $this->assertArrayHasKey('00', $this->payment->tab_response);
        $this->assertArrayHasKey('51', $this->payment->tab_response);
        $this->assertArrayHasKey('54', $this->payment->tab_response);

        $this->assertStringContainsString('approval', $this->payment->tab_response['00']);
        $this->assertStringContainsString('Insufficient funds', $this->payment->tab_response['51']);
        $this->assertStringContainsString('Expired card', $this->payment->tab_response['54']);
    }

    // =========================================================================
    // Validation tests
    // =========================================================================

    public function testValidateReturnsZeroIfAlreadyValidated(): void
    {
        global $user;
        $this->payment->status = \Stancer_payments::STATUS_VALIDATED;

        $result = $this->payment->validate($user);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // setDraft tests
    // =========================================================================

    public function testSetDraftReturnsZeroIfAlreadyDraft(): void
    {
        global $user;
        $this->payment->status = \Stancer_payments::STATUS_DRAFT;

        $result = $this->payment->setDraft($user);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // cancel tests
    // =========================================================================

    public function testCancelReturnsZeroIfNotValidated(): void
    {
        global $user;
        $this->payment->status = \Stancer_payments::STATUS_DRAFT;

        $result = $this->payment->cancel($user);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // reopen tests
    // =========================================================================

    public function testReopenReturnsZeroIfAlreadyValidated(): void
    {
        global $user;
        $this->payment->status = \Stancer_payments::STATUS_VALIDATED;

        $result = $this->payment->reopen($user);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // deleteLine tests
    // =========================================================================

    public function testDeleteLineReturnsErrorIfStatusNegative(): void
    {
        global $user;
        $this->payment->status = -1;

        $result = $this->payment->deleteLine($user, 1);

        $this->assertEquals(-2, $result);
        $this->assertEquals('ErrorDeleteLineNotAllowedByObjectStatus', $this->payment->error);
    }

    // =========================================================================
    // fillDataFromApi() - ID extraction from expanded API objects
    // =========================================================================

    public function testFillDataFromApiExtractsCustomerIdFromObject(): void
    {
        $apiData = \StancerApiFixtures::paymentWithExpandedObjects();
        $this->payment->unique_id = $apiData['unique_id'];

        $this->payment->fillDataFromApi($apiData);

        $this->assertEquals('cust_9TycuMPH3xsPVE0n02IrI3L3', $this->payment->customer);
    }

    public function testFillDataFromApiExtractsCardIdFromObject(): void
    {
        $apiData = \StancerApiFixtures::paymentWithExpandedObjects();
        $this->payment->unique_id = $apiData['unique_id'];

        $this->payment->fillDataFromApi($apiData);

        $this->assertEquals('card_xognFbZs935LMKJp', $this->payment->card);
    }

    public function testFillDataFromApiExtractsSepaIdFromObject(): void
    {
        $apiData = \StancerApiFixtures::paymentWithExpandedObjects();
        $this->payment->unique_id = $apiData['unique_id'];

        $this->payment->fillDataFromApi($apiData);

        $this->assertEquals('sepa_ABC123456789', $this->payment->sepa);
    }

    public function testFillDataFromApiKeepsStringIds(): void
    {
        $apiData = \StancerApiFixtures::paymentWithStringIds();
        $this->payment->unique_id = $apiData['unique_id'];

        $this->payment->fillDataFromApi($apiData);

        $this->assertEquals('cust_9TycuMPH3xsPVE0n02IrI3L3', $this->payment->customer);
        $this->assertEquals('card_xognFbZs935LMKJp', $this->payment->card);
        $this->assertEquals('sepa_ABC123456789', $this->payment->sepa);
    }

    public function testFillDataFromApiHandlesNullValues(): void
    {
        $apiData = \StancerApiFixtures::paymentWithStringIds();
        $apiData['customer'] = null;
        $apiData['card'] = null;
        $apiData['sepa'] = null;
        $this->payment->unique_id = $apiData['unique_id'];

        $this->payment->fillDataFromApi($apiData);

        $this->assertNull($this->payment->card);
        $this->assertNull($this->payment->sepa);
    }

    public function testFillDataFromApiCustomerObjectWithoutIdStoresNull(): void
    {
        $apiData = \StancerApiFixtures::paymentWithExpandedObjects();
        $apiData['customer'] = ['email' => 'test@example.com', 'name' => 'No Id Customer'];
        $this->payment->unique_id = $apiData['unique_id'];

        $this->payment->fillDataFromApi($apiData);

        $this->assertNull($this->payment->customer);
    }

    public function testFillDataFromApiExpandedObjectsFitInVarchar30(): void
    {
        $apiData = \StancerApiFixtures::paymentWithExpandedObjects();
        $this->payment->unique_id = $apiData['unique_id'];

        $this->payment->fillDataFromApi($apiData);

        $this->assertLessThanOrEqual(30, strlen($this->payment->customer ?? ''));
        $this->assertLessThanOrEqual(30, strlen($this->payment->card ?? ''));
        $this->assertLessThanOrEqual(30, strlen($this->payment->sepa ?? ''));
    }

    // =========================================================================
    // fillDataFromApi() - UUID logic tests
    // =========================================================================

    public function testFillDataFromApiReturnsErrorWhenBothUuidsEmpty(): void
    {
        $apiData = \StancerApiFixtures::paymentCaptured();
        unset($apiData['unique_id']);
        $this->payment->unique_id = '';

        $result = $this->payment->fillDataFromApi($apiData);

        $this->assertEquals(-1, $result);
    }

    public function testFillDataFromApiKeepsOurUuidWhenDifferent(): void
    {
        $apiData = \StancerApiFixtures::paymentCaptured();
        $apiData['unique_id'] = 'stancer_uuid_123';
        $this->payment->unique_id = 'our_uuid_456';

        $this->payment->fillDataFromApi($apiData);

        $this->assertEquals('our_uuid_456', $this->payment->unique_id);
    }

    public function testFillDataFromApiUsesOurUuidWhenApiUuidEmpty(): void
    {
        $apiData = \StancerApiFixtures::paymentCaptured();
        $apiData['unique_id'] = '';
        $this->payment->unique_id = 'our_uuid_789';

        $this->payment->fillDataFromApi($apiData);

        $this->assertEquals('our_uuid_789', $this->payment->unique_id);
    }

    // =========================================================================
    // fillDataFromApi() - Full field mapping tests
    // =========================================================================

    public function testFillDataFromApiMapsAllFields(): void
    {
        $apiData = \StancerApiFixtures::paymentWithExpandedObjects();
        $this->payment->unique_id = $apiData['unique_id'];

        $result = $this->payment->fillDataFromApi($apiData);

        $this->assertEquals(0, $result);
        $this->assertEquals('paym_4kM8Kv5X0HJ8vLqF', $this->payment->stancer_id);
        $this->assertEquals(2500, $this->payment->amount);
        $this->assertEquals('eur', $this->payment->currency);
        $this->assertEquals('Test payment with expanded objects', $this->payment->description);
        $this->assertEquals('FA2602-4742', $this->payment->order_id);
        $this->assertEquals('card', $this->payment->method);
        $this->assertEquals(\Stancer_payments::STATUS_CAPTURED, $this->payment->status);
        $this->assertEquals('00', $this->payment->response);
        $this->assertTrue($this->payment->capture);
        $this->assertEquals(1704067200, $this->payment->created);
    }

    public function testFillDataFromApiHandlesEmptyCustomerWithDefault(): void
    {
        global $conf;
        $conf->global->STANCER_DEFAULT_CUSTOMER_IF_NULL = '42';

        $apiData = \StancerApiFixtures::paymentCaptured();
        $apiData['customer'] = null;
        $this->payment->unique_id = $apiData['unique_id'];

        $this->payment->fillDataFromApi($apiData);

        $this->assertEquals('42', $this->payment->fk_soc);

        unset($conf->global->STANCER_DEFAULT_CUSTOMER_IF_NULL);
    }

    public function testFillDataFromApiSetsLiveModeFromConf(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '1';

        $apiData = \StancerApiFixtures::paymentCaptured();
        $this->payment->unique_id = $apiData['unique_id'];

        $this->payment->fillDataFromApi($apiData);

        $this->assertEquals('1', $this->payment->live_mode);

        unset($conf->global->STANCER_IS_PROD);
    }

    public function testFillDataFromApiSetsEntityFromConf(): void
    {
        global $conf;
        $conf->entity = 3;

        $apiData = \StancerApiFixtures::paymentCaptured();
        $this->payment->unique_id = $apiData['unique_id'];

        $this->payment->fillDataFromApi($apiData);

        $this->assertEquals(3, $this->payment->entity);

        $conf->entity = 1;
    }

    // =========================================================================
    // LibStatut() tests
    // =========================================================================

    /**
     * @dataProvider libStatutProvider
     */
    public function testLibStatutReturnsCorrectLabel(int $status, string $expectedLabel): void
    {
        $result = $this->payment->LibStatut($status);
        $this->assertEquals($expectedLabel, $result);
    }

    public static function libStatutProvider(): array
    {
        return [
            'draft' => [0, 'Draft'],
            'authorized' => [1, 'StancerAuthorized'],
            'captured' => [2, 'StancerCaptured'],
            'capture_sent' => [3, 'StancerCaptureSent'],
            'disputed' => [4, 'StancerDisputed'],
            'expired' => [5, 'StancerExpired'],
            'failed' => [6, 'StancerFailed'],
            'refused' => [7, 'StancerRefused'],
            'to_capture' => [8, 'StancerToCapture'],
        ];
    }

    public function testGetLabelStatusDelegatesToLibStatut(): void
    {
        $this->payment->status = \Stancer_payments::STATUS_CAPTURED;
        $this->assertEquals('StancerCaptured', $this->payment->getLabelStatus());
    }

    public function testGetLibStatutDelegatesToLibStatut(): void
    {
        $this->payment->status = \Stancer_payments::STATUS_AUTHORIZED;
        $this->assertEquals('StancerAuthorized', $this->payment->getLibStatut());
    }

    // =========================================================================
    // getLibResponse() tests
    // =========================================================================

    public function testGetLibResponseWithValidCode(): void
    {
        $this->payment->response = '00';
        $result = $this->payment->getLibResponse();
        $this->assertNotEmpty($result);
    }

    public function testGetLibResponseWithEmptyResponse(): void
    {
        $this->payment->response = '';
        $result = $this->payment->getLibResponse();
        $this->assertEquals('', $result);
    }

    // =========================================================================
    // showOutputField() tests
    // =========================================================================

    public function testShowOutputFieldFormatsAmountInCents(): void
    {
        $val = ['type' => 'integer'];
        $result = $this->payment->showOutputField($val, 'amount', 10050);
        $this->assertEquals('100.50', $result);
    }

    public function testShowOutputFieldFormatsFeeInCents(): void
    {
        $val = ['type' => 'integer'];
        $result = $this->payment->showOutputField($val, 'fee', 250);
        $this->assertEquals('2.50', $result);
    }

    public function testShowOutputFieldFormatsStancerIdAsLink(): void
    {
        $val = ['type' => 'varchar'];
        $result = $this->payment->showOutputField($val, 'stancer_id', 'paym_test123');
        $this->assertStringContainsString('paym_test123', $result);
        $this->assertStringContainsString('href=', $result);
        $this->assertStringContainsString('stancer.com', $result);
    }

    // =========================================================================
    // initAsSpecimen() tests
    // =========================================================================

    public function testInitAsSpecimenCallsWithoutError(): void
    {
        $this->payment->initAsSpecimen();
        $this->assertTrue(true);
    }

    // =========================================================================
    // getNomUrl() tests (Cat 5 - standard Dolibarr methods)
    // =========================================================================

    public function testGetNomUrlReturnsLinkWithRef(): void
    {
        $this->payment->id = 42;
        $this->payment->ref = 'PAY-001';
        $this->payment->status = \Stancer_payments::STATUS_CAPTURED;

        $result = $this->payment->getNomUrl();

        $this->assertStringContainsString('PAY-001', $result);
        $this->assertStringContainsString('href=', $result);
        $this->assertStringContainsString('stancer_payments_list.php', $result);
        $this->assertStringContainsString('search_stancer_id=PAY-001', $result);
    }

    public function testGetNomUrlWithNoLinkReturnsSpan(): void
    {
        $this->payment->id = 42;
        $this->payment->ref = 'PAY-001';

        $result = $this->payment->getNomUrl(0, 'nolink');

        $this->assertStringContainsString('PAY-001', $result);
        $this->assertStringContainsString('<span', $result);
        $this->assertStringNotContainsString('<a ', $result);
    }

    public function testGetNomUrlWithPictoIncludesImg(): void
    {
        $this->payment->id = 42;
        $this->payment->ref = 'PAY-001';
        $this->payment->status = \Stancer_payments::STATUS_DRAFT;

        $result = $this->payment->getNomUrl(1);

        $this->assertStringContainsString('PAY-001', $result);
        $this->assertStringContainsString('<img', $result);
    }

    // =========================================================================
    // info() tests (Cat 5 - standard Dolibarr methods)
    // =========================================================================

    public function testInfoCallsDbQuery(): void
    {
        $db = $this->createMock(\DoliDB::class);
        $db->expects($this->once())
            ->method('query')
            ->with($this->stringContains('stancer_stancer_payments'))
            ->willReturn(true);
        $db->expects($this->once())
            ->method('num_rows')
            ->willReturn(0);
        $db->expects($this->once())
            ->method('free');

        $payment = new \Stancer_payments($db);
        $payment->info(42);
    }

    // =========================================================================
    // getLinesArray() tests (Cat 5 - standard Dolibarr methods)
    // =========================================================================

    public function testGetLinesArrayReturnsEmptyArray(): void
    {
        $this->payment->id = 1;
        $result = $this->payment->getLinesArray();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // generateDocument() tests (Cat 5 - standard Dolibarr methods)
    // =========================================================================

    public function testGenerateDocumentReturnsZeroWhenDocgenDisabled(): void
    {
        global $langs;
        $result = $this->payment->generateDocument('', $langs);

        $this->assertEquals(0, $result);
    }
}
