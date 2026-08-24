<?php

namespace Stancer\Tests\IntegrationDolibarr;

/**
 * Integration tests for Stancer_payouts class with real database
 */
class StancerPayoutsIntegrationTest extends DolibarrRealTestCase
{
    // =========================================================================
    // CRUD Tests
    // =========================================================================

    public function testCreatePayout(): void
    {
        $payout = $this->createTestPayout([
            'payout_id' => 'pout_integration_test_001',
            'amount' => 50000,
        ]);

        $this->assertGreaterThan(0, $payout->id);
        $this->assertDatabaseHas('stancer_stancer_payouts', [
            'payout_id' => 'pout_integration_test_001',
        ]);
    }

    public function testFetchPayout(): void
    {
        $created = $this->createTestPayout([
            'payout_id' => 'pout_fetch_test',
            'amount' => 75000,
            'fees' => 150,
        ]);

        require_once __DIR__ . '/../../../class/stancer_payouts.class.php';

        $fetched = new \Stancer_payouts($this->db);
        $result = $fetched->fetch($created->id);

        $this->assertGreaterThan(0, $result);
        $this->assertEquals('pout_fetch_test', $fetched->payout_id);
        $this->assertEquals(75000, $fetched->amount);
        $this->assertEquals(150, $fetched->fees);
    }

    public function testUpdatePayout(): void
    {
        $payout = $this->createTestPayout([
            'payout_id' => 'pout_update_test',
            'amount' => 10000,
            'status' => \Stancer_payouts::STATUS_DRAFT,
        ]);

        $payout->fetch($payout->id);
        $payout->fees = 50;
        $result = $payout->update($this->testUser);

        $this->assertGreaterThanOrEqual(0, $result);

        $check = new \Stancer_payouts($this->db);
        $check->fetch($payout->id);

        $this->assertEquals(50, $check->fees);
    }

    public function testDeletePayout(): void
    {
        $payout = $this->createTestPayout([
            'payout_id' => 'pout_delete_test',
        ]);

        $id = $payout->id;
        $result = $payout->delete($this->testUser);

        $this->assertGreaterThan(0, $result);
        $this->assertDatabaseMissing('stancer_stancer_payouts', ['rowid' => $id]);
    }

    // =========================================================================
    // Status Tests
    // =========================================================================

    public function testPayoutStatusCanBeUpdated(): void
    {
        $payout = $this->createTestPayout([
            'status' => \Stancer_payouts::STATUS_DRAFT,
        ]);

        $payout->fetch($payout->id);

        // Update status directly
        $payout->status = \Stancer_payouts::STATUS_SENT;
        $result = $payout->update($this->testUser);

        $this->assertGreaterThanOrEqual(0, $result);

        $payout->fetch($payout->id);
        $this->assertEquals(\Stancer_payouts::STATUS_SENT, $payout->status);
    }

    // =========================================================================
    // Amount Calculations
    // =========================================================================

    public function testPayoutAmountNetCalculation(): void
    {
        $payout = $this->createTestPayout([
            'amount' => 10000,
            'fees' => 100,
        ]);

        $payout->fetch($payout->id);

        // amount_net = amount - fees (in cents)
        $expectedNet = 10000 - 100;
        $this->assertEquals($expectedNet, $payout->amount - $payout->fees);
    }

    // =========================================================================
    // Status Transition Tests (validate/setDraft/cancel/reopen)
    // =========================================================================

    public function testValidateChangesStatusToValidated(): void
    {
        $payout = $this->createTestPayout([
            'payout_id' => 'pout_validate_test',
            'status' => \Stancer_payouts::STATUS_DRAFT,
        ]);
        $payout->fetch($payout->id);

        $result = $payout->validate($this->testUser, 1);

        $this->assertEquals(1, $result);

        $check = new \Stancer_payouts($this->db);
        $check->fetch($payout->id);
        $this->assertEquals(\Stancer_payouts::STATUS_VALIDATED, $check->status);
    }

