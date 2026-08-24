<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Fixtures/StancerApiFixtures.php';

/**
 * Unit tests for Stancer_payouts class
 */
class StancerPayoutsTest extends TestCase
{
    private $db;
    private $payout;

    protected function setUp(): void
    {
        global $conf;
        $conf->entity = 1;

        $this->db = $this->createMock(\DoliDB::class);

        require_once __DIR__ . '/../../../class/stancer_payouts.class.php';

        $this->payout = new \Stancer_payouts($this->db);
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    public function testConstructorSetsProperties(): void
    {
        $this->assertEquals('stancer', $this->payout->module);
        $this->assertEquals('stancer_payouts', $this->payout->element);
        $this->assertEquals('stancer_stancer_payouts', $this->payout->table_element);
        $this->assertSame($this->db, $this->payout->db);
    }

    // =========================================================================
    // Status constants tests
    // =========================================================================

    public function testStatusConstantsAreDefined(): void
    {
        $this->assertEquals(-10, \Stancer_payouts::STATUS_ERROR);
        $this->assertEquals(0, \Stancer_payouts::STATUS_DRAFT);
        $this->assertEquals(1, \Stancer_payouts::STATUS_PENDING);
        $this->assertEquals(2, \Stancer_payouts::STATUS_TO_PAY);
        $this->assertEquals(3, \Stancer_payouts::STATUS_SENT);
        $this->assertEquals(4, \Stancer_payouts::STATUS_PAID);
        $this->assertEquals(5, \Stancer_payouts::STATUS_FAILED);
        $this->assertEquals(9, \Stancer_payouts::STATUS_CANCELED);
        $this->assertEquals(10, \Stancer_payouts::STATUS_VALIDATED);
    }

    // =========================================================================
    // convert_status_code() tests
    // =========================================================================

    /**
     * @dataProvider statusStringProvider
     */
    public function testConvertStatusCodeFromString(string $statusString, int $expectedCode): void
    {
        $result = $this->payout->convert_status_code($statusString);
        $this->assertEquals($expectedCode, $result);
    }

    public static function statusStringProvider(): array
    {
        return [
            'draft' => ['draft', 0],
            'pending' => ['pending', 1],
            'to_pay' => ['to_pay', 2],
            'sent' => ['sent', 3],
            'paid' => ['paid', 4],
            'failed' => ['failed', 5],
            'canceled' => ['canceled', 9],
            'validated' => ['validated', 10],
            'error' => ['error', -10],
        ];
    }

    public function testConvertStatusCodeFromUnknownStringReturnsError(): void
    {
        $result = $this->payout->convert_status_code('unknown_status');
        $this->assertEquals(\Stancer_payouts::STATUS_ERROR, $result);
    }

    // =========================================================================
    // fillDataArray() tests
    // =========================================================================

    public function testFillDataArrayWithBasicValues(): void
    {
        $data = [
            'payout_id' => 'pout_test123',
            'amount' => 10000,
            'currency' => 'eur',
            'fees' => 50,
        ];

        $this->payout->fillDataArray($data);

        $this->assertEquals('pout_test123', $this->payout->payout_id);
        $this->assertEquals(10000, $this->payout->amount);
        $this->assertEquals('eur', $this->payout->currency);
        $this->assertEquals(50, $this->payout->fees);
    }

    public function testFillDataArrayWithStatusConversion(): void
    {
        $data = [
            'status' => 'paid',
        ];

        $this->payout->fillDataArray($data);

        $this->assertEquals(\Stancer_payouts::STATUS_PAID, $this->payout->status);
    }

    public function testFillDataArrayWithArrayValue(): void
    {
        $details = ['detail1', 'detail2'];
        $data = [
            'details' => $details,
        ];

        $this->payout->fillDataArray($data);

        $this->assertEquals(json_encode($details), $this->payout->details);
    }

    public function testFillDataArrayWithBooleanValue(): void
    {
        $data = [
            'live_mode' => true,
        ];

        $this->payout->fillDataArray($data);

        $this->assertTrue($this->payout->live_mode);
    }

    public function testFillDataArrayTrimsStringValues(): void
    {
        $data = [
            'currency' => '  eur  ',
        ];

        $this->payout->fillDataArray($data);

        $this->assertEquals('eur', $this->payout->currency);
    }

    // =========================================================================
    // Tab status mapping tests
    // =========================================================================

    public function testTabStatusContainsAllStatuses(): void
    {
        $expectedStatuses = [
            '-10' => 'error',
            '0' => 'draft',
            '1' => 'pending',
            '2' => 'to_pay',
            '3' => 'sent',
            '4' => 'paid',
            '5' => 'failed',
            '9' => 'canceled',
            '10' => 'validated',
        ];

        $this->assertEquals($expectedStatuses, $this->payout->tab_status);
    }

    // =========================================================================
    // Validation tests
    // =========================================================================

    public function testValidateReturnsZeroIfAlreadyValidated(): void
    {
        global $user;
        $this->payout->status = \Stancer_payouts::STATUS_VALIDATED;

        $result = $this->payout->validate($user);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // setDraft tests
    // =========================================================================

    public function testSetDraftReturnsZeroIfAlreadyDraft(): void
    {
        global $user;
        $this->payout->status = \Stancer_payouts::STATUS_DRAFT;

        $result = $this->payout->setDraft($user);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // cancel tests
    // =========================================================================

    public function testCancelReturnsZeroIfNotValidated(): void
    {
        global $user;
        $this->payout->status = \Stancer_payouts::STATUS_DRAFT;

        $result = $this->payout->cancel($user);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // reopen tests
    // =========================================================================

    public function testReopenReturnsZeroIfAlreadyValidated(): void
    {
        global $user;
        $this->payout->status = \Stancer_payouts::STATUS_VALIDATED;

        $result = $this->payout->reopen($user);

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // deleteLine tests
    // =========================================================================

    public function testDeleteLineReturnsErrorIfStatusNegative(): void
    {
        global $user;
        $this->payout->status = -1;

        $result = $this->payout->deleteLine($user, 1);

        $this->assertEquals(-2, $result);
        $this->assertEquals('ErrorDeleteLineNotAllowedByObjectStatus', $this->payout->error);
    }

    // =========================================================================
    // showOutputField tests
    // =========================================================================

    public function testShowOutputFieldFormatsAmountInCents(): void
    {
        $val = ['type' => 'integer'];
        $result = $this->payout->showOutputField($val, 'amount', 10050);

        $this->assertEquals('100.50', $result);
    }

    public function testShowOutputFieldFormatsFeesInCents(): void
    {
        $val = ['type' => 'integer'];
        $result = $this->payout->showOutputField($val, 'fees', 250);

        $this->assertEquals('2.50', $result);
    }

    public function testShowOutputFieldCalculatesAmountNet(): void
    {
        $this->payout->amount = 10000;
        $this->payout->fees = 100;

        $val = ['type' => 'integer'];
        $result = $this->payout->showOutputField($val, 'amount_net', 0);

        $this->assertEquals('99.00', $result);
    }

    public function testShowOutputFieldFormatsPayoutIdAsLink(): void
    {
        $val = ['type' => 'varchar'];
        $result = $this->payout->showOutputField($val, 'payout_id', 'pout_test123');

        $this->assertStringContainsString('pout_test123', $result);
        $this->assertStringContainsString('href=', $result);
        $this->assertStringContainsString('stancer.com', $result);
    }

    // =========================================================================
    // fillDataFromApi() tests
    // =========================================================================

    public function testFillDataFromApiWithFullPayoutData(): void
    {
        $apiData = \StancerApiFixtures::payoutFullApiResponse();

        $result = $this->payout->fillDataFromApi($apiData);

        $this->assertEquals(0, $result);
        $this->assertEquals('pout_1A2B3C4D5E6F', $this->payout->payout_id);
        $this->assertEquals(95000, $this->payout->amount);
        $this->assertEquals(5000, $this->payout->fees);
        $this->assertEquals(90000, $this->payout->amount_net);
        $this->assertEquals('eur', $this->payout->currency);
        $this->assertEquals(\Stancer_payouts::STATUS_SENT, $this->payout->status);
        $this->assertEquals(1704153600, $this->payout->date_bank);
        $this->assertEquals(1704240000, $this->payout->date_paym);
        $this->assertEquals('STANCER PAYOUT', $this->payout->statement_description);
    }

    public function testFillDataFromApiCalculatesAmountNet(): void
    {
        $apiData = [
            'id' => 'pout_test',
            'payments' => ['amount' => 10000],
            'fees' => 250,
            'currency' => 'eur',
        ];

        $this->payout->fillDataFromApi($apiData);

        $this->assertEquals(10000, $this->payout->amount);
        $this->assertEquals(250, $this->payout->fees);
        $this->assertEquals(9750, $this->payout->amount_net);
    }

    public function testFillDataFromApiUsesTotalWhenPaymentsAmountMissing(): void
    {
        $apiData = [
            'id' => 'pout_test',
            'total' => 5000,
            'fees' => 100,
            'currency' => 'eur',
        ];

        $this->payout->fillDataFromApi($apiData);

        $this->assertEquals(5000, $this->payout->amount);
        $this->assertEquals(4900, $this->payout->amount_net);
    }

    public function testFillDataFromApiDefaultsFeesToZero(): void
    {
        $apiData = [
            'id' => 'pout_test',
            'payments' => ['amount' => 8000],
            'currency' => 'eur',
        ];

        $this->payout->fillDataFromApi($apiData);

        $this->assertEquals(0, $this->payout->fees);
        $this->assertEquals(8000, $this->payout->amount_net);
    }

    public function testFillDataFromApiSetsLiveModeFromConf(): void
    {
        global $conf;
        $conf->global->STANCER_IS_PROD = '1';

        $apiData = \StancerApiFixtures::payoutFullApiResponse();
        $this->payout->fillDataFromApi($apiData);

        $this->assertEquals('1', $this->payout->live_mode);

        unset($conf->global->STANCER_IS_PROD);
    }

    // =========================================================================
    // fillDataFromApi() with disputes/refunds (bug: currently ignored)
    // =========================================================================

    /**
     * The net amount actually received on the bank account is API field "amount".
     * It already accounts for payments + refunds + disputes - fees - fees_vat.
     * The module must use it as amount_net (not payments.amount - fees).
     */
    public function testFillDataFromApiUsesApiAmountAsNetWhenDisputesExist(): void
    {
        $apiData = \StancerApiFixtures::payoutWithDisputesApiResponse();

        $this->payout->fillDataFromApi($apiData);

        $this->assertEquals(18138, $this->payout->amount_net, 'amount_net must equal API "amount" field (real bank transfer amount)');
    }

    /**
     * Gross amount (before fees) must reflect the real settlement:
     * payments.amount + refunds.amount + disputes.amount
     * = 20400 + 0 + (-1200) = 19200
     */
    public function testFillDataFromApiStoresGrossAmountIncludingDisputesAndRefunds(): void
    {
        $apiData = \StancerApiFixtures::payoutWithDisputesApiResponse();

        $this->payout->fillDataFromApi($apiData);

        $expectedGross = 20400 + 0 + (-1200);
        $this->assertEquals($expectedGross, $this->payout->amount, 'amount must include refunds and disputes, not only payments.amount');
    }

    /**
     * Disputes data must be persisted so the list page / bookkeeping can
     * explain the difference between payments.amount and real net.
     */
    public function testFillDataFromApiPersistsDisputesDetails(): void
    {
        $apiData = \StancerApiFixtures::payoutWithDisputesApiResponse();

        $this->payout->fillDataFromApi($apiData);

        $this->assertNotEmpty($this->payout->disputes, 'disputes field must not be empty when API returns disputes');
        $decoded = is_array($this->payout->disputes) ? $this->payout->disputes : json_decode($this->payout->disputes, true);
        $this->assertIsArray($decoded);
        $this->assertEquals(-1200, $decoded['amount']);
    }

    /**
     * Refunds data must be persisted (even empty) so the list page stays
     * consistent.
     */
    public function testFillDataFromApiPersistsRefundsDetails(): void
    {
        $apiData = \StancerApiFixtures::payoutWithDisputesApiResponse();

        $this->payout->fillDataFromApi($apiData);

        $this->assertNotNull($this->payout->refunds, 'refunds field must not be null when API returns refunds block');
        $decoded = is_array($this->payout->refunds) ? $this->payout->refunds : json_decode($this->payout->refunds, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('amount', $decoded);
    }

    /**
     * Fees must include dispute fees (they are already summed in api "fees").
     */
    public function testFillDataFromApiStoresFullFeesIncludingDisputeFees(): void
    {
        $apiData = \StancerApiFixtures::payoutWithDisputesApiResponse();

        $this->payout->fillDataFromApi($apiData);

        $this->assertEquals(885, $this->payout->fees);
    }

    /**
     * fees_vat must be persisted separately so monthly supplier invoice
     * reconciliation (TTC) can match fees + fees_vat.
     */
    public function testFillDataFromApiPersistsFeesVat(): void
    {
        $apiData = \StancerApiFixtures::payoutWithDisputesApiResponse();

        $this->payout->fillDataFromApi($apiData);

        $this->assertEquals(177, $this->payout->fees_vat);
    }

    public function testFillDataFromApiDefaultsFeesVatToZero(): void
    {
        $apiData = [
            'id' => 'pout_test',
            'payments' => ['amount' => 8000],
            'fees' => 100,
            'currency' => 'eur',
        ];

        $this->payout->fillDataFromApi($apiData);

        $this->assertEquals(0, $this->payout->fees_vat);
    }

    /**
     * Invariant: amount_net must equal amount - fees - fees_vat (with
     * amount = gross settlement including disputes/refunds).
     */
    public function testFillDataFromApiNetAmountInvariantHolds(): void
    {
        $apiData = \StancerApiFixtures::payoutWithDisputesApiResponse();

        $this->payout->fillDataFromApi($apiData);

        $feesVat = isset($apiData['fees_vat']) ? $apiData['fees_vat'] : 0;
        $this->assertEquals(
            $this->payout->amount - $this->payout->fees - $feesVat,
            $this->payout->amount_net,
            'amount_net = amount - fees - fees_vat must hold'
        );
    }

    // =========================================================================
    // LibStatut() tests
    // =========================================================================

    /**
     * @dataProvider libStatutProvider
     */
    public function testLibStatutReturnsCorrectLabel(int $status, string $expectedLabel): void
    {
        $result = $this->payout->LibStatut($status);
        $this->assertEquals($expectedLabel, $result);
    }

    public static function libStatutProvider(): array
    {
        return [
            'draft' => [0, 'Draft'],
            'pending' => [1, 'StancerPending'],
            'to_pay' => [2, 'StancerToPay'],
            'sent' => [3, 'StancerCaptureSent'],
            'paid' => [4, 'StancerPaid'],
            'failed' => [5, 'StancerFailed'],
        ];
    }

    public function testGetLabelStatusDelegatesToLibStatut(): void
    {
        $this->payout->status = \Stancer_payouts::STATUS_PAID;
        $this->assertEquals('StancerPaid', $this->payout->getLabelStatus());
    }

    public function testGetLibStatutDelegatesToLibStatut(): void
    {
        $this->payout->status = \Stancer_payouts::STATUS_PENDING;
        $this->assertEquals('StancerPending', $this->payout->getLibStatut());
    }

    // =========================================================================
    // getLibResponse() tests
    // =========================================================================

    public function testGetLibResponseWithEmptyResponse(): void
    {
        $this->payout->response = '';
        $result = $this->payout->getLibResponse();
        $this->assertEquals('', $result);
    }

    // =========================================================================
    // initAsSpecimen() tests
    // =========================================================================

    public function testInitAsSpecimenCallsWithoutError(): void
    {
        $this->payout->initAsSpecimen();
        $this->assertTrue(true);
    }

    // =========================================================================
    // getNomUrl() tests (Cat 5 - standard Dolibarr methods)
    // =========================================================================

    public function testGetNomUrlReturnsLinkWithRef(): void
    {
        $this->payout->id = 10;
        $this->payout->ref = 'POUT-001';
        $this->payout->status = \Stancer_payouts::STATUS_SENT;

        $result = $this->payout->getNomUrl();

        $this->assertStringContainsString('POUT-001', $result);
        $this->assertStringContainsString('href=', $result);
        $this->assertStringContainsString('stancer_payouts_card.php', $result);
        $this->assertStringContainsString('id=10', $result);
    }

    public function testGetNomUrlWithNoLinkReturnsSpan(): void
    {
        $this->payout->id = 10;
        $this->payout->ref = 'POUT-001';

        $result = $this->payout->getNomUrl(0, 'nolink');

        $this->assertStringContainsString('POUT-001', $result);
        $this->assertStringContainsString('<span', $result);
        $this->assertStringNotContainsString('<a ', $result);
    }

    public function testGetNomUrlWithPictoIncludesImg(): void
    {
        $this->payout->id = 10;
        $this->payout->ref = 'POUT-001';
        $this->payout->status = \Stancer_payouts::STATUS_DRAFT;

        $result = $this->payout->getNomUrl(1);

        $this->assertStringContainsString('POUT-001', $result);
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
            ->with($this->stringContains('stancer_stancer_payouts'))
            ->willReturn(true);
        $db->expects($this->once())
            ->method('num_rows')
            ->willReturn(0);
        $db->expects($this->once())
            ->method('free');

        $payout = new \Stancer_payouts($db);
        $payout->info(10);
    }

    // =========================================================================
    // getLinesArray() tests (Cat 5 - standard Dolibarr methods)
    // =========================================================================

    public function testGetLinesArrayReturnsEmptyArray(): void
    {
        $this->payout->id = 1;
        $result = $this->payout->getLinesArray();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // generateDocument() tests (Cat 5 - standard Dolibarr methods)
    // =========================================================================

    public function testGenerateDocumentReturnsZeroWhenDocgenDisabled(): void
    {
        global $langs;
        $result = $this->payout->generateDocument('', $langs);

        $this->assertEquals(0, $result);
    }
}
