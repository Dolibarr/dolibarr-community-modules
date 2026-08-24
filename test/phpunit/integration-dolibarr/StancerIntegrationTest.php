<?php

namespace Stancer\Tests\IntegrationDolibarr;

/**
 * Integration tests for Stancer module with real Dolibarr
 */
class StancerIntegrationTest extends DolibarrRealTestCase
{
    public function testDolibarrIsInitialized(): void
    {
        $this->assertNotNull($this->db, 'Database connection should be available');
        $this->assertNotNull($this->testUser, 'Test user should be available');
        $this->assertGreaterThan(0, $this->testUser->id, 'Test user should have valid ID');
    }

    public function testCanCreateSociete(): void
    {
        $soc = $this->createTestSociete(['name' => 'Stancer Test Company']);

        $this->assertGreaterThan(0, $soc->id);
        $this->assertDatabaseHas('societe', ['nom' => 'Stancer Test Company']);
    }

    public function testStancerClassWithRealDb(): void
    {
        require_once __DIR__ . '/../../../class/stancer.class.php';

        $stancer = new \Stancer($this->db);

        $this->assertInstanceOf(\Stancer::class, $stancer);
        $this->assertSame($this->db, $stancer->db);
    }

    public function testCanCreateInvoiceForThirdparty(): void
    {
        $soc = $this->createTestSociete(['name' => 'Invoice Test Company']);
        $invoice = $this->createTestInvoice($soc);

        $this->assertGreaterThan(0, $invoice->id);
        $this->assertEquals($soc->id, $invoice->socid);
    }
}
