<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Integration tests for the order/propal -> invoice resolution used by the repair
 * tool (stancer_repair.lib.php).
 *
 * Real-world workflow reproduced: a sales order (commande) is created and paid via
 * Stancer, so the Stancer order_id is the COMMANDE ref; the invoice is only created
 * afterwards (at payment return). The repair page must therefore resolve the invoice
 * BEHIND the commande, not treat the order_id as an invoice ref. When the linked
 * invoice already carries the Stancer payment, it is "not a problem" (already
 * settled). A propal works the same way.
 *
 * Covers:
 *  - stancerResolveInvoiceFromOrderId(): commande/propal link + direct-ref + null.
 *  - stancerInvoiceStateForOrderId(): resolves via the commande link and reports
 *    the settlement state (already paid via stancer, or remaining to pay).
 *  - stancerForcePostPayment(): resolves the invoice behind the commande and posts,
 *    without Guard 1 (api_order_id === invoice ref) refusing the commande case.
 */
class StancerOrderLinkedInvoiceIntegrationTest extends DolibarrRealTestCase
{
    /** @var int */
    private $bankAccountId = 0;

    /** @var array<string,mixed> Snapshot of global state mutated by this test. */
    private $confSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        global $conf;

        $this->setupHttpMock();

        // Snapshot the global state we are about to mutate so tearDown can restore
        // it (this test enables the bank module and facture/commande/propal, which
        // other tests assume are off).
        $this->confSnapshot = [
            'banque_enabled' => isset($conf->banque->enabled) ? $conf->banque->enabled : null,
            'modules'        => (isset($conf->modules) && is_array($conf->modules)) ? $conf->modules : null,
        ];

        require_once __DIR__ . '/../../../class/stancer_api.class.php';
        require_once __DIR__ . '/../../../class/stancer_payments.class.php';
        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
        require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
        dol_include_once('/stancer/lib/stancer.lib.php');
        dol_include_once('/stancer/lib/stancer_bank.lib.php');
        dol_include_once('/stancer/lib/stancer_repair.lib.php');

        // Bank module must look enabled for the posting path.
        if (!isset($conf->banque) || !is_object($conf->banque)) {
            $conf->banque = new \stdClass();
        }
        $conf->banque->enabled = 1;

        // fetchObjectLinked() drops linkedObjectsIds entries whose module is not
        // enabled (isModEnabled). The bootstrap only enables stancer, so activate
        // facture/commande/propal explicitly (they are enabled in real Dolibarr).
        if (!isset($conf->modules) || !is_array($conf->modules)) {
            $conf->modules = [];
        }
        $conf->modules['facture'] = 'facture';
        $conf->modules['commande'] = 'commande';
        $conf->modules['propal'] = 'propal';

        // Grant order/propal rights (needed to create and validate them).
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

        $this->bankAccountId = $this->createBankAccount();
        $this->configureStancerSettings([
            'STANCER_BANK_ACCOUNT_FOR_PAYMENTS' => (string) $this->bankAccountId,
        ]);

