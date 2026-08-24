<?php

namespace Stancer\Tests\IntegrationDolibarr;

/**
 * Integration test for the re-runnable data migration in modStancer::init() that
 * tags legacy Stancer payments recorded with an empty ext_payment_site (the old
 * command-path posting bug).
 *
 * Contract: a Dolibarr Paiement whose num_paiement OR ext_payment_id matches a known
 * stancer_id (llx_stancer_stancer_payments) and whose ext_payment_site is empty/NULL
 * must be re-tagged 'stancer'. Rows already tagged (stripe/mollie/stancer) or not
 * matching a known stancer_id must be left untouched. Idempotent.
 */
class StancerLegacyPaymentTaggingIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../../class/stancer_payments.class.php';
        require_once __DIR__ . '/../../../core/modules/modStancer.class.php';
    }

    public function testInitTagsLegacyUntaggedStancerPaymentsOnly(): void
    {
        $cbId = (int) dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1);

        // Two known Stancer payments (mirror rows).
        $sid1 = 'paym_legacy_' . uniqid();
        $sid2 = 'paym_legacy2_' . uniqid();
        $this->seedStancerPayment($sid1);
        $this->seedStancerPayment($sid2);

        // A) untagged, num_paiement matches a known stancer_id -> must become 'stancer'.
        $idA = $this->insertPaiement($cbId, $sid1, $sid1, '');
        // B) untagged, only ext_payment_id matches a known stancer_id -> must become 'stancer'.
        $idB = $this->insertPaiement($cbId, 'INTERNAL-B', $sid2, '');
        // C) untagged cheque, no stancer_id match -> must stay ''.
        $idC = $this->insertPaiement($cbId, 'CHQ-000123', '', '');
        // D) already tagged for another provider -> must stay 'stripe'.
        $idD = $this->insertPaiement($cbId, 'pi_stripe_x', 'pi_stripe_x', 'stripe');
        // E) a paym_-looking id that is NOT a known stancer_id -> must stay '' (we only
        //    tag ids present in llx_stancer_stancer_payments, never by prefix guessing).
        $idE = $this->insertPaiement($cbId, 'paym_unknown_' . uniqid(), '', '');

        // init() also re-runs schema/document_model inserts (harmless duplicates on a
        // second run); its return code is not the contract under test here. What matters
        // is the re-runnable tagging migration it performs.
        (new \modStancer($this->db))->init();

        $this->assertEquals('stancer', $this->siteOf($idA), 'A: num_paiement matching a stancer_id must be tagged stancer');
        $this->assertEquals('stancer', $this->siteOf($idB), 'B: ext_payment_id matching a stancer_id must be tagged stancer');
        $this->assertEquals('', $this->siteOf($idC), 'C: a non-Stancer cheque must stay untagged');
        $this->assertEquals('stripe', $this->siteOf($idD), 'D: an already-tagged provider row must be left untouched');
        $this->assertEquals('', $this->siteOf($idE), 'E: an unknown paym_ id (not in the mirror table) must not be tagged');
    }

    public function testMigrationIsIdempotent(): void
    {
        $cbId = (int) dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1);
        $sid = 'paym_idem_' . uniqid();
        $this->seedStancerPayment($sid);
        $id = $this->insertPaiement($cbId, $sid, $sid, '');

        (new \modStancer($this->db))->init();
        $this->assertEquals('stancer', $this->siteOf($id));

        // Second run must leave the row tagged (idempotent, matches nothing new).
        (new \modStancer($this->db))->init();
        $this->assertEquals('stancer', $this->siteOf($id));
    }

    // -------------------------------------------------------------------------

    private function seedStancerPayment(string $stancerId): void
    {
        $sp = new \Stancer_payments($this->db);
        $sp->stancer_id = $stancerId;
        $sp->amount = 12000;
        $sp->currency = 'eur';
        $sp->status = \Stancer_payments::STATUS_CAPTURED;
        $sp->method = 'card';
        $sp->description = 'Legacy tag test';
        $sp->customer = 'cust_tag_' . uniqid();
        $sp->live_mode = 1;
        $sp->order_id = '';
        $res = $sp->create($this->testUser);
        $this->assertGreaterThan(0, $res, 'seedStancerPayment failed: ' . $sp->error);
    }

    private function insertPaiement(int $cbId, string $numPaiement, string $extId, string $extSite): int
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "paiement"
            . " (entity, datep, amount, fk_paiement, num_paiement, ext_payment_id, ext_payment_site, statut)"
            . " VALUES (1, '" . $this->db->idate(dol_now()) . "', 120.00, " . $cbId . ","
            . " '" . $this->db->escape($numPaiement) . "',"
            . " '" . $this->db->escape($extId) . "',"
            . " '" . $this->db->escape($extSite) . "', 1)";
        $res = $this->db->query($sql);
        $this->assertNotFalse($res, 'insertPaiement failed: ' . $this->db->lasterror());
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . "paiement");
    }

    private function siteOf(int $paiementId): string
    {
        $res = $this->db->query("SELECT ext_payment_site FROM " . MAIN_DB_PREFIX . "paiement WHERE rowid = " . (int) $paiementId);
        $this->assertNotFalse($res);
        $obj = $this->db->fetch_object($res);
        return $obj ? (string) $obj->ext_payment_site : '__missing__';
    }
}
