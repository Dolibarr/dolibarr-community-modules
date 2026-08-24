<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Integration tests for the "hide stuck Stancer_payments when the linked object
 * is no longer eligible" path added in stancer_payment.lib.php and consumed by
 * the two refresh functions in stancer_refresh.lib.php.
 *
 * Scenario reproduced: customer attempts a CB payment from the shop, fails,
 * then asks for a bank transfer. The merchant switches the invoice/order
 * payment method to VIR and the bank account away from the Stancer one. The
 * cron must stop emitting "Erreur de paiement" emails for that orphaned
 * Stancer payment and mark its local row STATUS_HIDDEN so it stays out of the
 * next refresh window.
 */
class StancerHiddenEligibilityIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupHttpMock();
        $this->configureStancerSettings();

        dol_include_once('/stancer/lib/stancer.lib.php');
        dol_include_once('/stancer/lib/stancer_refresh.lib.php');
        dol_include_once('/stancer/lib/stancer_bank.lib.php');
        dol_include_once('/stancer/lib/stancer_payment.lib.php');

        require_once __DIR__ . '/../../../class/stancer_api.class.php';
        require_once __DIR__ . '/../../../class/stancer_payments.class.php';

        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
        require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';

        // isModEnabled() reads $conf->modules[<key>], not $conf->{key}->enabled. The
        // bootstrap only enables stancer; we also need facture/commande for the resolver.
        global $conf;
        if (!isset($conf->modules) || !is_array($conf->modules)) {
            $conf->modules = [];
        }
        $conf->modules['facture'] = 'facture';
        $conf->modules['commande'] = 'commande';

        // STANCER_BANK_ACCOUNT_FOR_PAYMENTS = '1' from configureStancerSettings().
        // Force a known value so the eligibility check compares against a stable int.
        $conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS = '1';

        // Permissions used by Commande create
        if (!isset($this->testUser->rights->commande)) {
            $this->testUser->rights->commande = new \stdClass();
        }
        $this->testUser->rights->commande->creer = 1;
        $this->testUser->rights->commande->valider = 1;
    }

    protected function tearDown(): void
    {
        $this->teardownHttpMock();
        parent::tearDown();
    }

    /**
     * Build a validated invoice with the requested payment mode + bank account.
     * Returns the freshly fetched Facture so mode_reglement_code is populated.
     */
    private function buildInvoiceWithPaymentMode(\Societe $soc, string $modeCode, int $fkAccount, float $amountHT = 50.0): \Facture
    {
        $modeId = (int) dol_getIdFromCode($this->db, $modeCode, 'c_paiement', 'code', 'id', 1);

        $invoice = new \Facture($this->db);
        $invoice->socid = $soc->id;
        $invoice->date = dol_now();
        $invoice->mode_reglement_code = $modeCode;
        $invoice->mode_reglement_id = $modeId;
        $invoice->fk_account = $fkAccount;
        $invoice->create($this->testUser);
        $invoice->addline('Test line', $amountHT, 1, 0, 0, 0, 0, 0, '', '', 0, 0, '', 'HT');
        $invoice->fetch($invoice->id);
        $invoice->validate($this->testUser);
        $invoice->fetch($invoice->id);
        return $invoice;
    }

    /**
     * Build a validated commande with the requested payment mode + bank account.
     */
    private function buildCommandeWithPaymentMode(\Societe $soc, string $modeCode, int $fkAccount): \Commande
    {
        $modeId = (int) dol_getIdFromCode($this->db, $modeCode, 'c_paiement', 'code', 'id', 1);

        $cmd = new \Commande($this->db);
        $cmd->socid = $soc->id;
        $cmd->date = dol_now();
        $cmd->mode_reglement_code = $modeCode;
        $cmd->mode_reglement_id = $modeId;
        $cmd->fk_account = $fkAccount;
        $cmdId = $cmd->create($this->testUser);
        $this->assertGreaterThan(0, $cmdId, 'Commande create failed: ' . $cmd->error);
        $cmd->fetch($cmdId);
        return $cmd;
    }

    // =========================================================================
    // Helper isolation: covers the eligibility helper itself.
    // =========================================================================
    public function testHelperReturnsFalseWhenObjectIsNull(): void
    {
        $this->assertFalse(stancerIsObjectStillEligibleForStancer(null));
    }

    public function testHelperReturnsTrueWhenInvoiceIsCBOnStancerBank(): void
    {
        $soc = $this->createTestSociete(['name' => 'Helper CB OK']);
        $invoice = $this->buildInvoiceWithPaymentMode($soc, 'CB', 1);

        $this->assertTrue(stancerIsObjectStillEligibleForStancer($invoice));
    }

    public function testHelperReturnsTrueWhenInvoiceIsPREOnStancerBank(): void
    {
        $soc = $this->createTestSociete(['name' => 'Helper PRE OK']);
        $invoice = $this->buildInvoiceWithPaymentMode($soc, 'PRE', 1);

        $this->assertTrue(stancerIsObjectStillEligibleForStancer($invoice));
    }

    public function testHelperReturnsFalseWhenInvoiceModeChangedToVIR(): void
    {
        $soc = $this->createTestSociete(['name' => 'Helper VIR KO']);
        $invoice = $this->buildInvoiceWithPaymentMode($soc, 'VIR', 1);

        $this->assertFalse(stancerIsObjectStillEligibleForStancer($invoice));
    }

    public function testHelperReturnsFalseWhenFkAccountDiffersFromStancerBank(): void
    {
        $soc = $this->createTestSociete(['name' => 'Helper Bank KO']);
        $invoice = $this->buildInvoiceWithPaymentMode($soc, 'CB', 999);

        $this->assertFalse(stancerIsObjectStillEligibleForStancer($invoice));
    }

    // =========================================================================
    // Refresh from Dolibarr local list: invoice manually switched to VIR.
    // The stuck Stancer_payments must end up STATUS_HIDDEN, no error mail logged.
    // =========================================================================
    public function testRefreshFromDolibarrHidesPaymentWhenInvoiceModeIsVIR(): void
    {
        $soc = $this->createTestSociete(['name' => 'Refresh Invoice VIR']);
        $invoice = $this->buildInvoiceWithPaymentMode($soc, 'VIR', 1);

        $stancerId = 'paym_elig_inv_vir_' . uniqid();
        \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
            'id'        => $stancerId,
            'amount'    => 5000,
            'currency'  => 'eur',
            'status'    => 'failed',
            'method'    => 'card',
            'unique_id' => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'  => $invoice->ref,
            'response'  => 'A1',
        ]);

        $sp = $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 5000,
            'unique_id'  => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'   => $invoice->ref,
            'status'     => \Stancer_payments::STATUS_FAILED,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        stancerRefreshAllPaymentsFromDolibarr(false, null, false);

        $reload = new \Stancer_payments($this->db);
        $reload->fetch(0, null, $stancerId);
        $this->assertEquals(
            \Stancer_payments::STATUS_HIDDEN,
            (int) $reload->status,
            'A failed Stancer payment whose invoice has been switched away from CB/PRE must be marked HIDDEN by the refresh.'
        );
    }

    // =========================================================================
    // Same scenario for a commande (the real-world incident).
    // =========================================================================
    public function testRefreshFromDolibarrHidesPaymentWhenCommandeModeIsVIR(): void
    {
        $soc = $this->createTestSociete(['name' => 'Refresh Commande VIR']);
        $cmd = $this->buildCommandeWithPaymentMode($soc, 'VIR', 1);

        $stancerId = 'paym_elig_cmd_vir_' . uniqid();
        \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
            'id'        => $stancerId,
            'amount'    => 4200,
            'currency'  => 'eur',
            'status'    => 'failed',
            'method'    => 'card',
            'unique_id' => 'CUS=' . $soc->id . '.ORD=' . $cmd->id,
            'order_id'  => $cmd->ref,
            'response'  => 'A1',
        ]);

        $sp = $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 4200,
            'unique_id'  => 'CUS=' . $soc->id . '.ORD=' . $cmd->id,
            'order_id'   => $cmd->ref,
            'status'     => \Stancer_payments::STATUS_FAILED,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        stancerRefreshAllPaymentsFromDolibarr(false, null, false);

        $reload = new \Stancer_payments($this->db);
        $reload->fetch(0, null, $stancerId);
        $this->assertEquals(
            \Stancer_payments::STATUS_HIDDEN,
            (int) $reload->status,
            'A failed Stancer payment whose commande has been switched away from CB/PRE must be marked HIDDEN.'
        );
    }

    // =========================================================================
    // Bank account check: invoice still CB but pointing to a non-Stancer bank.
    // =========================================================================
    public function testRefreshFromDolibarrHidesPaymentWhenBankAccountIsNotStancer(): void
    {
        $soc = $this->createTestSociete(['name' => 'Refresh Other Bank']);
        $invoice = $this->buildInvoiceWithPaymentMode($soc, 'CB', 999);

        $stancerId = 'paym_elig_bank_' . uniqid();
        \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
            'id'        => $stancerId,
            'amount'    => 3000,
            'currency'  => 'eur',
            'status'    => 'refused',
            'method'    => 'card',
            'unique_id' => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'  => $invoice->ref,
            'response'  => '05',
        ]);

        $sp = $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 3000,
            'unique_id'  => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'   => $invoice->ref,
            'status'     => \Stancer_payments::STATUS_REFUSED,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        stancerRefreshAllPaymentsFromDolibarr(false, null, false);

        $reload = new \Stancer_payments($this->db);
        $reload->fetch(0, null, $stancerId);
        $this->assertEquals(
            \Stancer_payments::STATUS_HIDDEN,
            (int) $reload->status,
            'A refused Stancer payment whose invoice fk_account != STANCER_BANK_ACCOUNT_FOR_PAYMENTS must be marked HIDDEN.'
        );
    }

    // =========================================================================
    // Negative case: invoice still CB on Stancer bank -> NOT hidden.
    // =========================================================================
    public function testRefreshFromDolibarrKeepsPaymentWhenStillEligible(): void
    {
        $soc = $this->createTestSociete(['name' => 'Refresh Still Eligible']);
        $invoice = $this->buildInvoiceWithPaymentMode($soc, 'CB', 1);

        $stancerId = 'paym_elig_keep_' . uniqid();
        \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
            'id'        => $stancerId,
            'amount'    => 5000,
            'currency'  => 'eur',
            'status'    => 'failed',
            'method'    => 'card',
            'unique_id' => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'  => $invoice->ref,
            'response'  => 'A1',
        ]);

        $initialStatus = \Stancer_payments::STATUS_FAILED;
        $sp = $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 5000,
            'unique_id'  => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'   => $invoice->ref,
            'status'     => $initialStatus,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        stancerRefreshAllPaymentsFromDolibarr(false, null, false);

        $reload = new \Stancer_payments($this->db);
        $reload->fetch(0, null, $stancerId);
        $this->assertNotEquals(
            \Stancer_payments::STATUS_HIDDEN,
            (int) $reload->status,
            'An eligible (CB + Stancer bank) failed payment must NOT be marked HIDDEN by the refresh.'
        );
    }

    // =========================================================================
    // CustomSQL guard: a row already HIDDEN must NOT be re-picked by the refresh
    // (no API call is made, the row keeps STATUS_HIDDEN unchanged).
    // =========================================================================
    public function testRefreshFromDolibarrSkipsAlreadyHiddenRow(): void
    {
        $soc = $this->createTestSociete(['name' => 'Already Hidden']);
        $invoice = $this->buildInvoiceWithPaymentMode($soc, 'CB', 1);

        $stancerId = 'paym_already_hidden_' . uniqid();
        // If the customsql still picks up HIDDEN, the code would try to fetch the
        // API; assert no GET happened on this paym_id at all.
        \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
            'id'        => $stancerId,
            'amount'    => 1000,
            'currency'  => 'eur',
            'status'    => 'failed',
            'method'    => 'card',
        ]);

        $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 1000,
            'unique_id'  => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'   => $invoice->ref,
            'status'     => \Stancer_payments::STATUS_HIDDEN,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        stancerRefreshAllPaymentsFromDolibarr(false, null, false);

        $this->assertFalse(
            \HttpMock::wasRequested('*checkout/' . $stancerId . '*', 'GET'),
            'A Stancer_payments row in STATUS_HIDDEN must be excluded by the customsql filter (no API fetch).'
        );
    }
}
