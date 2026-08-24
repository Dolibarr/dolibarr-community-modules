<?php

namespace Stancer\Tests\IntegrationDolibarr;

/**
 * Integration test for audit finding E3: payment identity had no UNIQUE
 * constraint, so two concurrent refresh/paymentback runs could both pass the
 * SELECT count(*) idempotence check and INSERT the same Stancer payment,
 * over-paying the invoice and double-crediting the bank.
 *
 * A UNIQUE index on (entity, stancer_id) is the real barrier. This test creates
 * that index (the bootstrap skips *.key.sql) and proves a second insert of the
 * same stancer_id is rejected by the DB, not silently duplicated.
 */
class StancerPaymentUniquenessIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../../class/stancer_payments.class.php';

        // The bootstrap loads llx_*.sql but skips *.key.sql, so the E3 unique
        // index is absent in tests. Create it here to exercise the real
        // constraint (mirror of sql/update_001_06.sql).
        $this->db->query(
            "CREATE UNIQUE INDEX IF NOT EXISTS uk_stancer_stancer_payments_stancer_id"
            . " ON " . MAIN_DB_PREFIX . "stancer_stancer_payments (entity, stancer_id)"
        );
    }

    protected function tearDown(): void
    {
        $this->db->query("DROP INDEX IF EXISTS uk_stancer_stancer_payments_stancer_id");
        parent::tearDown();
    }

    public function testDuplicateStancerIdIsRejectedByUniqueIndex(): void
    {
        $stancerId = 'paym_uniq_' . uniqid();

        $first = $this->makePayment($stancerId);
        $res1 = $first->create($this->testUser);
        $this->assertGreaterThan(0, $res1, 'First insert must succeed: ' . $first->error);

        // A second row with the SAME stancer_id (same entity) must be rejected by
        // the unique index (return <= 0 or throw), never silently duplicated.
        $second = $this->makePayment($stancerId);
        $res2 = 0;
        $threw = false;
        try {
            $res2 = @$second->create($this->testUser);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue(
            $threw || $res2 <= 0,
            'E3: a second insert of the same stancer_id must be rejected by the unique index'
        );

        // Exactly one row exists for that identity.
        $sql = "SELECT COUNT(*) AS c FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments"
            . " WHERE stancer_id = '" . $this->db->escape($stancerId) . "'";
        $r = $this->db->query($sql);
        $this->assertEquals(1, (int) $this->db->fetch_object($r)->c, 'Only one row must exist for the stancer_id');
    }

    private function makePayment(string $stancerId): \Stancer_payments
    {
        $sp = new \Stancer_payments($this->db);
        $sp->stancer_id = $stancerId;
        $sp->amount = 1000;
        $sp->currency = 'eur';
        $sp->status = \Stancer_payments::STATUS_CAPTURED;
        $sp->method = 'card';
        $sp->description = 'E3 uniqueness test';
        $sp->fk_soc = 0;
        $sp->live_mode = 1;
        $sp->unique_id = 'E3_' . uniqid();
        $sp->order_id = '';
        return $sp;
    }
}