    public function testSetDraftChangesStatusToDraft(): void
    {
        $payout = $this->createTestPayout([
            'payout_id' => 'pout_setdraft_test',
            'status' => \Stancer_payouts::STATUS_SENT,
        ]);
        $payout->fetch($payout->id);

        $result = $payout->setDraft($this->testUser, 1);

        $this->assertGreaterThanOrEqual(1, $result);

        $check = new \Stancer_payouts($this->db);
        $check->fetch($payout->id);
        $this->assertEquals(\Stancer_payouts::STATUS_DRAFT, $check->status);
    }

    public function testCancelChangesStatusToCanceled(): void
    {
        $payout = $this->createTestPayout([
            'payout_id' => 'pout_cancel_test',
            'status' => \Stancer_payouts::STATUS_VALIDATED,
        ]);
        $payout->fetch($payout->id);

        $result = $payout->cancel($this->testUser, 1);

        $this->assertGreaterThanOrEqual(1, $result);

        $check = new \Stancer_payouts($this->db);
        $check->fetch($payout->id);
        $this->assertEquals(\Stancer_payouts::STATUS_CANCELED, $check->status);
    }

    public function testReopenChangesStatusToValidated(): void
    {
        $payout = $this->createTestPayout([
            'payout_id' => 'pout_reopen_test',
            'status' => \Stancer_payouts::STATUS_DRAFT,
        ]);
        $payout->fetch($payout->id);

        $result = $payout->reopen($this->testUser, 1);

        $this->assertGreaterThanOrEqual(1, $result);

        $check = new \Stancer_payouts($this->db);
        $check->fetch($payout->id);
        $this->assertEquals(\Stancer_payouts::STATUS_VALIDATED, $check->status);
    }

    // =========================================================================
    // FetchAll Tests
    // =========================================================================

    public function testFetchAllPayouts(): void
    {
        $this->createTestPayout(['payout_id' => 'pout_list_1', 'amount' => 10000]);
        $this->createTestPayout(['payout_id' => 'pout_list_2', 'amount' => 20000]);
        $this->createTestPayout(['payout_id' => 'pout_list_3', 'amount' => 30000]);

        require_once __DIR__ . '/../../../class/stancer_payouts.class.php';

        $payout = new \Stancer_payouts($this->db);
        $list = $payout->fetchAll();

        $this->assertIsArray($list);
        $this->assertGreaterThanOrEqual(3, count($list));
    }

    public function testFetchAllPayoutsWithLimit(): void
    {
        $this->createTestPayout(['payout_id' => 'pout_lim_1', 'amount' => 10000]);
        $this->createTestPayout(['payout_id' => 'pout_lim_2', 'amount' => 20000]);
        $this->createTestPayout(['payout_id' => 'pout_lim_3', 'amount' => 30000]);

        require_once __DIR__ . '/../../../class/stancer_payouts.class.php';

        $payout = new \Stancer_payouts($this->db);
        $list = $payout->fetchAll('', '', 2);

        $this->assertIsArray($list);
        $this->assertCount(2, $list);
    }

    public function testFetchAllPayoutsWithFilter(): void
    {
        $this->createTestPayout(['payout_id' => 'pout_filter_1', 'amount' => 10000, 'currency' => 'eur']);
        $this->createTestPayout(['payout_id' => 'pout_filter_2', 'amount' => 20000, 'currency' => 'usd']);

        require_once __DIR__ . '/../../../class/stancer_payouts.class.php';

        $payout = new \Stancer_payouts($this->db);
        $list = $payout->fetchAll('', '', 0, 0, ['customsql' => "t.currency = 'eur'"]);

        $this->assertIsArray($list);
        foreach ($list as $item) {
            $this->assertEquals('eur', $item->currency);
        }
    }

    // =========================================================================
    // fillDataFromApi() -> DB roundtrip Tests
    // =========================================================================

