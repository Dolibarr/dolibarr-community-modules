<?php

namespace Stancer\Tests\IntegrationDolibarr;

/**
 * Integration tests for Stancer_payments class with real database
 */
class StancerPaymentsIntegrationTest extends DolibarrRealTestCase
{
    // =========================================================================
    // CRUD Tests
    // =========================================================================

    public function testCreatePayment(): void
    {
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_integration_test_001',
            'amount' => 5000,
            'description' => 'Integration test payment',
        ]);

        $this->assertGreaterThan(0, $payment->id);
        $this->assertDatabaseHas('stancer_stancer_payments', [
            'stancer_id' => 'paym_integration_test_001',
        ]);
    }

    public function testFetchPayment(): void
    {
        $created = $this->createTestPayment([
            'stancer_id' => 'paym_fetch_test',
            'amount' => 3000,
        ]);

        require_once __DIR__ . '/../../../class/stancer_payments.class.php';

        $fetched = new \Stancer_payments($this->db);
        $result = $fetched->fetch($created->id);

        $this->assertGreaterThan(0, $result);
        $this->assertEquals('paym_fetch_test', $fetched->stancer_id);
        $this->assertEquals(3000, $fetched->amount);
    }

    public function testUpdatePayment(): void
    {
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_update_test',
            'amount' => 1000,
        ]);

        // Fetch to get all fields
        $payment->fetch($payment->id);

        // Update
        $payment->amount = 2000;
        $payment->description = 'Updated description';
        $result = $payment->update($this->testUser);

        $this->assertGreaterThanOrEqual(0, $result);

        // Verify update
        $check = new \Stancer_payments($this->db);
        $check->fetch($payment->id);

        $this->assertEquals(2000, $check->amount);
        $this->assertEquals('Updated description', $check->description);
    }

    public function testDeletePayment(): void
    {
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_delete_test',
        ]);

        $id = $payment->id;
        $result = $payment->delete($this->testUser);

        $this->assertGreaterThan(0, $result);
        $this->assertDatabaseMissing('stancer_stancer_payments', ['rowid' => $id]);
    }

    // =========================================================================
    // Status Tests
    // =========================================================================

    public function testPaymentStatusCanBeUpdated(): void
    {
        $payment = $this->createTestPayment([
            'status' => \Stancer_payments::STATUS_DRAFT,
        ]);

        $payment->fetch($payment->id);

        // Update status directly (validate() requires 'ref' column which may not exist)
        $payment->status = \Stancer_payments::STATUS_CAPTURED;
        $result = $payment->update($this->testUser);

        $this->assertGreaterThanOrEqual(0, $result);

        $payment->fetch($payment->id);
        $this->assertEquals(\Stancer_payments::STATUS_CAPTURED, $payment->status);
    }

    // =========================================================================
    // Relationship Tests
    // =========================================================================

    public function testPaymentLinkedToInvoice(): void
    {
        // Create a company
        $soc = $this->createTestSociete(['name' => 'Payment Test Company']);

        // Create an invoice
        $invoice = $this->createTestInvoice($soc);

        // Create payment linked to invoice
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_invoice_linked',
            'fk_facture' => $invoice->id,
            'fk_soc' => $soc->id,
        ]);

        $this->assertGreaterThan(0, $payment->id);
        $this->assertEquals($invoice->id, $payment->fk_facture);
        $this->assertEquals($soc->id, $payment->fk_soc);
    }

    // =========================================================================
    // Filter/Search Tests
    // =========================================================================

    public function testFetchAllPayments(): void
    {
        // Create multiple payments
        $this->createTestPayment(['stancer_id' => 'paym_list_1', 'amount' => 1000]);
        $this->createTestPayment(['stancer_id' => 'paym_list_2', 'amount' => 2000]);
        $this->createTestPayment(['stancer_id' => 'paym_list_3', 'amount' => 3000]);

        require_once __DIR__ . '/../../../class/stancer_payments.class.php';

        $payment = new \Stancer_payments($this->db);
        $list = $payment->fetchAll();

        $this->assertIsArray($list);
        $this->assertGreaterThanOrEqual(3, count($list));
    }

    // =========================================================================
    // Status Transition Tests (validate/setDraft/cancel/reopen)
    // =========================================================================

    public function testValidateChangesStatusToValidated(): void
    {
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_validate_test',
            'status' => \Stancer_payments::STATUS_DRAFT,
        ]);
        $payment->fetch($payment->id);

        $result = $payment->validate($this->testUser, 1);

        $this->assertEquals(1, $result);

        $check = new \Stancer_payments($this->db);
        $check->fetch($payment->id);
        $this->assertEquals(\Stancer_payments::STATUS_VALIDATED, $check->status);
    }

    public function testSetDraftChangesStatusToDraft(): void
    {
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_setdraft_test',
            'status' => \Stancer_payments::STATUS_CAPTURED,
        ]);
        $payment->fetch($payment->id);

        $result = $payment->setDraft($this->testUser, 1);

        $this->assertGreaterThanOrEqual(1, $result);

        $check = new \Stancer_payments($this->db);
        $check->fetch($payment->id);
        $this->assertEquals(\Stancer_payments::STATUS_DRAFT, $check->status);
    }

    public function testCancelChangesStatusToCanceled(): void
    {
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_cancel_test',
            'status' => \Stancer_payments::STATUS_VALIDATED,
        ]);
        $payment->fetch($payment->id);

        $result = $payment->cancel($this->testUser, 1);

        $this->assertGreaterThanOrEqual(1, $result);

        $check = new \Stancer_payments($this->db);
        $check->fetch($payment->id);
        $this->assertEquals(\Stancer_payments::STATUS_CANCELED, $check->status);
    }

    public function testReopenChangesStatusToValidated(): void
    {
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_reopen_test',
            'status' => \Stancer_payments::STATUS_DRAFT,
        ]);
        $payment->fetch($payment->id);

        $result = $payment->reopen($this->testUser, 1);

        $this->assertGreaterThanOrEqual(1, $result);

        $check = new \Stancer_payments($this->db);
        $check->fetch($payment->id);
        $this->assertEquals(\Stancer_payments::STATUS_VALIDATED, $check->status);
    }

    // =========================================================================
    // Fetch by stancer_id / unique_id Tests
    // =========================================================================

    public function testFetchPaymentByStancerId(): void
    {
        $this->createTestPayment([
            'stancer_id' => 'paym_fetch_by_sid_123',
            'amount' => 4200,
        ]);

        require_once __DIR__ . '/../../../class/stancer_payments.class.php';

        $fetched = new \Stancer_payments($this->db);
        $result = $fetched->fetch(0, null, 'paym_fetch_by_sid_123');

        $this->assertGreaterThan(0, $result);
        $this->assertEquals('paym_fetch_by_sid_123', $fetched->stancer_id);
        $this->assertEquals(4200, $fetched->amount);
    }

    public function testFetchAllPaymentsWithFilter(): void
    {
        $this->createTestPayment(['stancer_id' => 'paym_filter_eur', 'amount' => 1000, 'currency' => 'eur']);
        $this->createTestPayment(['stancer_id' => 'paym_filter_usd', 'amount' => 2000, 'currency' => 'usd']);

        require_once __DIR__ . '/../../../class/stancer_payments.class.php';

        $payment = new \Stancer_payments($this->db);
        $list = $payment->fetchAll('', '', 0, 0, ['customsql' => "t.currency = 'eur'"]);

        $this->assertIsArray($list);
        foreach ($list as $item) {
            $this->assertEquals('eur', $item->currency);
        }
    }

    // =========================================================================
    // fillDataFromApi() Tests - expanded objects (DB_ERROR_1406 fix)
    // =========================================================================

    public function testFillDataFromApiExtractsCustomerIdFromExpandedObject(): void
    {
        $soc = $this->createTestSociete(['name' => 'Expanded Customer Test']);

        // Create a company payment mode with stancer_account matching the fixture
        $this->createTestCompanyPaymentMode($soc, [
            'stancer_account' => 'cust_9TycuMPH3xsPVE0n02IrI3L3',
        ]);

        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_expand_cust',
            'unique_id' => 'CUS=1478.INV=7597',
        ]);
        $payment->fetch($payment->id);

        $apiData = \StancerApiFixtures::paymentWithExpandedObjects();
        $result = $payment->fillDataFromApi($apiData);
        $payment->update($this->testUser);

        // Verify in DB: customer should be the ID string, not the full JSON object
        $check = new \Stancer_payments($this->db);
        $check->fetch($payment->id);

        $this->assertEquals(0, $result);
        $this->assertEquals('cust_9TycuMPH3xsPVE0n02IrI3L3', $check->customer);
        $this->assertLessThanOrEqual(30, strlen($check->customer),
            'customer field must fit in varchar(30)');
    }

    public function testFillDataFromApiExtractsCardIdFromExpandedObject(): void
    {
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_expand_card',
            'unique_id' => 'CUS=1478.INV=7597',
        ]);
        $payment->fetch($payment->id);

        $apiData = \StancerApiFixtures::paymentWithExpandedObjects();
        $payment->fillDataFromApi($apiData);
        $payment->update($this->testUser);

        $check = new \Stancer_payments($this->db);
        $check->fetch($payment->id);

        $this->assertEquals('card_xognFbZs935LMKJp', $check->card);
        $this->assertLessThanOrEqual(30, strlen($check->card),
            'card field must fit in varchar(30)');
    }

    public function testFillDataFromApiExtractsSepaIdFromExpandedObject(): void
    {
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_expand_sepa',
            'unique_id' => 'CUS=1478.INV=7597',
        ]);
        $payment->fetch($payment->id);

        $apiData = \StancerApiFixtures::paymentWithExpandedObjects();
        $payment->fillDataFromApi($apiData);
        $payment->update($this->testUser);

        $check = new \Stancer_payments($this->db);
        $check->fetch($payment->id);

        $this->assertEquals('sepa_ABC123456789', $check->sepa);
        $this->assertLessThanOrEqual(30, strlen($check->sepa),
            'sepa field must fit in varchar(30)');
    }

    public function testFillDataFromApiKeepsStringIds(): void
    {
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_string_ids',
            'unique_id' => 'CUS=1478.INV=7597',
        ]);
        $payment->fetch($payment->id);

        $apiData = \StancerApiFixtures::paymentWithStringIds();
        $payment->fillDataFromApi($apiData);
        $payment->update($this->testUser);

        $check = new \Stancer_payments($this->db);
        $check->fetch($payment->id);

        $this->assertEquals('cust_9TycuMPH3xsPVE0n02IrI3L3', $check->customer);
        $this->assertEquals('card_xognFbZs935LMKJp', $check->card);
        $this->assertEquals('sepa_ABC123456789', $check->sepa);
    }

    public function testFillDataFromApiMapsAllFieldsToDb(): void
    {
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_full_map',
            'unique_id' => 'CUS=1478.INV=7597',
        ]);
        $payment->fetch($payment->id);

        $apiData = \StancerApiFixtures::paymentWithExpandedObjects();
        $payment->fillDataFromApi($apiData);
        $payment->update($this->testUser);

        $check = new \Stancer_payments($this->db);
        $check->fetch($payment->id);

        $this->assertEquals('paym_4kM8Kv5X0HJ8vLqF', $check->stancer_id);
        $this->assertEquals(2500, $check->amount);
        $this->assertEquals('eur', $check->currency);
        $this->assertEquals('card', $check->method);
        $this->assertEquals('00', $check->response);
        $this->assertEquals(\Stancer_payments::STATUS_CAPTURED, $check->status);
    }

    // =========================================================================
    // fillDataArray() -> update -> fetch roundtrip
    // =========================================================================

    public function testFillDataArrayStatusConversionPersistedToDb(): void
    {
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_status_conv',
        ]);
        $payment->fetch($payment->id);

        $payment->fillDataArray(['status' => 'captured']);
        $payment->update($this->testUser);

        $check = new \Stancer_payments($this->db);
        $check->fetch($payment->id);

        $this->assertEquals(\Stancer_payments::STATUS_CAPTURED, $check->status);
    }

    public function testFillDataArrayJsonEncodesArrayFieldInDb(): void
    {
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_json_array',
        ]);
        $payment->fetch($payment->id);

        $refundsData = ['rfnd_abc', 'rfnd_def'];
        $payment->fillDataArray(['refunds' => $refundsData]);
        $payment->update($this->testUser);

        $check = new \Stancer_payments($this->db);
        $check->fetch($payment->id);

        $this->assertEquals(json_encode($refundsData), $check->refunds);
    }

    // =========================================================================
    // convert_status_code() roundtrip
    // =========================================================================

    public function testConvertStatusCodeAllStringStatusesPersistedToDb(): void
    {
        $statuses = [
            'authorized' => \Stancer_payments::STATUS_AUTHORIZED,
            'captured' => \Stancer_payments::STATUS_CAPTURED,
            'disputed' => \Stancer_payments::STATUS_DISPUTED,
            'failed' => \Stancer_payments::STATUS_FAILED,
            'refused' => \Stancer_payments::STATUS_REFUSED,
        ];

        foreach ($statuses as $apiStatus => $expectedCode) {
            $payment = $this->createTestPayment([
                'stancer_id' => 'paym_status_' . $apiStatus,
            ]);
            $payment->fetch($payment->id);
            $payment->fillDataArray(['status' => $apiStatus]);
            $payment->update($this->testUser);

            $check = new \Stancer_payments($this->db);
            $check->fetch($payment->id);

            $this->assertEquals($expectedCode, $check->status,
                "Status '$apiStatus' should map to code $expectedCode");
        }
    }
}
