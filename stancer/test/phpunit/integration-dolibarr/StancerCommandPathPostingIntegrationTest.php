<?php

namespace Stancer\Tests\IntegrationDolibarr;

/**
 * Integration tests for audit finding E4: the "commande" path of
 * public/paymentback.php creates the Dolibarr Paiement with an EMPTY
 * ext_payment_site (line 957) instead of 'stancer'.
 *
 * The reconciliation detector stancerFindCapturedPaymentsNotPosted() flags a
 * captured Stancer payment as "unposted" when no llx_paiement row has BOTH
 * num_paiement = stancer_id AND ext_payment_site = 'stancer'. So a command-path
 * posting (empty site) is invisible: the captured payment is surfaced as a
 * force-post candidate -> a SECOND payment (double).
 *
 * These tests prove the mechanism (empty site hides the posting, 'stancer'
 * covers it) and assert that the real paymentback.php command path tags the
 * payment as 'stancer'.
 */
class StancerCommandPathPostingIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../class/stancer_payments.class.php';
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
        dol_include_once('/stancer/lib/stancer.lib.php');
        dol_include_once('/stancer/lib/stancer_repair.lib.php');
    }

    // =========================================================================
    // Detection is now identity-based (num_paiement/ext_payment_id = paym id),
    // independent of ext_payment_site: an empty-site command-path posting (the old
    // E4 bug) is recognized as posted and is NOT surfaced as a false double.
    // =========================================================================
    public function testEmptyExtPaymentSiteStillCoveredByIdentity(): void
    {
        $soc = $this->createTestSociete(['name' => 'E4Empty']);
        $invoice = $this->buildValidatedInvoice($soc, 50.00);
        $stancerId = 'paym_e4empty_' . uniqid();
        $this->seedCapturedStancerPayment($stancerId, (int) $soc->id);

        // Command path posts the payment but with ext_payment_site='' (E4 bug).
        // num_paiement still carries the paym id, so identity detection covers it.
        $this->seedCommandPathPayment($invoice, $stancerId, '');

        $ids = $this->unpostedStancerIds();
        $this->assertNotContains(
            $stancerId,
            $ids,
            'An empty-site posting whose num_paiement is the paym id must be recognized as posted (no false double)'
        );
    }

    // =========================================================================
    // With the fixed value ('stancer'), the same posting covers the captured
    // payment: it is NOT surfaced as unposted, so no double is proposed.
    // =========================================================================
    public function testStancerExtPaymentSiteCoversTheCommandPathPosting(): void
    {
        $soc = $this->createTestSociete(['name' => 'E4Stancer']);
        $invoice = $this->buildValidatedInvoice($soc, 50.00);
        $stancerId = 'paym_e4stancer_' . uniqid();
        $this->seedCapturedStancerPayment($stancerId, (int) $soc->id);

        $this->seedCommandPathPayment($invoice, $stancerId, 'stancer');

        $ids = $this->unpostedStancerIds();
        $this->assertNotContains(
            $stancerId,
            $ids,
            'A stancer-tagged posting must cover the captured payment (no double)'
        );
    }

    // =========================================================================
    // The real code: the command path must tag the payment as 'stancer'.
    // Red before the E4 fix (line 957 sets ''), green after.
    // =========================================================================
    public function testPaymentbackCommandPathTagsPaymentAsStancer(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/public/paymentback.php');
        $this->assertNotFalse($src, 'Cannot read public/paymentback.php');

        // Locate the command-path Paiement: it sets num_payment from the Stancer
        // session id (line 954), then ext_payment_site shortly after (line 957).
        $ok = preg_match(
            '/num_payment\s*=\s*\$_SESSION\["stancer_payment_id"\];(.*?)ext_payment_site\s*=\s*(\x27[^\x27]*\x27)/s',
            $src,
            $m
        );
        $this->assertSame(1, $ok, 'Could not locate the command-path ext_payment_site assignment');
        $this->assertSame(
            "'stancer'",
            $m[2],
            'E4: the command-path payment must be tagged ext_payment_site=stancer, not empty'
        );
    }

    // =========================================================================
    // Identity detection via ext_payment_id: even when num_paiement differs, a
    // paiement whose ext_payment_id is the paym id covers the captured payment.
    // =========================================================================
    public function testExtPaymentIdIdentityCoversTheCapturedPayment(): void
    {
        $soc = $this->createTestSociete(['name' => 'E4ExtId']);
        $invoice = $this->buildValidatedInvoice($soc, 30.00);
        $stancerId = 'paym_e4extid_' . uniqid();
        $this->seedCapturedStancerPayment($stancerId, (int) $soc->id);

        // num_paiement is something else, but ext_payment_id carries the paym id.
        $p = new \Paiement($this->db);
        $p->datepaye = dol_now();
        $p->amounts = array($invoice->id => (float) $invoice->total_ttc);
        $p->paiementid = (int) dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1);
        $p->num_payment = 'BANKREF-' . uniqid();
        $p->ext_payment_id = $stancerId;
        $p->ext_payment_site = '';
        $pid = $p->create($this->testUser);
        $this->assertGreaterThan(0, $pid, 'seed ext-id payment failed: ' . $p->error);

        $ids = $this->unpostedStancerIds();
        $this->assertNotContains(
            $stancerId,
            $ids,
            'A paiement whose ext_payment_id is the paym id must cover the captured payment (no false double)'
        );
    }

    // -------------------------------------------------------------------------

    private function unpostedStancerIds(): array
    {
        $rows = stancerFindCapturedPaymentsNotPosted($this->db, true);
        return array_map(function ($r) {
            return $r->stancer_id;
        }, $rows);
    }

    private function seedCapturedStancerPayment(string $stancerId, int $socid): \Stancer_payments
    {
        $sp = new \Stancer_payments($this->db);
        $sp->stancer_id = $stancerId;
        $sp->amount = 5000;
        $sp->currency = 'eur';
        $sp->status = \Stancer_payments::STATUS_CAPTURED;
        $sp->method = 'card';
        $sp->description = 'E4 test';
        $sp->customer = 'cust_e4_' . uniqid();
        $sp->fk_soc = $socid;
        $sp->live_mode = 1;
        $sp->unique_id = 'E4_' . uniqid();
        $sp->order_id = '';
        $res = $sp->create($this->testUser);
        $this->assertGreaterThan(0, $res, 'seedCapturedStancerPayment failed: ' . $sp->error);
        return $sp;
    }

    /**
     * Reproduce what the paymentback.php command path builds (lines 943-960):
     * a Paiement whose num_payment is the Stancer id and whose ext_payment_site
     * is the value under test ('' = bug, 'stancer' = fixed).
     */
    private function seedCommandPathPayment(\Facture $invoice, string $stancerId, string $extSite): void
    {
        $p = new \Paiement($this->db);
        $p->datepaye = dol_now();
        $p->amounts = array($invoice->id => (float) $invoice->total_ttc);
        $p->paiementid = (int) dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1);
        $p->num_payment = $stancerId;                 // paymentback.php line 954
        $p->ext_payment_id = 'stancer_txn_' . uniqid(); // line 956
        $p->ext_payment_site = $extSite;              // line 957 (bug '' / fix 'stancer')
        $pid = $p->create($this->testUser);
        $this->assertGreaterThan(0, $pid, 'seedCommandPathPayment failed: ' . $p->error);
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
}