    public function testFillDataFromApiFullRoundtrip(): void
    {
        $payout = $this->createTestPayout([
            'payout_id' => 'pout_api_roundtrip',
            'amount' => 0,
            'fees' => 0,
            'status' => \Stancer_payouts::STATUS_DRAFT,
        ]);
        $payout->fetch($payout->id);

        $apiData = \StancerApiFixtures::payoutFullApiResponse();
        $result = $payout->fillDataFromApi($apiData);
        $payout->update($this->testUser);

        $this->assertEquals(0, $result);

        // Verify all fields persisted to DB
        $check = new \Stancer_payouts($this->db);
        $check->fetch($payout->id);

        $this->assertEquals('pout_1A2B3C4D5E6F', $check->payout_id);
        $this->assertEquals(95000, $check->amount);
        $this->assertEquals(5000, $check->fees);
        $this->assertEquals(90000, $check->amount_net);
        $this->assertEquals('eur', $check->currency);
        $this->assertEquals(\Stancer_payouts::STATUS_SENT, $check->status);
        $this->assertEquals(1704153600, $check->date_bank);
        $this->assertEquals(1704240000, $check->date_paym);
        $this->assertEquals('STANCER PAYOUT', $check->statement_description);
    }

    public function testFillDataFromApiCalculatesAmountNetInDb(): void
    {
        $payout = $this->createTestPayout([
            'payout_id' => 'pout_net_calc',
            'amount' => 0,
            'fees' => 0,
        ]);
        $payout->fetch($payout->id);

        $apiData = [
            'id' => 'pout_net_calc',
            'payments' => ['amount' => 50000],
            'fees' => 1500,
            'currency' => 'eur',
            'status' => 'sent',
        ];
        $payout->fillDataFromApi($apiData);
        $payout->update($this->testUser);

        $check = new \Stancer_payouts($this->db);
        $check->fetch($payout->id);

        $this->assertEquals(50000, $check->amount);
        $this->assertEquals(1500, $check->fees);
        $this->assertEquals(48500, $check->amount_net);
    }

    // =========================================================================
    // fillDataArray() -> update -> fetch roundtrip
    // =========================================================================

    public function testFillDataArrayStatusConversionPersistedToDb(): void
    {
        $payout = $this->createTestPayout([
            'payout_id' => 'pout_status_conv',
        ]);
        $payout->fetch($payout->id);

        $payout->fillDataArray(['status' => 'paid']);
        $payout->update($this->testUser);

        $check = new \Stancer_payouts($this->db);
        $check->fetch($payout->id);

        $this->assertEquals(\Stancer_payouts::STATUS_PAID, $check->status);
    }

    public function testFillDataArrayJsonEncodesArrayFieldInDb(): void
    {
        $payout = $this->createTestPayout([
            'payout_id' => 'pout_json_array',
        ]);
        $payout->fetch($payout->id);

        $details = ['paym_abc', 'paym_def'];
        $payout->fillDataArray(['details' => $details]);
        $payout->update($this->testUser);

        $check = new \Stancer_payouts($this->db);
        $check->fetch($payout->id);

        $this->assertEquals(json_encode($details), $check->details);
    }

    // =========================================================================
    // convert_status_code() roundtrip
    // =========================================================================

    public function testConvertStatusCodeAllStringStatusesPersistedToDb(): void
    {
        $statuses = [
            'pending' => \Stancer_payouts::STATUS_PENDING,
            'to_pay' => \Stancer_payouts::STATUS_TO_PAY,
            'sent' => \Stancer_payouts::STATUS_SENT,
            'paid' => \Stancer_payouts::STATUS_PAID,
            'failed' => \Stancer_payouts::STATUS_FAILED,
        ];

        foreach ($statuses as $apiStatus => $expectedCode) {
            $payout = $this->createTestPayout([
                'payout_id' => 'pout_status_' . $apiStatus,
            ]);
            $payout->fetch($payout->id);
            $payout->fillDataArray(['status' => $apiStatus]);
            $payout->update($this->testUser);

            $check = new \Stancer_payouts($this->db);
            $check->fetch($payout->id);

            $this->assertEquals($expectedCode, $check->status,
                "Status '$apiStatus' should map to code $expectedCode");
        }
    }
}
