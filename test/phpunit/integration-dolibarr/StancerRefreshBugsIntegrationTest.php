<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Integration tests that pin known bugs in the refresh path.
 *
 * These tests describe the expected (correct) behaviour. They are written before
 * the fix, so they are expected to FAIL on the current code base. Once the bugs
 * are corrected they must all pass.
 *
 * Bugs covered:
 *  1. stancerRefreshAllPaymentsFromDolibarr: when the linked object is a Commande
 *     whose linked Facture is fully paid, the local Stancer_payments status is
 *     never set to STATUS_CAPTURED (continue 3 jumps over the update).
 *  2. getObjectFromTag does not recognize the PRO= prefix (devis), although the
 *     tag is built with PRO= in lib/stancer_payment.lib.php.
 *  3. The fallback to getObjectFromOrderID is followed by an unconditional
 *     continue, so any payment whose unique_id does not match (SEPA batch,
 *     propal, ...) is skipped even when order_id resolves the target.
 *  4. There is no propal branch in the refresh loop: a captured payment that
 *     points to a Propal linked to a paid Facture leaves the local status stuck.
 */
class StancerRefreshBugsIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupHttpMock();
        $this->configureStancerSettings();

        dol_include_once('/stancer/lib/stancer.lib.php');
        dol_include_once('/stancer/lib/stancer_refresh.lib.php');
        dol_include_once('/stancer/lib/stancer_bank.lib.php');

        require_once __DIR__ . '/../../../class/stancer_api.class.php';
        require_once __DIR__ . '/../../../class/stancer_payments.class.php';

        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
        require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';

        // Ensure user has rights to create orders, propals, payments
        if (!isset($this->testUser->rights->commande)) {
            $this->testUser->rights->commande = new \stdClass();
        }
        $this->testUser->rights->commande->creer = 1;
        $this->testUser->rights->commande->valider = 1;

        if (!isset($this->testUser->rights->propal)) {
            $this->testUser->rights->propal = new \stdClass();
        }
        $this->testUser->rights->propal->creer = 1;
        $this->testUser->rights->propal->valider = 1;
        $this->testUser->rights->propal->lire = 1;

        // isModEnabled() reads $conf->modules[<key>] (array), not $conf->{key}->enabled.
        // The bootstrap only enables the stancer module, so isModEnabled('propal'),
        // ('facture') and ('commande') are false by default, which makes
        // getObjectFromOrderID() return null. Activate them explicitly here.
        global $conf;
        if (!isset($conf->modules) || !is_array($conf->modules)) {
            $conf->modules = [];
        }
        $conf->modules['propal'] = 'propal';
        $conf->modules['facture'] = 'facture';
        $conf->modules['commande'] = 'commande';
    }

    protected function tearDown(): void
    {
        $this->teardownHttpMock();
        parent::tearDown();
    }

    /**
     * Build a validated invoice with one line and a target TTC amount.
     */
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
     * Pay an invoice in full via the Dolibarr Paiement object, which also closes it.
     */
    private function payInvoiceInFull(\Facture $invoice, string $stancerId): \Paiement
    {
        $paiement = new \Paiement($this->db);
        $paiement->datepaye = dol_now();
        $paiement->amounts = [$invoice->id => (float) $invoice->total_ttc];
        $paiement->paiementid = (int) dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1);
        $paiement->num_payment = $stancerId;
        $paiement->ext_payment_id = $stancerId;
        $paiement->ext_payment_site = 'stancer';

        $pid = $paiement->create($this->testUser, 1);
        if ($pid <= 0) {
            $this->fail('Failed to create Paiement: ' . $paiement->error . ' | ' . implode(', ', $paiement->errors));
        }
        $invoice->fetch($invoice->id);
        return $paiement;
    }

    // =========================================================================
    // Bug 1: branche commande oublie la mise a jour du statut local Stancer
    // =========================================================================
    public function testCommandePaymentLeavesStancerStatusStuckOnToCapture(): void
    {
        $soc = $this->createTestSociete(['name' => 'Bug1 Commande']);

        // Create and validate a Commande
        $cmd = new \Commande($this->db);
        $cmd->socid = $soc->id;
        $cmd->date = dol_now();
        $cmdId = $cmd->create($this->testUser);
        $this->assertGreaterThan(0, $cmdId, 'Commande create failed: ' . $cmd->error);
        $cmd->fetch($cmdId);

        // Linked invoice (Facture <- Commande), fully paid
        $invoice = $this->buildValidatedInvoice($soc, 180.0);
        $invoice->add_object_linked('commande', $cmd->id);
        $stancerId = 'paym_bug1_' . uniqid();
        $this->payInvoiceInFull($invoice, $stancerId);

        // Sanity check: invoice is paye=1
        $invoice->fetch($invoice->id);
        $this->assertEquals(1, (int) $invoice->paye, 'Pre-condition: invoice must be marked paid');

        // Create the local Stancer_payments stuck on to_capture, pointing to the Commande
        $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 18000,
            'unique_id'  => 'CUS=' . $soc->id . '.ORD=' . $cmd->id,
            'order_id'   => $cmd->ref,
            'status'     => \Stancer_payments::STATUS_TO_CAPTURE,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        // Run the refresh path that should unstick the status
        stancerRefreshAllPaymentsFromDolibarr(false);

        // Re-fetch and assert
        $reload = new \Stancer_payments($this->db);
        $reload->fetch(0, null, $stancerId);

        $this->assertEquals(
            \Stancer_payments::STATUS_CAPTURED,
            (int) $reload->status,
            'Bug 1: when the Commande linked Facture is fully paid, the local Stancer_payments status must be moved to STATUS_CAPTURED (currently stuck at ' . (int) $reload->status . ').'
        );
    }

    // =========================================================================
    // Bug 2: getObjectFromTag does not recognize PRO=
    // =========================================================================
    public function testGetObjectFromTagRecognizesPropalPrefix(): void
    {
        $soc = $this->createTestSociete(['name' => 'Bug2 Propal']);

        $propal = new \Propal($this->db);
        $propal->socid = $soc->id;
        $propal->date = dol_now();
        $propalId = $propal->create($this->testUser);
        $this->assertGreaterThan(0, $propalId, 'Propal create failed: ' . $propal->error);

        $tag = 'CUS=' . $soc->id . '.PRO=' . $propalId;
        $obj = getObjectFromTag($tag);

        $this->assertNotNull(
            $obj,
            'Bug 2: getObjectFromTag must resolve a PRO= prefix to the matching Propal (currently returns null).'
        );
        $this->assertEquals('propal', $obj->element);
        $this->assertEquals($propalId, (int) $obj->id);
    }

    // =========================================================================
    // Bug 3: unconditional continue after the order_id fallback
    // =========================================================================
    public function testFallbackOrderIdShouldNotSkipStatusUpdate(): void
    {
        $soc = $this->createTestSociete(['name' => 'Bug3 Fallback']);

        // Validated invoice, fully paid
        $invoice = $this->buildValidatedInvoice($soc, 75.0);
        $stancerId = 'paym_bug3_' . uniqid();
        $this->payInvoiceInFull($invoice, $stancerId);

        // unique_id has no INV/ORD/DON/MEM, getObjectFromTag returns null.
        // order_id is the invoice ref, which getObjectFromOrderID can resolve.
        $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 7500,
            'unique_id'  => 'CUS=' . $soc->id . '.NADA=foo',
            'order_id'   => $invoice->ref,
            'status'     => \Stancer_payments::STATUS_TO_CAPTURE,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        stancerRefreshAllPaymentsFromDolibarr(false);

        $reload = new \Stancer_payments($this->db);
        $reload->fetch(0, null, $stancerId);

        $this->assertEquals(
            \Stancer_payments::STATUS_CAPTURED,
            (int) $reload->status,
            'Bug 3: when getObjectFromTag fails but getObjectFromOrderID resolves an already paid invoice, the local status must be updated (currently stuck at ' . (int) $reload->status . ' because of an unconditional continue after the fallback).'
        );
    }

    // =========================================================================
    // Bug 5: null-deref in dol_syslog when both fallbacks fail
    // =========================================================================
    public function testFallbackToOrderIdMustNotCrashWhenNothingResolves(): void
    {
        $soc = $this->createTestSociete(['name' => 'Bug5 NullDeref']);

        // unique_id and order_id both point to nothing resolvable
        $stancerId = 'paym_bug5_' . uniqid();
        $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 1000,
            'unique_id'  => 'CUS=' . $soc->id . '.NADA=foo',
            'order_id'   => 'NO_SUCH_REF_' . uniqid(),
            'status'     => \Stancer_payments::STATUS_TO_CAPTURE,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        // Today this triggers "Attempt to read property element on null" at
        // stancer_refresh.lib.php:233 because dol_syslog reads $obj->element
        // without null-check. The refresh must survive this case.
        try {
            stancerRefreshAllPaymentsFromDolibarr(false);
        } catch (\Throwable $e) {
            $this->fail('Bug 5: refresh must not crash when both unique_id and order_id resolve to null. Got: ' . $e->getMessage());
        }
        $this->assertTrue(true, 'refresh survived an unresolvable payment');
    }

    // =========================================================================
    // Bug 4: propal branch missing entirely
    // =========================================================================
    public function testPropalPaymentUpdatesLocalStatusWhenLinkedInvoicePaid(): void
    {
        $soc = $this->createTestSociete(['name' => 'Bug4 Propal']);

        // Validated propal
        $propal = new \Propal($this->db);
        $propal->socid = $soc->id;
        $propal->date = dol_now();
        $propalId = $propal->create($this->testUser);
        $this->assertGreaterThan(0, $propalId, 'Propal create failed: ' . $propal->error);
        $propal->fetch($propalId);

        // Invoice generated from the propal, paid in full
        $invoice = $this->buildValidatedInvoice($soc, 120.0);
        $invoice->add_object_linked('propal', $propal->id);
        $stancerId = 'paym_bug4_' . uniqid();
        $this->payInvoiceInFull($invoice, $stancerId);

        // Mock the Stancer API: getPayment returns captured. The refresh has to
        // reach the API call because there is no propal short-circuit today; the
        // mock guarantees the test does not depend on real network.
        \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
            'id'        => $stancerId,
            'amount'    => 12000,
            'currency'  => 'eur',
            'status'    => 'captured',
            'method'    => 'card',
            'unique_id' => 'CUS=' . $soc->id . '.PRO=' . $propal->id,
            'order_id'  => $propal->ref,
            'response'  => '00',
            'date_bank' => time(),
            'date_paym' => time(),
        ]);

        $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 12000,
            'unique_id'  => 'CUS=' . $soc->id . '.PRO=' . $propal->id,
            'order_id'   => $propal->ref,
            'status'     => \Stancer_payments::STATUS_TO_CAPTURE,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        stancerRefreshAllPaymentsFromDolibarr(false);

        $reload = new \Stancer_payments($this->db);
        $reload->fetch(0, null, $stancerId);

        $this->assertEquals(
            \Stancer_payments::STATUS_CAPTURED,
            (int) $reload->status,
            'Bug 4: a captured payment pointing to a Propal whose linked Facture is paid must move the local status to STATUS_CAPTURED (currently stuck at ' . (int) $reload->status . ' because no propal branch exists in the refresh loop).'
        );
    }
}
