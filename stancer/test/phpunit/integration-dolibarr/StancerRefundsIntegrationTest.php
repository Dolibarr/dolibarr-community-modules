<?php

namespace Stancer\Tests\IntegrationDolibarr;

/**
 * Integration tests for Stancer_refunds class with real database
 */
class StancerRefundsIntegrationTest extends DolibarrRealTestCase
{
    // =========================================================================
    // CRUD Tests
    // =========================================================================

    public function testCreateRefund(): void
    {
        $refund = $this->createTestRefund([
            'refund_id' => 'rfnd_integration_test_001',
            'payment_id' => 'paym_test_001',
            'amount' => 1000,
        ]);

        $this->assertGreaterThan(0, $refund->id);
        $this->assertDatabaseHas('stancer_stancer_refunds', [
            'refund_id' => 'rfnd_integration_test_001',
        ]);
    }

    public function testFetchRefund(): void
    {
        $created = $this->createTestRefund([
            'refund_id' => 'rfnd_fetch_test',
            'payment_id' => 'paym_fetch_linked',
            'amount' => 2500,
        ]);

        require_once __DIR__ . '/../../../class/stancer_refunds.class.php';

        $fetched = new \Stancer_refunds($this->db);
        $result = $fetched->fetch($created->id);

        $this->assertGreaterThan(0, $result);
        $this->assertEquals('rfnd_fetch_test', $fetched->refund_id);
        $this->assertEquals('paym_fetch_linked', $fetched->payment_id);
        $this->assertEquals(2500, $fetched->amount);
    }

    public function testUpdateRefund(): void
    {
        $refund = $this->createTestRefund([
            'refund_id' => 'rfnd_update_test',
            'amount' => 500,
        ]);

        // Fetch to get all fields
        $refund->fetch($refund->id);

        // Update
        $refund->amount = 750;
        $refund->currency = 'usd';
        $result = $refund->update($this->testUser);

        $this->assertGreaterThanOrEqual(0, $result);

        // Verify update
        $check = new \Stancer_refunds($this->db);
        $check->fetch($refund->id);

        $this->assertEquals(750, $check->amount);
        $this->assertEquals('usd', $check->currency);
    }

    public function testDeleteRefund(): void
    {
        $refund = $this->createTestRefund([
            'refund_id' => 'rfnd_delete_test',
        ]);

        $id = $refund->id;
        $result = $refund->delete($this->testUser);

        $this->assertGreaterThan(0, $result);
        $this->assertDatabaseMissing('stancer_stancer_refunds', ['rowid' => $id]);
    }

    // =========================================================================
    // Status Tests
    // =========================================================================

    public function testRefundStatusCanBeUpdated(): void
    {
        $refund = $this->createTestRefund([
            'status' => \Stancer_refunds::STATUS_DRAFT,
        ]);

        $refund->fetch($refund->id);

        // Update status directly (validate() requires 'ref' column which may not exist)
        $refund->status = \Stancer_refunds::STATUS_VALIDATED;
        $result = $refund->update($this->testUser);

        $this->assertGreaterThanOrEqual(0, $result);

        $refund->fetch($refund->id);
        $this->assertEquals(\Stancer_refunds::STATUS_VALIDATED, $refund->status);
    }

    // =========================================================================
    // Relationship Tests
    // =========================================================================

    public function testRefundLinkedToPayment(): void
    {
        // Create a company
        $soc = $this->createTestSociete(['name' => 'Refund Test Company']);

        // Create a payment first
        $payment = $this->createTestPayment([
            'stancer_id' => 'paym_for_refund',
            'amount' => 5000,
            'fk_soc' => $soc->id,
        ]);

        // Create refund linked to payment
        $refund = $this->createTestRefund([
            'refund_id' => 'rfnd_linked_to_payment',
            'payment_id' => $payment->stancer_id,
            'amount' => 1000,
            'fk_soc' => $soc->id,
        ]);

        $this->assertGreaterThan(0, $refund->id);
        $this->assertEquals($payment->stancer_id, $refund->payment_id);
        $this->assertEquals($soc->id, $refund->fk_soc);
    }

    // =========================================================================
    // Filter/Search Tests
    // =========================================================================

    public function testFetchAllRefunds(): void
    {
        // Create multiple refunds
        $this->createTestRefund(['refund_id' => 'rfnd_list_1', 'amount' => 100]);
        $this->createTestRefund(['refund_id' => 'rfnd_list_2', 'amount' => 200]);
        $this->createTestRefund(['refund_id' => 'rfnd_list_3', 'amount' => 300]);

        require_once __DIR__ . '/../../../class/stancer_refunds.class.php';

        $refund = new \Stancer_refunds($this->db);
        $list = $refund->fetchAll();

        $this->assertIsArray($list);
        $this->assertGreaterThanOrEqual(3, count($list));
    }

    // =========================================================================
    // Status Transition Tests (validate/setDraft/cancel/reopen)
    // =========================================================================

    public function testValidateChangesStatusToValidated(): void
    {
        $refund = $this->createTestRefund([
            'refund_id' => 'rfnd_validate_test',
            'status' => \Stancer_refunds::STATUS_DRAFT,
        ]);
        $refund->fetch($refund->id);

        $result = $refund->validate($this->testUser, 1);

        $this->assertEquals(1, $result);

        $check = new \Stancer_refunds($this->db);
        $check->fetch($refund->id);
        $this->assertEquals(\Stancer_refunds::STATUS_VALIDATED, $check->status);
    }

    public function testSetDraftChangesStatusToDraft(): void
    {
        $refund = $this->createTestRefund([
            'refund_id' => 'rfnd_setdraft_test',
            'status' => \Stancer_refunds::STATUS_VALIDATED,
        ]);
        $refund->fetch($refund->id);

        $result = $refund->setDraft($this->testUser, 1);

        $this->assertGreaterThanOrEqual(1, $result);

        $check = new \Stancer_refunds($this->db);
        $check->fetch($refund->id);
        $this->assertEquals(\Stancer_refunds::STATUS_DRAFT, $check->status);
    }

    public function testCancelChangesStatusToCanceled(): void
    {
        $refund = $this->createTestRefund([
            'refund_id' => 'rfnd_cancel_test',
            'status' => \Stancer_refunds::STATUS_VALIDATED,
        ]);
        $refund->fetch($refund->id);

        $result = $refund->cancel($this->testUser, 1);

        $this->assertGreaterThanOrEqual(1, $result);

        $check = new \Stancer_refunds($this->db);
        $check->fetch($refund->id);
        $this->assertEquals(\Stancer_refunds::STATUS_CANCELED, $check->status);
    }

    public function testReopenChangesStatusToValidated(): void
    {
        $refund = $this->createTestRefund([
            'refund_id' => 'rfnd_reopen_test',
            'status' => \Stancer_refunds::STATUS_DRAFT,
        ]);
        $refund->fetch($refund->id);

        $result = $refund->reopen($this->testUser, 1);

        $this->assertGreaterThanOrEqual(1, $result);

        $check = new \Stancer_refunds($this->db);
        $check->fetch($refund->id);
        $this->assertEquals(\Stancer_refunds::STATUS_VALIDATED, $check->status);
    }
}
