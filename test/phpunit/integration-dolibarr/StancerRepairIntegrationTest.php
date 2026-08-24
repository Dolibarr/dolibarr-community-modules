<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Integration tests for the shared-customer detection & repair tool
 * (lib/stancer_repair.lib.php).
 *
 * Scenario reproduced: one cust_xxx wrongly linked to two distinct
 * thirdparties (the 2026-06 cross-customer leak). The repair must keep the
 * cust on its real owner and give the intruder its own distinct cust_xxx,
 * deleting the bogus societe_rib link and relabeling the intruder's payments.
 */
class StancerRepairIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupHttpMock();
        $this->configureStancerSettings();

        dol_include_once('/stancer/lib/stancer_repair.lib.php');

        require_once __DIR__ . '/../../../class/stancer_api.class.php';
        require_once __DIR__ . '/../../../class/stancer_payments.class.php';
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';

        // Reset the StancerApi singleton so the HTTP mock is honoured.
        $ref = new \ReflectionClass('StancerApi');
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $this->teardownHttpMock();
        parent::tearDown();
    }

    private function makeSociete(string $name, string $email): \Societe
    {
        $soc = $this->createTestSociete(['name' => $name]);
        $sql = "UPDATE " . MAIN_DB_PREFIX . "societe SET email = '" . $this->db->escape($email) . "'"
            . " WHERE rowid = " . (int) $soc->id;
        $this->db->query($sql);
        $soc->email = $email;
        return $soc;
    }

    private function seedPayment(int $socid, string $cust, int $amountCents = 1000): \Stancer_payments
    {
        $sp = new \Stancer_payments($this->db);
        $sp->stancer_id = 'paym_rep_' . uniqid();
        $sp->amount = $amountCents;
        $sp->currency = 'eur';
        $sp->status = \Stancer_payments::STATUS_CAPTURED;
        $sp->method = 'card';
        $sp->description = 'Repair test';
        $sp->customer = $cust;
        $sp->fk_soc = $socid;
        $sp->live_mode = 1;
        $sp->unique_id = 'CUS=' . $socid . '.UNIQ=' . uniqid();
        $sp->order_id = '';
        $res = $sp->create($this->testUser);
        $this->assertGreaterThan(0, $res, 'seedPayment failed: ' . $sp->error);
        return $sp;
    }

    private function countPaymentsForCustomer(int $socid, string $cust): int
    {
        $sql = "SELECT COUNT(*) AS c FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments"
            . " WHERE fk_soc = " . $socid . " AND customer = '" . $this->db->escape($cust) . "'";
        $res = $this->db->query($sql);
        return (int) $this->db->fetch_object($res)->c;
    }

    // =========================================================================
    // Detection: only custs linked to >= 2 socids are reported.
    // =========================================================================
    public function testFindSharedCustomerIds(): void
    {
        $shared = 'cust_shared_' . uniqid();
        $lonely = 'cust_lonely_' . uniqid();

        $socA = $this->makeSociete('OwnerA', 'a@example.test');
        $socB = $this->makeSociete('IntruderB', 'b@example.test');
        $socC = $this->makeSociete('LonelyC', 'c@example.test');

        $this->createTestCompanyPaymentMode($socA, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $shared]);
        $this->seedPayment((int) $socB->id, $shared);          // shared via payment
        $this->createTestCompanyPaymentMode($socC, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $lonely]);

        $ids = stancerFindSharedCustomerIds($this->db);

        $this->assertContains($shared, $ids, 'A cust on 2 socids must be reported');
        $this->assertNotContains($lonely, $ids, 'A cust on a single socid must NOT be reported');
    }

    // =========================================================================
    // Detail aggregates rib links + payment counts/amounts per socid.
    // =========================================================================
    public function testGetSharedCustomerDetailAggregates(): void
    {
        $cust = 'cust_detail_' . uniqid();
        $socA = $this->makeSociete('DetA', 'deta@example.test');
        $socB = $this->makeSociete('DetB', 'detb@example.test');

        $this->createTestCompanyPaymentMode($socA, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        $this->seedPayment((int) $socB->id, $cust, 1500);
        $this->seedPayment((int) $socB->id, $cust, 2500);

        $detail = stancerGetSharedCustomerDetail($cust, $this->db);

        $this->assertArrayHasKey((int) $socA->id, $detail['socids']);
        $this->assertArrayHasKey((int) $socB->id, $detail['socids']);
        $this->assertTrue($detail['socids'][(int) $socA->id]['in_rib']);
        $this->assertEquals(2, $detail['socids'][(int) $socB->id]['nb_payments']);
        $this->assertEqualsWithDelta(40.0, $detail['socids'][(int) $socB->id]['amount_total'], 0.001);
        $this->assertEquals('DetA', $detail['socids'][(int) $socA->id]['name']);
    }

    // =========================================================================
    // Owner resolution by email.
    // =========================================================================
    public function testResolveOwnerByEmail(): void
    {
        $cust = 'cust_owner_' . uniqid();
        $socA = $this->makeSociete('OwnerMatch', 'owner@match.test');
        $socB = $this->makeSociete('Intruder', 'someone@else.test');
        $this->createTestCompanyPaymentMode($socA, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        $this->seedPayment((int) $socB->id, $cust);

        $detail = stancerGetSharedCustomerDetail($cust, $this->db);

        $ok = stancerResolveSharedCustomerOwner($detail, 'owner@match.test');
        $this->assertTrue($ok['confident']);
        $this->assertEquals((int) $socA->id, $ok['owner_socid']);

        $none = stancerResolveSharedCustomerOwner($detail, 'nobody@here.test');
        $this->assertFalse($none['confident']);
        $this->assertEquals(0, $none['owner_socid']);

        $empty = stancerResolveSharedCustomerOwner($detail, '');
        $this->assertFalse($empty['confident']);
    }

    // =========================================================================
    // Dry-run must not touch the DB nor the API.
    // =========================================================================
    public function testRepairDryRunChangesNothing(): void
    {
        $cust = 'cust_dry_' . uniqid();
        $socOwner = $this->makeSociete('DryOwner', 'dryowner@x.test');
        $socIntruder = $this->makeSociete('DryIntruder', 'dryintruder@y.test');
        $this->createTestCompanyPaymentMode($socOwner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        $this->createTestCompanyPaymentMode($socIntruder, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        $this->seedPayment((int) $socIntruder->id, $cust);

        $api = new \StancerApi();
        $res = stancerRepairSharedCustomer($cust, (int) $socOwner->id, $this->db, $this->testUser, $api, true);

        $this->assertTrue($res['dry_run']);
        $this->assertCount(1, $res['actions']);
        $this->assertEquals('planned', $res['actions'][0]['status']);

        // Nothing changed: intruder still linked via rib and payment.
        $this->assertDatabaseHas('societe_rib', ['fk_soc' => (int) $socIntruder->id, 'stancer_account' => $cust]);
        $this->assertEquals(1, $this->countPaymentsForCustomer((int) $socIntruder->id, $cust));
    }

    // =========================================================================
    // Apply: the intruder gets a distinct cust, its bogus rib link is deleted,
    // its payments are relabeled; the owner keeps the shared cust untouched.
    // =========================================================================
    public function testRepairApplyDetachesIntruder(): void
    {
        $cust = 'cust_apply_' . uniqid();
        $newCust = 'cust_brandnew_' . uniqid();

        \HttpMock::addJsonResponse('*customers*', ['id' => $newCust, 'email' => 'applyintruder@y.test']);

        $socOwner = $this->makeSociete('ApplyOwner', 'applyowner@x.test');
        $socIntruder = $this->makeSociete('ApplyIntruder', 'applyintruder@y.test');

        $this->createTestCompanyPaymentMode($socOwner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        $this->createTestCompanyPaymentMode($socIntruder, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        $this->seedPayment((int) $socOwner->id, $cust);
        $this->seedPayment((int) $socIntruder->id, $cust);
        $this->seedPayment((int) $socIntruder->id, $cust);

        $api = new \StancerApi();
        $res = stancerRepairSharedCustomer($cust, (int) $socOwner->id, $this->db, $this->testUser, $api, false);

        $this->assertTrue($res['success'], 'Repair should succeed: ' . $res['message']);
        $this->assertCount(1, $res['actions']);
        $this->assertEquals('done', $res['actions'][0]['status']);
        $this->assertEquals($newCust, $res['actions'][0]['new_cust']);
        $this->assertEquals(1, $res['actions'][0]['rib_deleted']);

        // Intruder is now ANCHORED to its own distinct cust in societe_rib
        // (durable: untouched by the refresh), and the bogus link is gone.
        $this->assertDatabaseHas('societe_rib', ['fk_soc' => (int) $socIntruder->id, 'stancer_account' => $newCust]);
        $this->assertDatabaseMissing('societe_rib', ['fk_soc' => (int) $socIntruder->id, 'stancer_account' => $cust]);

        // Owner keeps the cust untouched.
        $this->assertDatabaseHas('societe_rib', ['fk_soc' => (int) $socOwner->id, 'stancer_account' => $cust]);

        // No longer detected as shared: the hardened detection ignores the
        // intruder's residual payments because it is anchored to its own cust.
        $this->assertNotContains($cust, stancerFindSharedCustomerIds($this->db));
    }

    // =========================================================================
    // Hardened detection: a residual payment carrying the shared cust on a tiers
    // already anchored to its OWN cust (post-separation, refresh-rewritten) must
    // NOT make the cust look shared again.
    // =========================================================================
    public function testDetectionIgnoresPaymentsOfAnchoredThirdparty(): void
    {
        $cust = 'cust_anchored_' . uniqid();
        $owner = $this->makeSociete('AnchOwner', 'anch@owner.test');
        $intruder = $this->makeSociete('AnchIntruder', 'anch@intruder.test');

        $this->createTestCompanyPaymentMode($owner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        // Intruder already separated: rib points to its own cust...
        $ownCust = 'cust_own_' . uniqid();
        $this->createTestCompanyPaymentMode($intruder, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $ownCust]);
        // ...but a residual payment still carries the shared cust (refresh value).
        $this->seedPayment((int) $intruder->id, $cust);

        $this->assertNotContains($cust, stancerFindSharedCustomerIds($this->db),
            'A residual payment on an anchored tiers must not re-flag the cust as shared');
    }

    // =========================================================================
    // Intruder with a bogus rib link but NO payment: just remove the link, no
    // Stancer customer creation (no API call). Reproduces MAC2 (#2241).
    // =========================================================================
    public function testRepairNoPaymentJustRemovesLink(): void
    {
        $cust = 'cust_nopay_' . uniqid();
        $owner = $this->makeSociete('NoPayOwner', 'nopayowner@x.test');
        $intruder = $this->makeSociete('NoPayIntruder', 'nopayintruder@y.test');
        $this->createTestCompanyPaymentMode($owner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        $this->createTestCompanyPaymentMode($intruder, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        // intruder has NO payment.

        // No HttpMock queued: a 0-payment intruder must NOT trigger a createCustomer.
        $api = new \StancerApi();
        $res = stancerRepairSharedCustomer($cust, (int) $owner->id, $this->db, $this->testUser, $api, false);

        $this->assertTrue($res['success'], 'Repair should succeed: ' . $res['message']);
        $this->assertEquals('done', $res['actions'][0]['status']);
        $this->assertEquals('(none)', $res['actions'][0]['new_cust']);
        $this->assertEquals(1, $res['actions'][0]['rib_deleted']);

        $this->assertDatabaseMissing('societe_rib', ['fk_soc' => (int) $intruder->id, 'stancer_account' => $cust]);
        $this->assertNotContains($cust, stancerFindSharedCustomerIds($this->db));
    }

    // =========================================================================
    // Intruder thirdparty no longer exists (merged/deleted): its orphan payments
    // must be reattached to the owner, not crash with "Cannot fetch thirdparty".
    // Reproduces the #604 case (thirdparty merged, stancer_payments left behind).
    // =========================================================================
    public function testRepairReattachesPaymentsOfMergedThirdparty(): void
    {
        $cust = 'cust_merged_' . uniqid();
        $owner = $this->makeSociete('MergedOwner', 'merged@owner.test');
        $this->createTestCompanyPaymentMode($owner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);

        // Create then hard-delete a thirdparty to get a guaranteed-dead socid,
        // then leave an orphan payment behind (as a merge would).
        $ghost = $this->createTestSociete(['name' => 'GhostMerged']);
        $ghostId = (int) $ghost->id;
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . $ghostId);
        $this->seedPayment($ghostId, $cust, 5000);

        // Sanity: shared (owner via rib, ghost via payment).
        $this->assertContains($cust, stancerFindSharedCustomerIds($this->db));

        $api = new \StancerApi();
        $res = stancerRepairSharedCustomer($cust, (int) $owner->id, $this->db, $this->testUser, $api, false);

        $this->assertTrue($res['success'], 'Repair should succeed for a merged thirdparty: ' . $res['message']);
        $this->assertCount(1, $res['actions']);
        $this->assertEquals('done', $res['actions'][0]['status']);
        $this->assertEquals(1, $res['actions'][0]['payments_relinked']);

        // The orphan payment is now attached to the owner; nothing left on the ghost.
        $this->assertEquals(0, $this->countPaymentsForCustomer($ghostId, $cust));
        $this->assertEquals(1, $this->countPaymentsForCustomer((int) $owner->id, $cust));

        // No longer shared.
        $this->assertNotContains($cust, stancerFindSharedCustomerIds($this->db));
    }

    // =========================================================================
    // Generic account with no email/+mobile (eg "CLIENT DIVERS"): no Stancer
    // customer can be created and the payment must stay on it; only the wrong
    // customer label is cleared. No API call must happen. Reproduces the VULCAIN
    // / CLIENT DIVERS #638 crash ("neither email nor +mobile").
    // =========================================================================
    public function testRepairClearsCustomerOnGenericAccount(): void
    {
        $cust = 'cust_generic_' . uniqid();
        $owner = $this->makeSociete('GenOwner', 'genowner@x.test');
        $this->createTestCompanyPaymentMode($owner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);

        // Generic account: createTestSociete sets neither email nor phone.
        $generic = $this->createTestSociete(['name' => 'CLIENT DIVERS']);
        $this->seedPayment((int) $generic->id, $cust, 240);

        $this->assertContains($cust, stancerFindSharedCustomerIds($this->db));

        // No HttpMock response queued on purpose: CASE C must NOT hit the API.
        $api = new \StancerApi();
        $res = stancerRepairSharedCustomer($cust, (int) $owner->id, $this->db, $this->testUser, $api, false);

        $this->assertTrue($res['success'], 'Generic-account repair should succeed: ' . $res['message']);
        $this->assertCount(1, $res['actions']);
        $this->assertEquals('done', $res['actions'][0]['status']);
        $this->assertEquals(1, $res['actions'][0]['payments_relinked']);

        // Customer label cleared, but the payment stays on the generic account.
        $this->assertEquals(0, $this->countPaymentsForCustomer((int) $generic->id, $cust));
        $sql = "SELECT COUNT(*) AS c FROM " . MAIN_DB_PREFIX . "stancer_stancer_payments WHERE fk_soc = " . (int) $generic->id;
        $resCount = $this->db->query($sql);
        $this->assertEquals(1, (int) $this->db->fetch_object($resCount)->c, 'Payment must stay on the generic account');

        $this->assertNotContains($cust, stancerFindSharedCustomerIds($this->db));
    }

    // =========================================================================
    // Listing of captured-but-not-posted payments (force-post candidates):
    // a captured payment with no llx_paiement is listed, a draft is not.
    // =========================================================================
    public function testFindCapturedPaymentsNotPosted(): void
    {
        $soc = $this->makeSociete('UnpostedSoc', 'unposted@x.test');
        $captured = $this->seedPayment((int) $soc->id, 'cust_unp_' . uniqid(), 1000); // CAPTURED, live_mode=1

        $draft = new \Stancer_payments($this->db);
        $draft->stancer_id  = 'paym_draft_' . uniqid();
        $draft->amount      = 500;
        $draft->currency    = 'eur';
        $draft->status      = \Stancer_payments::STATUS_DRAFT;
        $draft->method      = 'card';
        $draft->description = 'draft';
        $draft->customer    = 'cust_draft_' . uniqid();
        $draft->fk_soc      = (int) $soc->id;
        $draft->live_mode   = 1;
        $draft->unique_id   = 'DRAFT_' . uniqid();
        $draft->order_id    = '';
        $draft->create($this->testUser);

        $rows = stancerFindCapturedPaymentsNotPosted($this->db, true);
        $ids = array_map(function ($r) {
            return $r->stancer_id;
        }, $rows);

        $this->assertContains($captured->stancer_id, $ids, 'Captured unposted payment must be listed');
        $this->assertNotContains($draft->stancer_id, $ids, 'Draft payment must NOT be listed');
    }

    // =========================================================================
    // Force-post guards: refuse a non-paid status, and refuse when order_id does
    // not resolve to a Dolibarr invoice. (No DB write in either case.)
    // =========================================================================
    public function testForcePostRejectsNonPaidStatus(): void
    {
        \HttpMock::addJsonResponse('*checkout*', ['status' => 'refused', 'order_id' => 'FAxxx', 'amount' => 1000]);
        $api = new \StancerApi();
        $res = stancerForcePostPayment('paym_refused_' . uniqid(), $this->db, $this->testUser, $api);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('not a paid status', $res['message']);
    }

    public function testForcePostRejectsUnknownOrderId(): void
    {
        \HttpMock::addJsonResponse('*checkout*', ['status' => 'captured', 'order_id' => 'FA-NOPE-' . uniqid(), 'amount' => 1000]);
        $api = new \StancerApi();
        $res = stancerForcePostPayment('paym_noinv_' . uniqid(), $this->db, $this->testUser, $api);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('No Dolibarr invoice', $res['message']);
    }

    // =========================================================================
    // The "captured" list must reflect the REAL Stancer status: a payment refused
    // upstream (but still flagged captured locally) must be split out, not shown
    // as captured/double. Reproduces paym_wcBhDvO... (refused on Stancer).
    // =========================================================================
    public function testSplitCapturedByApiStatusExcludesRefused(): void
    {
        $rowPaid    = (object) ['stancer_id' => 'paym_paid_x', 'order_id' => 'FA1', 'amount' => 100, 'fk_soc' => 1];
        $rowRefused = (object) ['stancer_id' => 'paym_refused_x', 'order_id' => 'FA2', 'amount' => 200, 'fk_soc' => 1];
        \HttpMock::addJsonResponse('*checkout/paym_paid_x*', ['status' => 'captured']);
        \HttpMock::addJsonResponse('*checkout/paym_refused_x*', ['status' => 'refused']);

        $api = new \StancerApi();
        $split = stancerSplitCapturedByApiStatus([$rowPaid, $rowRefused], $api, $this->db);

        $this->assertCount(1, $split['paid']);
        $this->assertEquals('paym_paid_x', $split['paid'][0]->stancer_id);
        $this->assertCount(1, $split['notpaid']);
        $this->assertEquals('refused', $split['notpaid'][0]->api_status);
        $this->assertFalse($split['auth_error']);
    }

    // =========================================================================
    // TTL cache: a row whose tms is fresh is trusted as-is, no API call (none is
    // mocked here - if it hit the API, getPayment would fail and it would land in
    // notpaid).
    // =========================================================================
    public function testSplitUsesLocalStatusWhenTmsFresh(): void
    {
        $fresh = (object) [
            'stancer_id' => 'paym_fresh', 'status' => 2, 'rowid' => 0,
            'order_id' => '', 'amount' => 0, 'fk_soc' => 0,
            'tms' => dol_print_date(dol_now(), '%Y-%m-%d %H:%M:%S'),
        ];

        $api = new \StancerApi();
        $split = stancerSplitCapturedByApiStatus([$fresh], $api, $this->db, 3600);

        $this->assertCount(1, $split['paid'], 'A fresh-tms captured row must be trusted without an API call');
        $this->assertCount(0, $split['notpaid']);
    }

    // =========================================================================
    // Over-pay path: forcing a payment on an already-settled invoice with
    // allowOverpay=true must RE-OPEN the invoice (paye -> 0) before posting.
    // =========================================================================
    public function testForcePostOverpayReopensSettledInvoice(): void
    {
        $soc = $this->makeSociete('OverpayReopenSoc', 'overreopen@x.test');
        $invoice = $this->buildValidatedInvoice($soc, 20.00);
        // Mark it paid, as another mean (Stripe/Mollie) would have.
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "facture SET paye = 1 WHERE rowid = " . (int) $invoice->id);

        \HttpMock::addJsonResponse('*checkout*', [
            'status' => 'captured', 'order_id' => $invoice->ref, 'amount' => 2000, 'created' => 1, 'method' => 'card',
        ]);

        $api = new \StancerApi();
        // allowOverpay = true.
        stancerForcePostPayment('paym_over_' . uniqid(), $this->db, $this->testUser, $api, true);

        // The invoice must have been re-opened, regardless of whether the final
        // bank posting succeeds in the test env (no bank account configured).
        $r = $this->db->query("SELECT paye FROM " . MAIN_DB_PREFIX . "facture WHERE rowid = " . (int) $invoice->id);
        $this->assertEquals(0, (int) $this->db->fetch_object($r)->paye, 'Invoice must be re-opened for over-pay');
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
     * Create a Dolibarr payment (llx_paiement + llx_paiement_facture) on an
     * invoice, tagged with an ext_payment_site (e.g. 'mollie', 'stancer').
     */
    private function seedDolibarrPayment(\Facture $invoice, float $amountEur, string $extSite): void
    {
        $p = new \Paiement($this->db);
        $p->datepaye   = dol_now();
        $p->amounts    = array($invoice->id => $amountEur);
        $p->paiementid = (int) dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1);
        $p->num_payment = 'TST' . uniqid();
        $p->ext_payment_site = $extSite;
        $pid = $p->create($this->testUser);
        $this->assertGreaterThan(0, $pid, 'seedDolibarrPayment failed: ' . $p->error);
    }

    // =========================================================================
    // Form A: an invoice fully settled by another mean (Mollie) is reported as
    // settled, with that mean listed -> a captured-but-unposted Stancer payment
    // on it is a probable double.
    // =========================================================================
    public function testInvoiceStateDetectsSettledByOtherMean(): void
    {
        $soc = $this->makeSociete('SettledSoc', 'settled@x.test');
        $invoice = $this->buildValidatedInvoice($soc, 50.00);
        $this->seedDolibarrPayment($invoice, (float) $invoice->total_ttc, 'mollie');

        $state = stancerInvoiceStateForOrderId($invoice->ref, $this->db);

        $this->assertTrue($state['found']);
        $this->assertTrue($state['is_settled'], 'Invoice fully paid by Mollie must be settled');
        $this->assertContains('mollie', $state['methods']);
        $this->assertNotContains('stancer', $state['methods']);
    }

    public function testInvoiceStateNotSettledWhenPartial(): void
    {
        $soc = $this->makeSociete('PartialSoc', 'partial@x.test');
        $invoice = $this->buildValidatedInvoice($soc, 100.00);
        $this->seedDolibarrPayment($invoice, (float) $invoice->total_ttc / 2, 'mollie');

        $state = stancerInvoiceStateForOrderId($invoice->ref, $this->db);

        $this->assertTrue($state['found']);
        $this->assertFalse($state['is_settled'], 'Half-paid invoice must NOT be settled');
        $this->assertGreaterThan(0.01, (float) $state['remaining']);
    }

    public function testInvoiceStateNotFoundForUnknownOrderId(): void
    {
        $state = stancerInvoiceStateForOrderId('FA-NOPE-' . uniqid(), $this->db);
        $this->assertFalse($state['found']);
    }

    // =========================================================================
    // Form B: an invoice over-paid by a Stancer payment + another mean is
    // reported; a normally-paid invoice is not.
    // =========================================================================
    public function testFindOverpaidWithStancerDetectsDouble(): void
    {
        $soc = $this->makeSociete('OverpaidSoc', 'overpaid@x.test');
        $invoice = $this->buildValidatedInvoice($soc, 40.00);
        $ttc = (float) $invoice->total_ttc;
        // Two full payments: one Stancer + one Mollie -> total = 2 * ttc.
        $this->seedDolibarrPayment($invoice, $ttc, 'stancer');
        $this->seedDolibarrPayment($invoice, $ttc, 'mollie');

        $over = stancerFindOverpaidWithStancer($this->db);
        $refs = array_map(function ($o) {
            return $o['invoice_ref'];
        }, $over);

        $this->assertContains($invoice->ref, $refs, 'Over-paid invoice with a Stancer payment must be detected');
        foreach ($over as $o) {
            if ($o['invoice_ref'] === $invoice->ref) {
                $this->assertEqualsWithDelta($ttc, $o['excess'], 0.01);
                $this->assertContains('stancer', $o['methods']);
                $this->assertContains('mollie', $o['methods']);
            }
        }
    }

    public function testFindOverpaidIgnoresNormallyPaid(): void
    {
        $soc = $this->makeSociete('NormalPaidSoc', 'normal@x.test');
        $invoice = $this->buildValidatedInvoice($soc, 30.00);
        // Single Stancer payment for the exact amount -> not over-paid.
        $this->seedDolibarrPayment($invoice, (float) $invoice->total_ttc, 'stancer');

        $over = stancerFindOverpaidWithStancer($this->db);
        $refs = array_map(function ($o) {
            return $o['invoice_ref'];
        }, $over);

        $this->assertNotContains($invoice->ref, $refs, 'A normally-paid invoice must NOT be flagged over-paid');
    }

    // =========================================================================
    // createCustomer must survive a 422 on an invalid mobile: when an email is
    // available, retry without the mobile instead of failing the separation.
    // Reproduces PACK PLV (#2225) with phone '+336310713366' (one extra digit).
    // =========================================================================
    public function testRepairRetriesCustomerCreationWithoutInvalidMobile(): void
    {
        $cust = 'cust_mob_' . uniqid();
        $newCust = 'cust_nomob_' . uniqid();

        // 1st createCustomer -> 422 rejecting the mobile; 2nd (no mobile) -> OK.
        \HttpMock::addResponse('*customers*', [
            'http_code' => 422,
            'content'   => json_encode(['detail' => [[
                'loc'  => array('body', 'mobile'),
                'msg'  => 'Please provide a valid mobile phone number',
                'type' => 'value_error',
            ]]]),
        ]);
        \HttpMock::addJsonResponse('*customers*', ['id' => $newCust, 'email' => 'mob@intruder.test']);

        $owner = $this->makeSociete('MobOwner', 'mobowner@x.test');
        $intruder = $this->makeSociete('MobIntruder', 'mob@intruder.test');
        // Invalid mobile (one extra digit for a FR number).
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "societe SET phone = '+336310713366' WHERE rowid = " . (int) $intruder->id);

        $this->createTestCompanyPaymentMode($owner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        $this->createTestCompanyPaymentMode($intruder, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        $this->seedPayment((int) $intruder->id, $cust); // 1 payment -> CASE B nbPay>0

        $api = new \StancerApi();
        $res = stancerRepairSharedCustomer($cust, (int) $owner->id, $this->db, $this->testUser, $api, false);

        $this->assertTrue($res['success'], 'Repair should succeed via retry without mobile: ' . $res['message']);
        $this->assertEquals('done', $res['actions'][0]['status']);
        $this->assertEquals($newCust, $res['actions'][0]['new_cust']);
        $this->assertDatabaseHas('societe_rib', ['fk_soc' => (int) $intruder->id, 'stancer_account' => $newCust]);
    }

    // =========================================================================
    // SEPA mandates (societe_rib.type='ban') must survive EVERY repair path.
    // A mandate holds the IBAN, the RUM and the mandate date; Dolibarr lists the
    // bank accounts of a thirdparty with "type='ban' AND fk_soc=x" and cannot
    // build a direct debit without one. Deleting it silently stops every future
    // withdrawal, which is exactly what happened before this guard existed.
    // =========================================================================

    /**
     * Count the societe_rib rows of a thirdparty for a given type.
     */
    private function countRibOfType(int $socid, string $type): int
    {
        $sql = "SELECT COUNT(*) AS c FROM " . MAIN_DB_PREFIX . "societe_rib"
            . " WHERE fk_soc = " . $socid . " AND type = '" . $this->db->escape($type) . "'";
        $res = $this->db->query($sql);
        return (int) $this->db->fetch_object($res)->c;
    }

    /**
     * Read one column of a societe_rib row.
     */
    private function ribColumn(int $rowid, string $column): string
    {
        $sql = "SELECT " . $column . " AS v FROM " . MAIN_DB_PREFIX . "societe_rib WHERE rowid = " . $rowid;
        $res = $this->db->query($sql);
        $obj = $this->db->fetch_object($res);
        return $obj === null ? '' : (string) $obj->v;
    }

    // CASE B (intruder with payments): the mandate is re-anchored to the new
    // distinct cust, never deleted, and its banking data is left untouched.
    public function testRepairReanchorsSepaMandateInsteadOfDeletingIt(): void
    {
        $cust = 'cust_sepa_' . uniqid();
        $newCust = 'cust_sepanew_' . uniqid();
        \HttpMock::addJsonResponse('*customers*', ['id' => $newCust, 'email' => 'sepaintruder@y.test']);

        $owner = $this->makeSociete('SepaOwner', 'sepaowner@x.test');
        $intruder = $this->makeSociete('SepaIntruder', 'sepaintruder@y.test');

        $this->createTestCompanyPaymentMode($owner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        // The intruder carries BOTH a bogus card link and a real SEPA mandate.
        $this->createTestCompanyPaymentMode($intruder, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        $mandateId = $this->createTestCompanyPaymentMode($intruder, [
            'type'               => 'ban',
            'label'              => 'stancer-sepa',
            'stancer_account'    => $cust,
            'stancer_object_ref' => 'sepa_keepme',
            'iban'               => 'FR7630001007941234567890185',
            'rum'                => 'RUM-KEEP-ME',
        ]);
        $this->seedPayment((int) $intruder->id, $cust);

        $api = new \StancerApi();
        $res = stancerRepairSharedCustomer($cust, (int) $owner->id, $this->db, $this->testUser, $api, false);

        $this->assertTrue($res['success'], 'Repair should succeed: ' . $res['message']);
        $this->assertEquals('done', $res['actions'][0]['status']);
        $this->assertEquals(1, $res['actions'][0]['rib_deleted'], 'Only the card link is droppable');
        $this->assertEquals(1, $res['actions'][0]['rib_preserved'], 'The mandate must be reported as preserved');

        // The mandate row still exists, with its banking data intact...
        $this->assertEquals(1, $this->countRibOfType((int) $intruder->id, 'ban'),
            'The SEPA mandate must never be deleted by a repair');
        $this->assertEquals('RUM-KEEP-ME', $this->ribColumn($mandateId, 'rum'));
        $this->assertEquals('FR7630001007941234567890185', $this->ribColumn($mandateId, 'iban_prefix'));
        $this->assertEquals('sepa_keepme', $this->ribColumn($mandateId, 'stancer_object_ref'));
        // ...and is now anchored to the distinct customer.
        $this->assertEquals($newCust, $this->ribColumn($mandateId, 'stancer_account'));

        // No duplicate anchor: the re-anchored mandate IS the anchor.
        $this->assertEquals(0, $this->countRibOfType((int) $intruder->id, 'card'),
            'The bogus card link is gone and no placeholder was added on top of the mandate');

        // Separation is durable: the cust is no longer shared.
        $this->assertNotContains($cust, stancerFindSharedCustomerIds($this->db));
    }

    // CASE B without payment: nothing justifies a distinct customer, so the
    // mandate is kept exactly as it is (and keeps the cust flagged as shared).
    public function testRepairKeepsSepaMandateWhenIntruderHasNoPayment(): void
    {
        $cust = 'cust_sepanopay_' . uniqid();
        $owner = $this->makeSociete('SepaNoPayOwner', 'sepanopayowner@x.test');
        $intruder = $this->makeSociete('SepaNoPayIntruder', 'sepanopayintruder@y.test');

        $this->createTestCompanyPaymentMode($owner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        $mandateId = $this->createTestCompanyPaymentMode($intruder, [
            'type' => 'ban', 'label' => 'stancer-sepa', 'stancer_account' => $cust, 'rum' => 'RUM-NOPAY',
        ]);

        // No HttpMock queued: a 0-payment intruder must NOT hit the API.
        $api = new \StancerApi();
        $res = stancerRepairSharedCustomer($cust, (int) $owner->id, $this->db, $this->testUser, $api, false);

        $this->assertEquals('done', $res['actions'][0]['status']);
        $this->assertEquals(0, $res['actions'][0]['rib_deleted'], 'Nothing droppable here');
        $this->assertEquals(1, $res['actions'][0]['rib_preserved']);

        $this->assertEquals(1, $this->countRibOfType((int) $intruder->id, 'ban'));
        $this->assertEquals($cust, $this->ribColumn($mandateId, 'stancer_account'), 'Mandate left as it is');
        $this->assertEquals('RUM-NOPAY', $this->ribColumn($mandateId, 'rum'));
    }

    // CASE C (generic account, no email/+mobile): no distinct customer can be
    // created, so the mandate stays untouched rather than being destroyed.
    public function testRepairKeepsSepaMandateOnGenericAccount(): void
    {
        $cust = 'cust_sepagen_' . uniqid();
        $owner = $this->makeSociete('SepaGenOwner', 'sepagenowner@x.test');
        $this->createTestCompanyPaymentMode($owner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);

        $generic = $this->createTestSociete(['name' => 'CLIENT DIVERS SEPA']);
        $mandateId = $this->createTestCompanyPaymentMode($generic, [
            'type' => 'ban', 'label' => 'stancer-sepa', 'stancer_account' => $cust, 'rum' => 'RUM-GENERIC',
        ]);
        $this->seedPayment((int) $generic->id, $cust, 240);

        // No HttpMock queued on purpose: CASE C must NOT hit the API.
        $api = new \StancerApi();
        $res = stancerRepairSharedCustomer($cust, (int) $owner->id, $this->db, $this->testUser, $api, false);

        $this->assertEquals('done', $res['actions'][0]['status']);
        $this->assertEquals(0, $res['actions'][0]['rib_deleted']);
        $this->assertEquals(1, $res['actions'][0]['rib_preserved']);

        $this->assertEquals(1, $this->countRibOfType((int) $generic->id, 'ban'));
        $this->assertEquals('RUM-GENERIC', $this->ribColumn($mandateId, 'rum'));
    }

    // CASE A (thirdparty merged/deleted): even an orphan mandate is kept - a
    // repair is never allowed to destroy banking data, only to report it.
    public function testRepairKeepsSepaMandateOfMergedThirdparty(): void
    {
        $cust = 'cust_sepamerged_' . uniqid();
        $owner = $this->makeSociete('SepaMergedOwner', 'sepamerged@owner.test');
        $this->createTestCompanyPaymentMode($owner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);

        $ghost = $this->createTestSociete(['name' => 'GhostSepa']);
        $ghostId = (int) $ghost->id;
        $mandateId = $this->createTestCompanyPaymentMode($ghost, [
            'type' => 'ban', 'label' => 'stancer-sepa', 'stancer_account' => $cust, 'rum' => 'RUM-GHOST',
        ]);
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "societe WHERE rowid = " . $ghostId);
        $this->seedPayment($ghostId, $cust, 5000);

        $api = new \StancerApi();
        $res = stancerRepairSharedCustomer($cust, (int) $owner->id, $this->db, $this->testUser, $api, false);

        $this->assertEquals('done', $res['actions'][0]['status']);
        $this->assertEquals(0, $res['actions'][0]['rib_deleted']);
        $this->assertEquals(1, $res['actions'][0]['rib_preserved']);
        $this->assertEquals(1, $res['actions'][0]['payments_relinked']);

        $this->assertEquals(1, $this->countRibOfType($ghostId, 'ban'));
        $this->assertEquals('RUM-GHOST', $this->ribColumn($mandateId, 'rum'));
    }

    // Dry-run must announce what really happens: mandates counted as preserved,
    // never as deleted.
    public function testDryRunCountsMandatesAsPreservedNotDeleted(): void
    {
        $cust = 'cust_sepadry_' . uniqid();
        $owner = $this->makeSociete('SepaDryOwner', 'sepadryowner@x.test');
        $intruder = $this->makeSociete('SepaDryIntruder', 'sepadryintruder@y.test');

        $this->createTestCompanyPaymentMode($owner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        $this->createTestCompanyPaymentMode($intruder, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $cust]);
        $this->createTestCompanyPaymentMode($intruder, [
            'type' => 'ban', 'label' => 'stancer-sepa', 'stancer_account' => $cust, 'rum' => 'RUM-DRY',
        ]);
        $this->seedPayment((int) $intruder->id, $cust);

        $api = new \StancerApi();
        $res = stancerRepairSharedCustomer($cust, (int) $owner->id, $this->db, $this->testUser, $api, true);

        $this->assertEquals('planned', $res['actions'][0]['status']);
        $this->assertEquals(1, $res['actions'][0]['rib_deleted'], 'Only the card link would be deleted');
        $this->assertEquals(1, $res['actions'][0]['rib_preserved']);
        $this->assertEquals(2, $this->countRibOfType((int) $intruder->id, 'ban') + $this->countRibOfType((int) $intruder->id, 'card'),
            'A dry-run writes nothing');
    }
}
