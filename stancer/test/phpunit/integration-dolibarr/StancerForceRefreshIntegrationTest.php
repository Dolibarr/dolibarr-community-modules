<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Integration tests for the "force refresh selected payments" mass action
 * and the $sendNotifications flag added to stancerRefreshAllPaymentsFromDolibarr().
 *
 * The mass action in stancer_payments_list.php triggers an audit-style refresh
 * that:
 *  - ignores the status filter (CAPTURED rows are still re-checked)
 *  - ignores the date filter (old rows are still processed)
 *  - bypasses the "invoice already paid" short circuits, so the Stancer API is
 *    always queried and divergences are reconciled
 *  - skips email notifications when $sendNotifications is false
 *
 * Each test seeds a local Stancer_payments row, mocks the Stancer API response
 * for that payment, calls stancerRefreshAllPaymentsFromDolibarr() with the
 * audit-mode signature (selectedIds = [rowid]), and asserts on the resulting
 * Dolibarr state (Paiement created, ActionComm presence, etc.).
 */
class StancerForceRefreshIntegrationTest extends DolibarrRealTestCase
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
        require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';

        global $conf;
        if (!isset($conf->modules) || !is_array($conf->modules)) {
            $conf->modules = [];
        }
        $conf->modules['facture'] = 'facture';
        // stancerAddPaymentOnObject() bails out (returns -5) when isModEnabled('banque') is false
        // or when STANCER_BANK_ACCOUNT_FOR_PAYMENTS is empty. Activate banque so the audit-mode
        // reconciliation can actually create the Dolibarr Paiement + bank entry.
        $conf->modules['banque'] = 'banque';
        if (!isset($conf->banque) || !is_object($conf->banque)) {
            $conf->banque = new \stdClass();
        }
        $conf->banque->enabled = 1;
    }

    protected function tearDown(): void
    {
        $this->teardownHttpMock();
        parent::tearDown();
    }

    /**
     * Build a validated invoice with one line and a target HT amount.
     *
     * The invoice is configured with payment mode CB and fk_account =
     * STANCER_BANK_ACCOUNT_FOR_PAYMENTS so it stays eligible for Stancer
     * processing (see stancerIsObjectStillEligibleForStancer). Without that,
     * the refresh path now marks the linked Stancer_payments STATUS_HIDDEN
     * and short-circuits before reaching the assertions of these tests.
     */
    private function buildValidatedInvoice(\Societe $soc, float $amountHT): \Facture
    {
        global $conf;
        $cbId = (int) dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1);
        $stancerBank = (int) ($conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS ?? 1);

        $invoice = new \Facture($this->db);
        $invoice->socid = $soc->id;
        $invoice->date = dol_now();
        $invoice->mode_reglement_code = 'CB';
        $invoice->mode_reglement_id = $cbId;
        $invoice->fk_account = $stancerBank;
        $invoice->create($this->testUser);
        $invoice->addline('Test line', $amountHT, 1, 0, 0, 0, 0, 0, '', '', 0, 0, '', 'HT');
        $invoice->fetch($invoice->id);
        $invoice->validate($this->testUser);
        $invoice->fetch($invoice->id);
        return $invoice;
    }

    /**
     * Count llx_paiement rows whose num_paiement = $stancerId. Used to assert that
     * no duplicate Dolibarr Paiement was inserted for a given Stancer payment.
     */
    private function countDolibarrPaymentsForStancerId(string $stancerId): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "paiement WHERE num_paiement = '" . $this->db->escape($stancerId) . "'";
        $res = $this->db->query($sql);
        $row = $this->db->fetch_object($res);
        return (int) $row->cnt;
    }

    /**
     * Count ActionComm rows for a given code on a given invoice. Used to assert
     * that mail-related ActionComm entries are NOT created when notifications
     * are disabled by the caller.
     */
    private function countActionCommsForCode(\Facture $invoice, string $code): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "actioncomm"
            . " WHERE fk_element = " . (int) $invoice->id
            . " AND elementtype = 'invoice'"
            . " AND code = '" . $this->db->escape($code) . "'";
        $res = $this->db->query($sql);
        $row = $this->db->fetch_object($res);
        return (int) $row->cnt;
    }

    // =========================================================================
    // Audit mode ignores the "status <> CAPTURED" filter: a payment already
    // marked CAPTURED locally is still re-checked against the API when the
    // user picks it explicitly.
    // =========================================================================
    public function testAuditModeReVerifiesPaymentEvenWhenAlreadyCapturedLocally(): void
    {
        $soc = $this->createTestSociete(['name' => 'AuditReVerify']);
        $invoice = $this->buildValidatedInvoice($soc, 50.0);
        $stancerId = 'paym_audit_recheck_' . uniqid();

        \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
            'id'        => $stancerId,
            'amount'    => 5000,
            'currency'  => 'eur',
            'status'    => 'captured',
            'method'    => 'card',
            'unique_id' => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'  => $invoice->ref,
            'response'  => '00',
            'date_bank' => time(),
            'date_paym' => time(),
        ]);

        $sp = $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 5000,
            'unique_id'  => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'   => $invoice->ref,
            'status'     => \Stancer_payments::STATUS_CAPTURED,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        stancerRefreshAllPaymentsFromDolibarr(false, null, false, [$sp->id]);

        $this->assertTrue(
            \HttpMock::wasRequested('*checkout/' . $stancerId . '*', 'GET'),
            'Audit mode must call the Stancer API even when the local status is already CAPTURED.'
        );
    }

    // =========================================================================
    // Main reconciliation scenario: a local payment row is stuck on REFUSED
    // while the Stancer API reports it as captured. The audit refresh must
    // fetch the API truth, update the local Stancer_payments row to CAPTURED
    // and attempt to add the missing Dolibarr Paiement. This is the bug the
    // user hit: invoices stuck unpaid while Stancer shows them captured.
    //
    // We assert on the local status update (the deterministic part of the
    // reconciliation); the Dolibarr Paiement creation depends on bank module
    // wiring that varies across environments and is covered by the dedicated
    // stancerAddPaymentOnObject test suite.
    // =========================================================================
    public function testAuditModeSyncsLocalStatusFromApiEvenWhenLocallyRefused(): void
    {
        $soc = $this->createTestSociete(['name' => 'AuditSyncRefused']);
        $invoice = $this->buildValidatedInvoice($soc, 80.0);
        $stancerId = 'paym_audit_sync_' . uniqid();

        \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
            'id'        => $stancerId,
            'amount'    => 8000,
            'currency'  => 'eur',
            'status'    => 'captured',
            'method'    => 'card',
            'unique_id' => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'  => $invoice->ref,
            'response'  => '00',
            'date_bank' => time(),
            'date_paym' => time(),
            'fee'       => 12,
        ]);

        $sp = $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 8000,
            'unique_id'  => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'   => $invoice->ref,
            'status'     => \Stancer_payments::STATUS_REFUSED,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        stancerRefreshAllPaymentsFromDolibarr(false, null, false, [$sp->id]);

        $reload = new \Stancer_payments($this->db);
        $reload->fetch(0, null, $stancerId);
        $this->assertEquals(
            \Stancer_payments::STATUS_CAPTURED,
            (int) $reload->status,
            'Audit mode must overwrite the local REFUSED status with the API truth (captured).'
        );
    }

    // =========================================================================
    // Duplicate guard: the audit refresh must not create a second Paiement
    // when one already exists for that Stancer id, even if the run is forced.
    // =========================================================================
    public function testAuditModeDoesNotDuplicateExistingDolibarrPaiement(): void
    {
        $soc = $this->createTestSociete(['name' => 'AuditNoDuplicate']);
        $invoice = $this->buildValidatedInvoice($soc, 30.0);
        $stancerId = 'paym_audit_dup_' . uniqid();

        // Seed an existing Dolibarr Paiement for this Stancer id
        $existing = new \Paiement($this->db);
        $existing->datepaye = dol_now();
        $existing->amounts = [$invoice->id => (float) $invoice->total_ttc];
        $existing->paiementid = (int) dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1);
        $existing->num_payment = $stancerId;
        $existing->ext_payment_id = $stancerId;
        $existing->ext_payment_site = 'stancer';
        $pid = $existing->create($this->testUser, 1);
        $this->assertGreaterThan(0, $pid, 'Pre-condition: a Paiement must already exist');
        $this->assertEquals(1, $this->countDolibarrPaymentsForStancerId($stancerId));

        \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
            'id'        => $stancerId,
            'amount'    => 3000,
            'currency'  => 'eur',
            'status'    => 'captured',
            'method'    => 'card',
            'unique_id' => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'  => $invoice->ref,
            'response'  => '00',
            'date_bank' => time(),
            'date_paym' => time(),
        ]);

        $sp = $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 3000,
            'unique_id'  => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'   => $invoice->ref,
            'status'     => \Stancer_payments::STATUS_TO_CAPTURE,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        stancerRefreshAllPaymentsFromDolibarr(false, null, false, [$sp->id]);

        $this->assertEquals(
            1,
            $this->countDolibarrPaymentsForStancerId($stancerId),
            'Audit mode must not duplicate an existing Dolibarr Paiement (stancerAddPaymentOnObject returns -1 on duplicate).'
        );
    }

    // =========================================================================
    // Mass-action handler entrypoint: stancerHandleRefreshSelectedMassAction
    // must trigger the Stancer API call when invoked with the expected inputs.
    // This guards the page-handler placement: a previous version of this code
    // was placed AFTER Dolibarr's "$massaction = '' unless confirmmassaction"
    // reset and was silently dropped on every click.
    // =========================================================================
    public function testHandlerCallsApiAndResetsMassActionOnValidInput(): void
    {
        $soc = $this->createTestSociete(['name' => 'HandlerEntrypoint']);
        $invoice = $this->buildValidatedInvoice($soc, 25.0);
        $stancerId = 'paym_handler_entry_' . uniqid();

        \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
            'id'        => $stancerId,
            'amount'    => 2500,
            'currency'  => 'eur',
            'status'    => 'captured',
            'method'    => 'card',
            'unique_id' => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'  => $invoice->ref,
            'response'  => '00',
            'date_bank' => time(),
            'date_paym' => time(),
        ]);

        $sp = $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 2500,
            'unique_id'  => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'   => $invoice->ref,
            'status'     => \Stancer_payments::STATUS_REFUSED,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        $massaction = 'refreshselected';
        $res = stancerHandleRefreshSelectedMassAction([$sp->id], 0, true, $massaction);

        $this->assertNotNull($res, 'Handler must return the refresh result object when massaction matches');
        $this->assertSame('', $massaction, 'Handler must reset $massaction so the standard pipeline does not re-handle it');
        $this->assertTrue(
            \HttpMock::wasRequested('*checkout/' . $stancerId . '*', 'GET'),
            'Handler must reach the Stancer API for each selected rowid'
        );
    }

    public function testHandlerReturnsNullWhenMassActionDoesNotMatch(): void
    {
        $massaction = 'somethingelse';
        $res = stancerHandleRefreshSelectedMassAction([1, 2, 3], 1, true, $massaction);

        $this->assertNull($res, 'Handler must be a no-op when massaction is not refreshselected');
        $this->assertSame('somethingelse', $massaction, 'Handler must NOT touch $massaction when it does not match');
    }

    public function testHandlerReturnsNullWhenPermissionMissing(): void
    {
        $massaction = 'refreshselected';
        $res = stancerHandleRefreshSelectedMassAction([1, 2, 3], 1, false, $massaction);

        $this->assertNull($res, 'Handler must refuse to run without write permission');
        $this->assertSame('refreshselected', $massaction, 'Handler must not reset $massaction when permission is missing');
    }

    public function testHandlerReturnsNullOnEmptySelection(): void
    {
        $massaction = 'refreshselected';
        $res = stancerHandleRefreshSelectedMassAction([], 1, true, $massaction);

        $this->assertNull($res, 'Handler must be a no-op on empty selection');
    }

    // =========================================================================
    // Retry guard: when a first Stancer attempt was REFUSED and a second attempt
    // SUCCEEDED under a different paym_id (very common with CB transient errors
    // returning response='A1'), the user typically force-refreshes the refused
    // row from the list. The audit code must NOT reopen the invoice in that
    // case: a valid Paiement covering total_ttc already exists from the retry,
    // and setUnpaid would silently break a paid invoice from the customer's
    // point of view. Reopen is only legitimate when the invoice is ONLY paid
    // by the refused payment we are auditing.
    // =========================================================================
    public function testAuditModeDoesNotReopenInvoiceCoveredByRetry(): void
    {
        $soc = $this->createTestSociete(['name' => 'RetryGuard']);
        $invoice = $this->buildValidatedInvoice($soc, 100.0);

        $refusedStancerId = 'paym_retry_refused_' . uniqid();
        $retryStancerId   = 'paym_retry_ok_' . uniqid();

        // Seed a valid Dolibarr Paiement under a DIFFERENT num_paiement (the retry)
        // that fully covers the invoice. This is the state we want to protect.
        $retryPaiement = new \Paiement($this->db);
        $retryPaiement->datepaye = dol_now();
        $retryPaiement->amounts = [$invoice->id => (float) $invoice->total_ttc];
        $retryPaiement->paiementid = (int) dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1);
        $retryPaiement->num_payment = $retryStancerId;
        $retryPaiement->ext_payment_id = $retryStancerId;
        $retryPaiement->ext_payment_site = 'stancer';
        $pid = $retryPaiement->create($this->testUser, 1);
        $this->assertGreaterThan(0, $pid, 'Pre-condition: retry Paiement must exist');

        $invoice->fetch($invoice->id);
        $this->assertEquals(1, (int) $invoice->paye, 'Pre-condition: invoice must be marked paid by the retry');

        // API tells us the FIRST (refused) attempt is well refused. Without the safety
        // check the audit would call stancerReopenInvoiceFromPayment() and setUnpaid the
        // invoice. With the check, it must abort and keep the invoice paid.
        \HttpMock::addJsonResponse('*checkout/' . $refusedStancerId . '*', [
            'id'        => $refusedStancerId,
            'amount'    => (int) round($invoice->total_ttc * 100),
            'currency'  => 'eur',
            'status'    => 'refused',
            'method'    => 'card',
            'unique_id' => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'  => $invoice->ref,
            'response'  => 'A1',
        ]);

        $sp = $this->createTestPayment([
            'stancer_id' => $refusedStancerId,
            'amount'     => (int) round($invoice->total_ttc * 100),
            'unique_id'  => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'   => $invoice->ref,
            'status'     => \Stancer_payments::STATUS_REFUSED,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        stancerRefreshAllPaymentsFromDolibarr(false, null, false, [$sp->id]);

        $reload = new \Facture($this->db);
        $reload->fetch($invoice->id);
        $this->assertEquals(
            1,
            (int) $reload->paye,
            'Audit must NOT reopen an invoice already covered by a valid retry payment under a different num_paiement.'
        );
        $this->assertEquals(
            \Facture::STATUS_CLOSED,
            (int) $reload->status,
            'Invoice status must stay CLOSED (paid) after the audit, not be flipped back to validated.'
        );
    }

    // =========================================================================
    // ActionComm code length: stancerAddActionComm prefixes the action code
    // with "AC_" and the resulting string is stored in llx_actioncomm.code,
    // a varchar(50). A naive code of the form CRON_PAY_REPORTED_<paym_id>_<STATUS>
    // overflowed (Data too long for column 'code' at row 1) which silently
    // killed the dedup of cron summary entries. We assert the generated code
    // fits in the column.
    // =========================================================================
    public function testCronSummaryActionCommCodeFitsVarchar50(): void
    {
        global $conf;
        $conf->global->STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT = 'admin@example.com';
        $conf->global->STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT_DETAILS = 'admin@example.com';

        $soc = $this->createTestSociete(['name' => 'ActionCommCodeLen']);
        $invoice = $this->buildValidatedInvoice($soc, 15.0);
        $stancerId = 'paym_codecodecodecodecodec_' . uniqid(); // long-ish on purpose

        \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
            'id'        => $stancerId,
            'amount'    => 1500,
            'currency'  => 'eur',
            'status'    => 'refused',
            'method'    => 'card',
            'unique_id' => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'  => $invoice->ref,
            'response'  => 'A1',
        ]);

        $sp = $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 1500,
            'unique_id'  => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'   => $invoice->ref,
            'status'     => \Stancer_payments::STATUS_TO_CAPTURE,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        stancerRefreshAllPaymentsFromDolibarr(false, null, true, [$sp->id]);

        // The cron summary ActionComm must have been inserted (no truncation error).
        $sql = "SELECT code FROM " . MAIN_DB_PREFIX . "actioncomm WHERE code LIKE 'AC_CRON_PAY_REP_%' AND fk_element = " . (int) $invoice->id;
        $res = $this->db->query($sql);
        $found = null;
        if ($res) {
            $row = $this->db->fetch_object($res);
            $found = $row ? $row->code : null;
        }

        $this->assertNotNull($found, 'Cron summary ActionComm must be inserted (was failing silently due to varchar(50) overflow)');
        $this->assertLessThanOrEqual(50, strlen($found), 'Cron summary ActionComm code must fit in varchar(50), got ' . strlen($found) . ' chars: ' . $found);

        unset($conf->global->STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT);
        unset($conf->global->STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT_DETAILS);
    }

    // =========================================================================
    // Notification gating: sendNotifications=false must skip the admin
    // notification ActionComm entries created next to mail sends. The admin
    // notification path lives at lines ~470 of stancer_refresh.lib.php and is
    // guarded by $mailNotif, which is now also gated by $sendNotifications.
    // =========================================================================
    public function testSendNotificationsFalseSkipsAdminNotificationActionComm(): void
    {
        global $conf;
        // Enable the admin-mail config so that without the gating flag the ActionComm
        // would be created. The flag must override and prevent it.
        $conf->global->STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT = 'admin@example.com';
        $conf->global->STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT_DETAILS = 'admin@example.com';

        $soc = $this->createTestSociete(['name' => 'NoNotifAdmin']);
        $invoice = $this->buildValidatedInvoice($soc, 40.0);
        $stancerId = 'paym_audit_nomail_' . uniqid();

        // API says refused -> code branches into admin notification path
        \HttpMock::addJsonResponse('*checkout/' . $stancerId . '*', [
            'id'        => $stancerId,
            'amount'    => 4000,
            'currency'  => 'eur',
            'status'    => 'refused',
            'method'    => 'card',
            'unique_id' => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'  => $invoice->ref,
            'response'  => '00',
        ]);

        $sp = $this->createTestPayment([
            'stancer_id' => $stancerId,
            'amount'     => 4000,
            'unique_id'  => 'CUS=' . $soc->id . '.INV=' . $invoice->id,
            'order_id'   => $invoice->ref,
            'status'     => \Stancer_payments::STATUS_TO_CAPTURE,
            'method'     => 'card',
            'live_mode'  => 0,
        ]);

        // Refresh with notifications OFF
        stancerRefreshAllPaymentsFromDolibarr(false, null, false, [$sp->id]);

        $adminActionCode = 'ADMIN_PAYERROR_REFUSED';
        $this->assertEquals(
            0,
            $this->countActionCommsForCode($invoice, 'AC_' . $adminActionCode),
            'sendNotifications=false must NOT create the admin-notification ActionComm.'
        );

        // Cleanup config to keep other tests isolated
        unset($conf->global->STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT);
        unset($conf->global->STANCER_AUTO_MAIL_NOTIFICATIONS_PAYMENT_DETAILS);
    }
}
