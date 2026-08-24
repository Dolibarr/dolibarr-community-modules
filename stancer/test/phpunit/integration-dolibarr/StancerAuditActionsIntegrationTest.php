<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Integration tests for the audit action functions:
 *  - stancerAuditFix       : moves the local Paiement -> correct invoice
 *  - stancerAuditIgnore    : creates an AC_STANCER_AUDIT_IGNORE ActionComm
 *  - stancerAuditFetchIgnoredPaymIds : reads them back
 *
 * Each test seeds a Stancer Paiement (raw SQL into llx_paiement +
 * llx_paiement_facture), mocks the Stancer API, calls the action, and
 * inspects the resulting DB state.
 */
class StancerAuditActionsIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupHttpMock();
        $this->configureStancerSettings();

        dol_include_once('/stancer/lib/stancer.lib.php');
        dol_include_once('/stancer/lib/stancer_audit.lib.php');

        require_once __DIR__ . '/../../../class/stancer_api.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

        // Clean any prior actioncomm rows from previous tests so the
        // "already ignored" idempotency check stays deterministic.
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "actioncomm WHERE code IN ('"
            . STANCER_AUDIT_AC_IGNORE . "', '" . STANCER_AUDIT_AC_REATTACH . "')");
    }

    protected function tearDown(): void
    {
        $this->teardownHttpMock();
        parent::tearDown();
    }

    private function buildValidatedInvoice(\Societe $soc, float $amountHT): \Facture
    {
        $invoice = new \Facture($this->db);
        $invoice->socid = $soc->id;
        $invoice->date = dol_now();
        $invoice->create($this->testUser);
        $invoice->addline('Test line', $amountHT, 1, 0, 0, 0, 0, 0, '', '', 0, 0, '', 'HT');
        $invoice->fetch($invoice->id);
        $invoice->validate($this->testUser);
        $invoice->fetch($invoice->id);
        return $invoice;
    }

    /**
     * Insert a Stancer-tagged Dolibarr Paiement attached to $invoice.
     * Returns the rowid of the inserted llx_paiement row.
     */
    private function seedStancerPaiement(\Facture $invoice, string $paymId, float $amount): int
    {
        $now = $this->db->idate(dol_now());
        $sqlPay = "INSERT INTO " . MAIN_DB_PREFIX . "paiement"
            . " (datec, datep, amount, fk_paiement, num_paiement, ext_payment_site, ext_payment_id, entity)"
            . " VALUES ('" . $now . "', '" . $now . "', " . (float) $amount . ", 6, '"
            . $this->db->escape($paymId) . "', 'stancer', '" . $this->db->escape($paymId) . "', 1)";
        $this->db->query($sqlPay);
        $paiementId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX . "paiement");

        $sqlPf = "INSERT INTO " . MAIN_DB_PREFIX . "paiement_facture"
            . " (fk_paiement, fk_facture, amount) VALUES ("
            . $paiementId . ", " . (int) $invoice->id . ", " . (float) $amount . ")";
        $this->db->query($sqlPf);

        return $paiementId;
    }

    private function mockApiPayment(string $paymId, array $apiPayload): void
    {
        \HttpMock::addJsonResponse('*checkout/' . $paymId . '*', array_merge([
            'id'        => $paymId,
            'amount'    => 1200,
            'currency'  => 'eur',
            'status'    => 'captured',
            'method'    => 'card',
            'unique_id' => '',
            'order_id'  => '',
            'response'  => '00',
            'customer'  => ['id' => '', 'name' => ''],
        ], $apiPayload));
    }

    private function fetchPfFkFacture(int $paiementId): int
    {
        $sql = "SELECT fk_facture FROM " . MAIN_DB_PREFIX . "paiement_facture"
            . " WHERE fk_paiement = " . $paiementId . " LIMIT 1";
        $res = $this->db->query($sql);
        $row = $this->db->fetch_object($res);
        return $row ? (int) $row->fk_facture : 0;
    }

    private function countActionComm(string $code, string $extraparamsPaymId): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "actioncomm"
            . " WHERE code = '" . $this->db->escape($code) . "'"
            . " AND extraparams = '" . $this->db->escape($extraparamsPaymId) . "'";
        $res = $this->db->query($sql);
        $row = $this->db->fetch_object($res);
        return (int) $row->cnt;
    }

    // =========================================================================
    // stancerAuditFix happy path: paym attached to wrong invoice -> moved to
    // the invoice indicated by the Stancer API order_id.
    // =========================================================================
    public function testFixMovesPaiementToCorrectInvoice(): void
    {
        $soc = $this->createTestSociete(['name' => 'FixHappy']);
        $wrongInv = $this->buildValidatedInvoice($soc, 12.00);
        $rightInv = $this->buildValidatedInvoice($soc, 12.00);

        $paymId = 'paym_fix_happy_' . uniqid();
        $paiementId = $this->seedStancerPaiement($wrongInv, $paymId, 12.00);

        $this->mockApiPayment($paymId, [
            'amount'   => 1200,
            'order_id' => $rightInv->ref,
            'customer' => ['id' => 'cust_test', 'name' => 'FixHappy'],
        ]);

        $api = new \StancerApi();
        $res = stancerAuditFix($paiementId, $this->db, $this->testUser, $api);

        $this->assertTrue($res['success'], 'Fix must succeed: ' . $res['message']);
        $this->assertEquals($wrongInv->ref, $res['old_invoice_ref']);
        $this->assertEquals($rightInv->ref, $res['new_invoice_ref']);

        $this->assertEquals(
            (int) $rightInv->id,
            $this->fetchPfFkFacture($paiementId),
            'paiement_facture must now point to the right invoice'
        );

        // One ActionComm per invoice (old + new).
        $this->assertEquals(
            2,
            $this->countActionComm(STANCER_AUDIT_AC_REATTACH, $paymId),
            'A REATTACH ActionComm must be created on both invoices'
        );
    }

    // =========================================================================
    // stancerAuditFix refuses when the target invoice (api order_id) does not
    // exist in Dolibarr.
    // =========================================================================
    public function testFixRefusesWhenTargetInvoiceDoesNotExist(): void
    {
        $soc = $this->createTestSociete(['name' => 'FixNoTarget']);
        $wrongInv = $this->buildValidatedInvoice($soc, 12.00);

        $paymId = 'paym_fix_notarget_' . uniqid();
        $paiementId = $this->seedStancerPaiement($wrongInv, $paymId, 12.00);

        $this->mockApiPayment($paymId, [
            'amount'   => 1200,
            'order_id' => 'FA-DOES-NOT-EXIST',
            'customer' => ['id' => 'cust_test', 'name' => 'FixNoTarget'],
        ]);

        $api = new \StancerApi();
        $res = stancerAuditFix($paiementId, $this->db, $this->testUser, $api);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('Target invoice not found', $res['message']);
        // pf row must not have moved.
        $this->assertEquals((int) $wrongInv->id, $this->fetchPfFkFacture($paiementId));
    }

    // =========================================================================
    // stancerAuditFix refuses when the API order_id already matches the
    // local invoice ref (no-op, the audit must have been stale).
    // =========================================================================
    public function testFixRefusesWhenAlreadyCorrect(): void
    {
        $soc = $this->createTestSociete(['name' => 'FixAlreadyOk']);
        $inv = $this->buildValidatedInvoice($soc, 5.00);

        $paymId = 'paym_already_' . uniqid();
        $paiementId = $this->seedStancerPaiement($inv, $paymId, 5.00);

        $this->mockApiPayment($paymId, [
            'amount'   => 500,
            'order_id' => $inv->ref,
            'customer' => ['id' => 'cust_test', 'name' => 'FixAlreadyOk'],
        ]);

        $api = new \StancerApi();
        $res = stancerAuditFix($paiementId, $this->db, $this->testUser, $api);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('already attached', $res['message']);
    }

    // =========================================================================
    // stancerAuditFix can move across customers (wrong-customer case).
    // =========================================================================
    public function testFixMovesAcrossCustomers(): void
    {
        $nitd = $this->createTestSociete(['name' => 'NITD-FixAcross']);
        $bhg  = $this->createTestSociete(['name' => 'BHG-FixAcross']);

        $nitdInv = $this->buildValidatedInvoice($nitd, 12.00);
        $bhgInv  = $this->buildValidatedInvoice($bhg, 12.00);

        $paymId = 'paym_across_' . uniqid();
        $paiementId = $this->seedStancerPaiement($nitdInv, $paymId, 12.00);

        $this->mockApiPayment($paymId, [
            'amount'   => 1200,
            'order_id' => $bhgInv->ref,
            'customer' => ['id' => 'cust_bhg', 'name' => 'BHG'],
        ]);

        $api = new \StancerApi();
        $res = stancerAuditFix($paiementId, $this->db, $this->testUser, $api);

        $this->assertTrue($res['success'], 'Fix across customers must succeed: ' . $res['message']);
        $this->assertEquals((int) $bhgInv->id, $this->fetchPfFkFacture($paiementId));
    }

    // =========================================================================
    // stancerAuditIgnore creates the ActionComm and the result is visible to
    // stancerAuditFetchIgnoredPaymIds().
    // =========================================================================
    public function testIgnoreCreatesActionCommAndIsRetrievable(): void
    {
        $soc = $this->createTestSociete(['name' => 'IgnoreCreates']);
        $inv = $this->buildValidatedInvoice($soc, 7.20);

        $paymId = 'paym_ignore_' . uniqid();
        $paiementId = $this->seedStancerPaiement($inv, $paymId, 7.20);

        $res = stancerAuditIgnore($paiementId, $this->db, $this->testUser, 'filiale');

        $this->assertTrue($res['success']);
        $this->assertEquals($paymId, $res['paym_id']);
        $this->assertEquals(1, $this->countActionComm(STANCER_AUDIT_AC_IGNORE, $paymId));

        $ignored = stancerAuditFetchIgnoredPaymIds($this->db);
        $this->assertArrayHasKey($paymId, $ignored);
    }

    // =========================================================================
    // stancerAuditIgnore is idempotent: 2 calls = 1 ActionComm row only.
    // =========================================================================
    public function testIgnoreIsIdempotent(): void
    {
        $soc = $this->createTestSociete(['name' => 'IgnoreIdem']);
        $inv = $this->buildValidatedInvoice($soc, 7.20);

        $paymId = 'paym_idem_' . uniqid();
        $paiementId = $this->seedStancerPaiement($inv, $paymId, 7.20);

        stancerAuditIgnore($paiementId, $this->db, $this->testUser);
        $res2 = stancerAuditIgnore($paiementId, $this->db, $this->testUser);

        $this->assertTrue($res2['success']);
        $this->assertEquals('Already ignored', $res2['message']);
        $this->assertEquals(1, $this->countActionComm(STANCER_AUDIT_AC_IGNORE, $paymId));
    }
}