        // Reset the StancerApi singleton so the HTTP mock is honoured.
        $ref = new \ReflectionClass('StancerApi');
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function tearDown(): void
    {
        global $conf;

        if ($this->bankAccountId > 0) {
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "bank WHERE fk_account = " . (int) $this->bankAccountId);
            $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "bank_account WHERE rowid = " . (int) $this->bankAccountId);
            $this->bankAccountId = 0;
        }
        $conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS = '1';

        // Restore the global state mutated in setUp (bank module + enabled modules)
        // so we do not pollute tests that assume they are off.
        if (array_key_exists('banque_enabled', $this->confSnapshot)) {
            if ($this->confSnapshot['banque_enabled'] === null) {
                if (isset($conf->banque)) {
                    $conf->banque->enabled = 0;
                }
            } else {
                $conf->banque->enabled = $this->confSnapshot['banque_enabled'];
            }
        }
        if (array_key_exists('modules', $this->confSnapshot)) {
            if ($this->confSnapshot['modules'] === null) {
                unset($conf->modules['facture'], $conf->modules['commande'], $conf->modules['propal']);
            } else {
                $conf->modules = $this->confSnapshot['modules'];
            }
        }

        $this->teardownHttpMock();
        parent::tearDown();
    }

    // =========================================================================
    // Resolver: order_id is a commande ref -> the invoice linked to it.
    // =========================================================================
    public function testResolveFollowsCommandeLink(): void
    {
        $soc = $this->createTestSociete(['name' => 'ResolveCmd']);
        $cmd = $this->createValidatedCommande($soc);
        $invoice = $this->buildValidatedInvoice($soc, 40.00);
        $invoice->add_object_linked('commande', $cmd->id);

        $resolved = stancerResolveInvoiceFromOrderId($cmd->ref, $this->db);

        $this->assertNotNull($resolved, 'A commande ref must resolve to its linked invoice');
        $this->assertEquals((int) $invoice->id, (int) $resolved->id, 'Resolver must return the invoice linked to the commande');
    }

    // =========================================================================
    // Resolver: order_id is a propal ref -> the invoice linked to it.
    // =========================================================================
    public function testResolveFollowsPropalLink(): void
    {
        $soc = $this->createTestSociete(['name' => 'ResolveProp']);
        $propal = $this->createValidatedPropal($soc);
        $invoice = $this->buildValidatedInvoice($soc, 55.00);
        $invoice->add_object_linked('propal', $propal->id);

        $resolved = stancerResolveInvoiceFromOrderId($propal->ref, $this->db);

        $this->assertNotNull($resolved, 'A propal ref must resolve to its linked invoice');
        $this->assertEquals((int) $invoice->id, (int) $resolved->id, 'Resolver must return the invoice linked to the propal');
    }

    // =========================================================================
    // Resolver: the direct case (order_id IS an invoice ref) still works.
    // =========================================================================
    public function testResolveDirectInvoiceRefStillWorks(): void
    {
        $soc = $this->createTestSociete(['name' => 'ResolveDirect']);
        $invoice = $this->buildValidatedInvoice($soc, 30.00);

        $resolved = stancerResolveInvoiceFromOrderId($invoice->ref, $this->db);

        $this->assertNotNull($resolved);
        $this->assertEquals((int) $invoice->id, (int) $resolved->id);
    }

    // =========================================================================
    // Resolver: unresolvable order_id -> null (no invoice, no order/propal).
    // =========================================================================
    public function testResolveReturnsNullWhenUnresolvable(): void
    {
        $this->assertNull(stancerResolveInvoiceFromOrderId('NO_SUCH_REF_' . uniqid(), $this->db));
        $this->assertNull(stancerResolveInvoiceFromOrderId('', $this->db));
    }

    // =========================================================================
    // Resolver: when a commande is linked to several invoices, the one already
    // carrying a Stancer payment is preferred (it is the real target).
    // =========================================================================
    public function testResolvePrefersInvoiceCarryingStancerPayment(): void
    {
        $soc = $this->createTestSociete(['name' => 'ResolvePrefer']);
        $cmd = $this->createValidatedCommande($soc);

        // Two invoices linked to the same commande: only the second is settled via stancer.
        $plain = $this->buildValidatedInvoice($soc, 25.00);
        $plain->add_object_linked('commande', $cmd->id);
        $paid = $this->buildValidatedInvoice($soc, 25.00);
        $paid->add_object_linked('commande', $cmd->id);
        $this->payInvoiceViaStancer($paid, 'paym_prefer_' . uniqid());

        $resolved = stancerResolveInvoiceFromOrderId($cmd->ref, $this->db);

        $this->assertNotNull($resolved);
        $this->assertEquals(
            (int) $paid->id,
            (int) $resolved->id,
            'Resolver must prefer the linked invoice that already carries a Stancer payment'
        );
    }

    // =========================================================================
    // Invoice state via a commande: the "not a problem" case. The linked invoice
    // is already settled by the Stancer payment -> found, settled, methods=[stancer].
    // =========================================================================
    public function testInvoiceStateViaCommandeAlreadySettledByStancer(): void
    {
        $soc = $this->createTestSociete(['name' => 'StateSettled']);
        $cmd = $this->createValidatedCommande($soc);
        $invoice = $this->buildValidatedInvoice($soc, 60.00);
        $invoice->add_object_linked('commande', $cmd->id);
        $this->payInvoiceViaStancer($invoice, 'paym_state_' . uniqid());

        $state = stancerInvoiceStateForOrderId($cmd->ref, $this->db);

        $this->assertTrue($state['found'], 'The invoice behind the commande must be found');
        $this->assertEquals((int) $invoice->id, (int) $state['invoice_id']);
        $this->assertTrue($state['is_settled'], 'A commande whose linked invoice is paid via stancer is settled (not a problem)');
        $this->assertContains('stancer', $state['methods'], 'The settlement method must be reported as stancer');
    }

    // =========================================================================
    // Invoice state via a commande: linked invoice still unpaid -> found, not
    // settled, remaining == total_ttc.
    // =========================================================================
    public function testInvoiceStateViaCommandeUnpaid(): void
    {
        $soc = $this->createTestSociete(['name' => 'StateUnpaid']);
        $cmd = $this->createValidatedCommande($soc);
        $invoice = $this->buildValidatedInvoice($soc, 70.00);
        $invoice->add_object_linked('commande', $cmd->id);

        $state = stancerInvoiceStateForOrderId($cmd->ref, $this->db);

        $this->assertTrue($state['found'], 'The invoice behind the commande must be found even when unpaid');
        $this->assertEquals((int) $invoice->id, (int) $state['invoice_id']);
        $this->assertFalse($state['is_settled']);
        $this->assertEqualsWithDelta((float) $invoice->total_ttc, (float) $state['remaining'], 0.01);
    }

    // =========================================================================
    // Force-post via a commande: the payment order_id is the COMMANDE ref. The
    // force-post must resolve the invoice behind it AND post it, without Guard 1
    // (api_order_id === invoice ref) refusing the commande case.
    // =========================================================================
    public function testForcePostResolvesInvoiceBehindCommande(): void
    {
        $soc = $this->createTestSociete(['name' => 'ForcePostCmd']);
        $cmd = $this->createValidatedCommande($soc);
        $invoice = $this->buildValidatedInvoice($soc, 45.00);
        $invoice->add_object_linked('commande', $cmd->id);

        // The Stancer API returns the COMMANDE ref as order_id (not the invoice ref).
        \HttpMock::addJsonResponse('*checkout*', [
            'status'   => 'captured',
            'order_id' => $cmd->ref,
            'amount'   => (int) round(((float) $invoice->total_ttc) * 100),
            'created'  => 1,
            'method'   => 'card',
        ]);

        $api = new \StancerApi();
        $res = stancerForcePostPayment('paym_fpcmd_' . uniqid(), $this->db, $this->testUser, $api);

        $this->assertStringNotContainsString('does not match invoice ref', $res['message'], 'Guard 1 must not refuse the commande case');
        $this->assertEquals($invoice->ref, $res['invoice_ref'], 'Force-post must resolve the invoice behind the commande');
        $this->assertTrue($res['success'], 'Force-post via a commande ref must succeed: ' . $res['message']);

        // The invoice must now carry the Stancer payment.
        $invoice->fetch($invoice->id);
        $this->assertEquals(1, (int) $invoice->paye, 'The resolved invoice must be settled after the force-post');
    }

    // =========================================================================
    // Grouped payment resolution: the reliable grouped_invoice_ids column drives
    // the resolution of every covered invoice.
    // =========================================================================
    public function testResolveInvoicesForPaymentUsesGroupedIds(): void
    {
        $soc = $this->createTestSociete(['name' => 'GrpIds']);
        $inv1 = $this->buildValidatedInvoice($soc, 10.00);
        $inv2 = $this->buildValidatedInvoice($soc, 12.00);

        $row = (object) [
            'grouped_invoice_ids' => $inv1->id . ',' . $inv2->id,
            'order_id'            => 'IGNORED+BECAUSE+IDS+PRESENT',
            'rowid'               => 0,
        ];
        $invoices = stancerResolveInvoicesForPayment($row, $this->db);

        $ids = array_map(function ($i) {
            return (int) $i->id;
        }, $invoices);
        sort($ids);
        $this->assertSame([(int) $inv1->id, (int) $inv2->id], $ids, 'grouped_invoice_ids must drive the resolution');
    }

    // =========================================================================
    // Grouped payment resolution fallback: parse order_id "FAa+FAb+N", skipping a
    // trailing numeric "+N" remaining-count token (not a ref).
    // =========================================================================
    public function testResolveInvoicesForPaymentParsesOrderIdPlus(): void
    {
        $soc = $this->createTestSociete(['name' => 'GrpPlus']);
        $inv1 = $this->buildValidatedInvoice($soc, 10.00);
        $inv2 = $this->buildValidatedInvoice($soc, 12.00);

        $row = (object) [
            'grouped_invoice_ids' => '',
            // Trailing "+2" is a remaining-count, not a ref: it must be ignored.
            'order_id'            => $inv1->ref . '+' . $inv2->ref . '+2',
            'rowid'               => 0,
        ];
        $invoices = stancerResolveInvoicesForPayment($row, $this->db);

        $ids = array_map(function ($i) {
            return (int) $i->id;
        }, $invoices);
        sort($ids);
        $this->assertSame([(int) $inv1->id, (int) $inv2->id], $ids, 'order_id "+" parsing must resolve both refs and skip the count token');
    }

    // =========================================================================
    // Aggregate state of a partially-settled group: found, grouped, not settled,
    // remaining == the unpaid invoice, methods include the settled one's mean.
    // =========================================================================
    public function testAggregateStatePartialGroup(): void
    {
        $soc = $this->createTestSociete(['name' => 'GrpAgg']);
        $inv1 = $this->buildValidatedInvoice($soc, 10.00);
        $inv2 = $this->buildValidatedInvoice($soc, 12.00);
        $this->payInvoiceViaStancer($inv1, 'paym_agg_' . uniqid()); // inv1 settled via stancer
        $inv1->fetch($inv1->id);
        $inv2->fetch($inv2->id);

        $agg = stancerAggregateInvoiceState([$inv1, $inv2], $this->db);

        $this->assertTrue($agg['found']);
        $this->assertTrue($agg['grouped']);
        $this->assertSame(2, (int) $agg['count']);
        $this->assertFalse($agg['is_settled'], 'A group with one unpaid invoice is not settled');
        $this->assertEqualsWithDelta((float) $inv2->total_ttc, (float) $agg['remaining'], 0.01);
        $this->assertContains('stancer', $agg['methods']);
    }

    // =========================================================================
    // Force-post of a grouped payment: the captured amount is dispatched across
    // the group's invoices (driven by grouped_invoice_ids), settling both.
    // =========================================================================
    public function testForcePostDispatchesGroupedPayment(): void
    {
        $soc = $this->createTestSociete(['name' => 'GrpForce']);
        $inv1 = $this->buildValidatedInvoice($soc, 10.00);
        $inv2 = $this->buildValidatedInvoice($soc, 12.00);
        $sumCents = (int) round((((float) $inv1->total_ttc) + ((float) $inv2->total_ttc)) * 100);

        $stancerId = 'paym_grpforce_' . uniqid();
        $this->seedGroupedStancerPayment($stancerId, $inv1->id . ',' . $inv2->id, $inv1->ref . '+' . $inv2->ref, $sumCents);

        \HttpMock::addJsonResponse('*checkout*', [
            'status'   => 'captured',
            'order_id' => $inv1->ref . '+' . $inv2->ref,
            'amount'   => $sumCents,
            'created'  => 1,
            'method'   => 'sepa',
        ]);

        $api = new \StancerApi();
        $res = stancerForcePostPayment($stancerId, $this->db, $this->testUser, $api);

        $this->assertTrue($res['success'], 'Grouped force-post must succeed: ' . $res['message']);

        $inv1->fetch($inv1->id);
        $inv2->fetch($inv2->id);
        $this->assertEquals(1, (int) $inv1->paye, 'Invoice 1 of the group must be settled');
        $this->assertEquals(1, (int) $inv2->paye, 'Invoice 2 of the group must be settled');
    }

    // -------------------------------------------------------------------------

    private function seedGroupedStancerPayment(string $stancerId, string $groupedIds, string $orderId, int $amountCents): void
    {
        $sp = new \Stancer_payments($this->db);
        $sp->stancer_id = $stancerId;
        $sp->amount = $amountCents;
        $sp->currency = 'eur';
        $sp->status = \Stancer_payments::STATUS_CAPTURED;
        $sp->method = 'sepa';
        $sp->description = 'Grouped force-post test';
        $sp->customer = 'cust_grp_' . uniqid();
        $sp->live_mode = 0;
        $sp->order_id = $orderId;
        $sp->unique_id = 'GRP=' . substr(md5($groupedIds), 0, 8) . '.CUS=0';
        $sp->grouped_invoice_ids = $groupedIds;
        $res = $sp->create($this->testUser);
        $this->assertGreaterThan(0, $res, 'seedGroupedStancerPayment failed: ' . $sp->error);
    }

    private function createValidatedCommande(\Societe $soc): \Commande
    {
        $cmd = new \Commande($this->db);
        $cmd->socid = $soc->id;
        $cmd->date = dol_now();
        $id = $cmd->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'Commande create failed: ' . $cmd->error);
        // Assign a real, unique ref instead of the provisional "(PROVxx)" one: a
        // PROV ref would collide with a draft invoice ref in the resolver's
        // direct-invoice-fetch step (test artifact; order_id is a real ref in prod).
        $this->assignRealRef('commande', (int) $id, 'COTEST');
        $cmd->fetch($id);
        return $cmd;
    }

    private function createValidatedPropal(\Societe $soc): \Propal
    {
        $propal = new \Propal($this->db);
        $propal->socid = $soc->id;
        $propal->date = dol_now();
        $id = $propal->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'Propal create failed: ' . $propal->error);
        $this->assignRealRef('propal', (int) $id, 'PRTEST');
        $propal->fetch($id);
        return $propal;
    }

    private function assignRealRef(string $table, int $id, string $prefix): void
    {
        $ref = $prefix . '-' . $id . '-' . uniqid();
        $ok = $this->db->query("UPDATE " . MAIN_DB_PREFIX . $table . " SET ref = '" . $this->db->escape($ref) . "' WHERE rowid = " . $id);
        $this->assertNotFalse($ok, 'assignRealRef failed: ' . $this->db->lasterror());
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

    private function payInvoiceViaStancer(\Facture $invoice, string $stancerId): void
    {
        $p = new \Paiement($this->db);
        $p->datepaye = dol_now();
        $p->amounts = [$invoice->id => (float) $invoice->total_ttc];
        $p->paiementid = (int) dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1);
        $p->num_payment = $stancerId;
        $p->ext_payment_id = $stancerId;
        $p->ext_payment_site = 'stancer';
        $pid = $p->create($this->testUser, 1);
        $this->assertGreaterThan(0, $pid, 'payInvoiceViaStancer failed: ' . $p->error);
        $invoice->fetch($invoice->id);
    }

    private function createBankAccount(): int
    {
        require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
        global $mysoc;

        $acc = new \Account($this->db);
        $acc->ref           = 'STCLNK' . uniqid();
        $acc->label         = 'Stancer Test Bank';
        $acc->country_id    = (int) (!empty($mysoc->country_id) ? $mysoc->country_id : 1);
        $acc->date_solde    = dol_now();
        $acc->solde         = 0;
        $acc->currency_code = 'EUR';
        $acc->type          = 1; // Account::TYPE_CURRENT
        $acc->courant       = 1;
        $acc->clos          = 0;
        $id = $acc->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'createBankAccount failed: ' . $acc->error);
        return (int) $id;
    }
}
