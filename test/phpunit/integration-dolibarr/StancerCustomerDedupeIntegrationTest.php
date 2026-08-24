<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Integration tests for the NITD-style customer dedupe (2026-05-19).
 *
 * Stancer does NOT dedupe customers by email server-side: calling
 * POST /customers twice with the same email gives 2 distinct cust_xxx.
 * To avoid that, stancerAddCustomerIfNeeded() now checks for an existing
 * customer in 2 places BEFORE calling createCustomer:
 *  1. local: societe_rib + llx_stancer_stancer_payments.fk_soc
 *  2. fallback: Stancer API GET /customers?email= or ?mobile=
 *
 * These tests pin the 3 outcomes:
 *  - found locally (societe_rib or stancer_payments)
 *  - found via API (no local trace, but exists upstream)
 *  - truly new (creates a fresh cust_xxx)
 */
class StancerCustomerDedupeIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupHttpMock();
        $this->configureStancerSettings();

        dol_include_once('/stancer/lib/stancer.lib.php');
        dol_include_once('/stancer/lib/stancer_customer.lib.php');

        require_once __DIR__ . '/../../../class/stancer_api.class.php';
        require_once __DIR__ . '/../../../class/stancer_payments.class.php';
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

        // Reset the StancerApi singleton so test config is picked up.
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

    /**
     * Insert a row in llx_stancer_stancer_payments for $soc with the given cust_xxx.
     * Used to simulate "client paid by CB once, no SEPA mandate created".
     */
    private function seedStancerPayment(\Societe $soc, string $customerId, bool $liveMode = false): void
    {
        $sp = new \Stancer_payments($this->db);
        $sp->stancer_id = 'paym_seed_' . uniqid();
        $sp->amount = 1000;
        $sp->currency = 'eur';
        $sp->status = \Stancer_payments::STATUS_CAPTURED;
        $sp->method = 'card';
        $sp->description = 'Seed for dedupe test';
        $sp->customer = $customerId;
        $sp->fk_soc = (int) $soc->id;
        $sp->live_mode = $liveMode ? 1 : 0;
        $sp->unique_id = 'CUS=' . $soc->id . '.INV=42';
        $sp->order_id = '';
        $res = $sp->create($this->testUser);
        $this->assertGreaterThan(0, $res, 'Failed to seed stancer_payment: ' . $sp->error);
    }

    // =========================================================================
    // Pass 1a: societe_rib already has a row -> reused immediately.
    // =========================================================================
    public function testFindCustomerLocallyViaSocieteRib(): void
    {
        $soc = $this->createTestSociete(['name' => 'DedupeViaRib']);
        $this->createTestCompanyPaymentMode($soc, [
            'type'               => 'card',
            'label'              => 'stancer-card-existing',
            'stancer_account'    => 'cust_via_rib_' . uniqid(),
            'stancer_object_ref' => 'card_via_rib_' . uniqid(),
        ]);

        // Re-fetch the stored cust_xxx so the assertion is exact.
        $sqlPeek = "SELECT stancer_account FROM " . MAIN_DB_PREFIX . "societe_rib"
            . " WHERE fk_soc = " . (int) $soc->id . " LIMIT 1";
        $res = $this->db->query($sqlPeek);
        $expectedCust = (string) $this->db->fetch_object($res)->stancer_account;

        $found = stancerFindExistingCustomerLocally($soc->id, $this->db, false);
        $this->assertEquals($expectedCust, $found);
    }

    // =========================================================================
    // Pass 1b: no societe_rib row, but historical payments exist.
    // Reproduces the NITD scenario (CB one-shot, no persistent mandate).
    // =========================================================================
    public function testFindCustomerLocallyViaPaymentsTable(): void
    {
        $soc = $this->createTestSociete(['name' => 'DedupeViaPayments']);
        $existingCust = 'cust_pew_' . uniqid();
        $this->seedStancerPayment($soc, $existingCust, false);

        $found = stancerFindExistingCustomerLocally($soc->id, $this->db, false);
        $this->assertEquals($existingCust, $found);
    }

    // =========================================================================
    // Pass 1 must respect live_mode: a live customer must NOT pollute a test
    // lookup, and vice versa.
    // =========================================================================
    public function testFindCustomerLocallyRespectsLiveMode(): void
    {
        $soc = $this->createTestSociete(['name' => 'DedupeLiveMode']);
        $liveCust = 'cust_live_' . uniqid();
        $this->seedStancerPayment($soc, $liveCust, true); // live_mode = 1

        // Looking up in test mode (false) must NOT find the live one.
        $found = stancerFindExistingCustomerLocally($soc->id, $this->db, false);
        $this->assertEquals('', $found, 'Test-mode lookup must skip live-mode customers');

        // Live lookup finds it.
        $foundLive = stancerFindExistingCustomerLocally($soc->id, $this->db, true);
        $this->assertEquals($liveCust, $foundLive);
    }

    // =========================================================================
    // Pass 2: nothing local, but Stancer has a customer with the same email.
    // =========================================================================
    public function testFindCustomerOnStancerByEmail(): void
    {
        $apiCust = 'cust_api_email_' . uniqid();
        \HttpMock::addJsonResponse('*customers*email=test%40dedupe.example*', [
            'customers' => [
                ['id' => $apiCust, 'email' => 'test@dedupe.example', 'name' => 'API hit'],
            ],
        ]);

        $api = new \StancerApi();
        $found = stancerFindExistingCustomerOnStancer('test@dedupe.example', '', $api);

        $this->assertEquals($apiCust, $found);
    }

    // =========================================================================
    // Pass 2: nothing local, nothing upstream -> returns empty.
    // =========================================================================
    public function testFindCustomerOnStancerReturnsEmptyWhenNoMatch(): void
    {
        \HttpMock::addJsonResponse('*customers*', ['customers' => []]);

        $api = new \StancerApi();
        $found = stancerFindExistingCustomerOnStancer('unknown@example.com', '+33000000000', $api);

        $this->assertEquals('', $found);
    }

    // =========================================================================
    // 401 from Stancer must abort the API pass cleanly (no exception).
    // =========================================================================
    public function testFindCustomerOnStancerSurvivesAuthError(): void
    {
        \HttpMock::addResponse('*customers*', [
            'http_code' => 401,
            'content'   => json_encode(['error' => ['type' => 'auth', 'message' => 'bad key']]),
        ]);

        $api = new \StancerApi();
        $found = stancerFindExistingCustomerOnStancer('any@example.com', '', $api);

        $this->assertEquals('', $found);
    }

    // =========================================================================
    // Root cause of the 2026-06 cross-customer leak: an API that does NOT honour
    // the email filter returns a stranger. The strict email validation must
    // reject it instead of blindly reusing $list[0].
    // =========================================================================
    public function testFindCustomerOnStancerRejectsUnrelatedEmail(): void
    {
        \HttpMock::addJsonResponse('*customers*email=alice%40acme.example*', [
            'customers' => [
                ['id' => 'cust_stranger', 'email' => 'someone.else@other.example', 'name' => 'Stranger'],
            ],
        ]);

        $api = new \StancerApi();
        $found = stancerFindExistingCustomerOnStancer('alice@acme.example', '', $api);

        $this->assertEquals('', $found, 'Must not reuse a customer whose email differs from the searched one');
    }

    // =========================================================================
    // The Stancer API has NO 'mobile' filter; a mobile-only lookup must perform
    // no blind pick. With an empty email, no customer may ever be returned
    // (this blind pick was the exact mechanism that leaked one cust to many).
    // =========================================================================
    public function testFindCustomerOnStancerIgnoresMobileWhenNoEmail(): void
    {
        \HttpMock::addJsonResponse('*customers*', [
            'customers' => [
                ['id' => 'cust_first_of_account', 'email' => 'first@account.example', 'name' => 'First'],
            ],
        ]);

        $api = new \StancerApi();
        $found = stancerFindExistingCustomerOnStancer('', '+33612345678', $api);

        $this->assertEquals('', $found, 'Mobile-only lookup must not pick any customer');
    }

    // =========================================================================
    // stancerCustomerOtherSocids(): a cust linked to two distinct thirdparties
    // (one via societe_rib, one via a historical payment) must be reported as
    // shared, excluding the queried socid itself.
    // =========================================================================
    public function testCustomerOtherSocidsDetectsSharedCust(): void
    {
        $cust = 'cust_shared_' . uniqid();
        $socA = $this->createTestSociete(['name' => 'ShareA']);
        $socB = $this->createTestSociete(['name' => 'ShareB']);

        $this->createTestCompanyPaymentMode($socA, [
            'type'            => 'card',
            'label'           => 'stancer-card',
            'stancer_account' => $cust,
        ]);
        $this->seedStancerPayment($socB, $cust, false);

        $othersForA = stancerCustomerOtherSocids($cust, (int) $socA->id, $this->db);
        $this->assertContains((int) $socB->id, $othersForA, 'socB must be seen as sharing the cust');
        $this->assertNotContains((int) $socA->id, $othersForA, 'The queried socid must be excluded');

        $othersForB = stancerCustomerOtherSocids($cust, (int) $socB->id, $this->db);
        $this->assertContains((int) $socA->id, $othersForB);

        // A cust nobody else uses must report no other socid.
        $lonely = stancerCustomerOtherSocids('cust_nobody_' . uniqid(), (int) $socA->id, $this->db);
        $this->assertSame([], $lonely);
    }

    // =========================================================================
    // Bullet-proof: stancerAddCustomerIfNeeded() must NEVER return/keep a cust
    // already linked to another thirdparty - even when THIS socid already has a
    // societe_rib pointing to it (the early-return path). It must create a
    // distinct cust and re-point the wrong link. Reproduces clicking "create
    // Stancer account" on a tiers still wrongly linked to another's cust.
    // =========================================================================
    public function testAddCustomerNeverReusesSharedRibCustomer(): void
    {
        require_once __DIR__ . '/../../../class/companypaymentmodestancer.class.php';
        require_once __DIR__ . '/../../../class/stancer.class.php';

        $shared = 'cust_sharedrib_' . uniqid();
        $newCust = 'cust_distinct_' . uniqid();

        $socOwner = $this->createTestSociete(['name' => 'RibOwner']);
        $socIntruder = $this->createTestSociete(['name' => 'RibIntruder']);
        // Both wrongly linked to the SAME cust via societe_rib (the bug state).
        $this->createTestCompanyPaymentMode($socOwner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $shared]);
        $this->createTestCompanyPaymentMode($socIntruder, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $shared]);
        // Intruder needs an email so a distinct customer can be created. Reload
        // the full object afterwards (as production does) so all properties
        // expected by stancerAddCustomerIfNeeded (country_code, ...) are set.
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "societe SET email = 'ribintruder@x.test' WHERE rowid = " . (int) $socIntruder->id);
        $socIntruder->fetch((int) $socIntruder->id);

        \HttpMock::addJsonResponse('*customers*', ['id' => $newCust, 'email' => 'ribintruder@x.test']);

        $returned = stancerAddCustomerIfNeeded($socIntruder);

        $this->assertEquals($newCust, $returned, 'Must create/return a distinct cust, never the shared one');

        // Intruder rib now points to the distinct cust, the wrong link is gone.
        $accounts = array();
        $r = $this->db->query("SELECT stancer_account FROM " . MAIN_DB_PREFIX . "societe_rib"
            . " WHERE fk_soc = " . (int) $socIntruder->id . " AND stancer_account <> ''");
        while ($row = $this->db->fetch_object($r)) {
            $accounts[] = (string) $row->stancer_account;
        }
        $this->assertContains($newCust, $accounts, 'Intruder rib should point to the new distinct cust');
        $this->assertNotContains($shared, $accounts, 'The shared link must have been removed');

        // Owner keeps the shared cust untouched.
        $ro = $this->db->query("SELECT stancer_account FROM " . MAIN_DB_PREFIX . "societe_rib WHERE fk_soc = " . (int) $socOwner->id);
        $this->assertEquals($shared, (string) $this->db->fetch_object($ro)->stancer_account);
    }

    // =========================================================================
    // Same bullet-proof cleanup, but the wrong link is carried by a SEPA mandate
    // (type='ban'). The mandate holds the IBAN and the RUM signed by the customer
    // and is what Dolibarr reads to build a direct debit: it must be re-anchored
    // to the distinct cust, NEVER deleted. Deleting it used to empty the "Bank
    // accounts" block of the thirdparty and stop every withdrawal silently.
    // =========================================================================
    public function testAddCustomerReanchorsSepaMandateAndNeverDeletesIt(): void
    {
        require_once __DIR__ . '/../../../class/companypaymentmodestancer.class.php';
        require_once __DIR__ . '/../../../class/stancer.class.php';

        $shared = 'cust_sharedsepa_' . uniqid();
        $newCust = 'cust_distinctsepa_' . uniqid();

        $socOwner = $this->createTestSociete(['name' => 'SepaRibOwner']);
        $socIntruder = $this->createTestSociete(['name' => 'SepaRibIntruder']);
        $this->createTestCompanyPaymentMode($socOwner, ['type' => 'card', 'label' => 'stancer-card', 'stancer_account' => $shared]);
        $mandateId = $this->createTestCompanyPaymentMode($socIntruder, [
            'type'               => 'ban',
            'label'              => 'stancer-sepa',
            'stancer_account'    => $shared,
            'stancer_object_ref' => 'sepa_dontdelete',
            'iban'               => 'FR7630001007941234567890185',
            'rum'                => 'RUM-DONT-DELETE',
        ]);
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "societe SET email = 'sepaintruder@x.test' WHERE rowid = " . (int) $socIntruder->id);
        $socIntruder->fetch((int) $socIntruder->id);

        \HttpMock::addJsonResponse('*customers*', ['id' => $newCust, 'email' => 'sepaintruder@x.test']);

        $returned = stancerAddCustomerIfNeeded($socIntruder);

        $this->assertEquals($newCust, $returned, 'Must create/return a distinct cust, never the shared one');

        // The mandate row is still there, with its banking data untouched...
        $sql = "SELECT rowid, type, rum, iban_prefix, stancer_object_ref, stancer_account"
            . " FROM " . MAIN_DB_PREFIX . "societe_rib WHERE rowid = " . (int) $mandateId;
        $res = $this->db->query($sql);
        $row = $this->db->fetch_object($res);

        $this->assertNotNull($row, 'The SEPA mandate must never be deleted by the shared-cust cleanup');
        $this->assertEquals('ban', (string) $row->type);
        $this->assertEquals('RUM-DONT-DELETE', (string) $row->rum);
        $this->assertEquals('FR7630001007941234567890185', (string) $row->iban_prefix);
        $this->assertEquals('sepa_dontdelete', (string) $row->stancer_object_ref);
        // ...and it now points to the distinct cust, so the dedupe cannot resolve
        // back to the shared one and loop creating customers.
        $this->assertEquals($newCust, (string) $row->stancer_account);

        // The thirdparty still has a bank account for Dolibarr (type='ban').
        $resBan = $this->db->query("SELECT COUNT(*) AS c FROM " . MAIN_DB_PREFIX . "societe_rib"
            . " WHERE fk_soc = " . (int) $socIntruder->id . " AND type = 'ban'");
        $this->assertEquals(1, (int) $this->db->fetch_object($resBan)->c);

        // Owner keeps the shared cust untouched.
        $ro = $this->db->query("SELECT stancer_account FROM " . MAIN_DB_PREFIX . "societe_rib WHERE fk_soc = " . (int) $socOwner->id);
        $this->assertEquals($shared, (string) $this->db->fetch_object($ro)->stancer_account);
    }

    // =========================================================================
    // createCustomer must survive a 422 on an invalid mobile when an email is
    // available: retry without the mobile. Shared by the normal flow AND repair.
    // Reproduces PACK PLV with phone '+336310713366' (one extra digit).
    // =========================================================================
    public function testCreateCustomerWithFallbackRetriesWithoutMobile(): void
    {
        $newCust = 'cust_fb_' . uniqid();
        \HttpMock::addResponse('*customers*', [
            'http_code' => 422,
            'content'   => json_encode(['detail' => [[
                'loc' => array('body', 'mobile'),
                'msg' => 'Please provide a valid mobile phone number',
                'type' => 'value_error',
            ]]]),
        ]);
        \HttpMock::addJsonResponse('*customers*', ['id' => $newCust]);

        $api = new \StancerApi();
        $resp = stancerCreateCustomerWithFallback(
            array('name' => 'PACK PLV', 'email' => 'x@y.test', 'mobile' => '+336310713366'),
            $api
        );

        $this->assertIsArray($resp);
        $this->assertEquals($newCust, $resp['id']);
    }

    public function testCreateCustomerWithFallbackNoRetryWithoutEmail(): void
    {
        // No email -> no retry; the 422 surfaces as a failure (false).
        \HttpMock::addResponse('*customers*', [
            'http_code' => 422,
            'content'   => json_encode(['detail' => [[
                'loc' => array('body', 'mobile'),
                'msg' => 'Please provide a valid mobile phone number',
                'type' => 'value_error',
            ]]]),
        ]);

        $api = new \StancerApi();
        $resp = stancerCreateCustomerWithFallback(
            array('name' => 'PACK PLV', 'mobile' => '+336310713366'),
            $api
        );

        $this->assertFalse($resp);
    }
}
