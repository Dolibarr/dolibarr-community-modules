<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Integration tests for stancerSendInvoiceMailModele() dedup logic.
 *
 * Background: a previous bug caused the dedup filter to look for code='AC_<actionCode>'
 * while stancerAddActionComm stored emails under code='AC_EMAIL'. Result: the same
 * notification could be sent multiple times for the same invoice. The fix records the
 * module-specific actionCode in extraparams and broadens the dedup filter to match
 * either shape.
 */
class StancerMailDedupIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureStancerSettings();

        dol_include_once('/stancer/lib/stancer_mail.lib.php');
    }

    /**
     * Insert an ActionComm row mimicking what stancerAddActionComm would create.
     */
    private function insertActionComm(int $invoiceId, int $socid, string $code, string $extraparams = ''): int
    {
        require_once DOL_DOCUMENT_ROOT . '/comm/action/class/actioncomm.class.php';
        $now = dol_now();
        $ac = new \ActionComm($this->db);
        $ac->type_code = 'AC_OTH_AUTO';
        $ac->code = $code;
        $ac->label = 'Stub for dedup test';
        $ac->datep = $now;
        $ac->datef = $now;
        $ac->percentage = -1;
        $ac->socid = $socid;
        $ac->contactid = 0;
        $ac->authorid = $this->testUser->id;
        $ac->userownerid = $this->testUser->id;
        $ac->fk_element = $invoiceId;
        $ac->elementtype = 'facture';
        $ac->extraparams = $extraparams;
        $id = $ac->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'ActionComm fixture insertion failed: ' . ($ac->error ?? ''));
        return (int) $id;
    }

    /**
     * Count ActionComm rows attached to an invoice (any code).
     */
    private function countAllActionComms(int $invoiceId): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "actioncomm"
            . " WHERE fk_element = " . $invoiceId . " AND elementtype = 'facture'";
        $res = $this->db->query($sql);
        if (!$res) {
            return -1;
        }
        $obj = $this->db->fetch_object($res);
        return (int) $obj->cnt;
    }

    /**
     * New storage format: code='AC_EMAIL', extraparams='AC_<actionCode>'.
     * A second call must early-return 0 (dedup match) without creating a new row.
     */
    public function testDedupMatchesAcEmailWithExtraparams(): void
    {
        global $conf;
        // MAIL_FROM must be set so that, if dedup did NOT match, the function would
        // go past the dedup block and only then early-return null on the empty $to.
        // Distinguishing 0 (dedup) from null (no recipient) is what proves the fix.
        $conf->global->MAIN_MAIL_EMAIL_FROM = 'test@example.com';

        $soc = $this->createTestSociete(['name' => 'Dedup New Format Test']);
        $invoice = $this->createTestInvoice($soc);

        $this->insertActionComm((int) $invoice->id, (int) $soc->id, 'AC_EMAIL', 'AC_BILL_PAYED_SENTBYMAIL');
        $before = $this->countAllActionComms((int) $invoice->id);

        $result = stancerSendInvoiceMailModele('AnyTemplate', $invoice, 'BILL_PAYED_SENTBYMAIL', 0);

        $this->assertSame(0, $result, 'Expected dedup short-circuit (return 0)');
        $this->assertSame($before, $this->countAllActionComms((int) $invoice->id), 'No new ActionComm must be created when dedup matches');
    }

    /**
     * Legacy storage format: code='AC_<actionCode>', no extraparams.
     * Compat: dedup must still match this older shape.
     */
    public function testDedupMatchesLegacyAcBillCode(): void
    {
        global $conf;
        $conf->global->MAIN_MAIL_EMAIL_FROM = 'test@example.com';

        $soc = $this->createTestSociete(['name' => 'Dedup Legacy Format Test']);
        $invoice = $this->createTestInvoice($soc);

        $this->insertActionComm((int) $invoice->id, (int) $soc->id, 'AC_BILL_PAYED_SENTBYMAIL', '');

        $result = stancerSendInvoiceMailModele('AnyTemplate', $invoice, 'BILL_PAYED_SENTBYMAIL', 0);

        $this->assertSame(0, $result, 'Expected dedup short-circuit on legacy code shape');
    }

    /**
     * An AC_EMAIL row whose extraparams targets a DIFFERENT actionCode must NOT match.
     * Otherwise the new dedup would be too permissive and silence unrelated mails.
     */
    public function testDedupDoesNotMatchUnrelatedActionCode(): void
    {
        global $conf;
        $conf->global->MAIN_MAIL_EMAIL_FROM = 'test@example.com';

        $soc = $this->createTestSociete(['name' => 'Dedup Unrelated Test']);
        $invoice = $this->createTestInvoice($soc);

        // Pre-existing row for a DIFFERENT actionCode
        $this->insertActionComm((int) $invoice->id, (int) $soc->id, 'AC_EMAIL', 'AC_BILL_VALIDATE_SENTBYMAIL');

        // Call for BILL_PAYED_SENTBYMAIL: dedup must NOT match. The function then goes
        // past the dedup block, finds no recipient (test societe has no email and no
        // billing contact) and falls through the `if (empty($from) || empty($to)) return;`
        // which returns null. assertNull distinguishes this from dedup (which returns 0).
        $result = stancerSendInvoiceMailModele('AnyTemplate', $invoice, 'BILL_PAYED_SENTBYMAIL', 0);

        $this->assertNull($result, 'Dedup must not match an unrelated extraparams marker');
    }

    /**
     * forceMail=1 must bypass dedup completely, even when a matching row exists.
     */
    public function testDedupSkippedWhenForceMail(): void
    {
        global $conf;
        $conf->global->MAIN_MAIL_EMAIL_FROM = 'test@example.com';

        $soc = $this->createTestSociete(['name' => 'Force Mail Test']);
        $invoice = $this->createTestInvoice($soc);

        // Row that would match if dedup ran
        $this->insertActionComm((int) $invoice->id, (int) $soc->id, 'AC_EMAIL', 'AC_BILL_PAYED_SENTBYMAIL');

        // forceMail=1 -> dedup block skipped -> empty recipient -> null
        $result = stancerSendInvoiceMailModele('AnyTemplate', $invoice, 'BILL_PAYED_SENTBYMAIL', 1);

        $this->assertNull($result, 'forceMail=1 must bypass dedup');
    }

    /**
     * An empty actionCode must NOT trigger the dedup query: searching for code='AC_'
     * would either match nothing or, with a partial-match bug, match unrelated rows.
     * The fix guards with !empty($actionCode).
     */
    public function testDedupSkippedWhenActionCodeEmpty(): void
    {
        global $conf;
        $conf->global->MAIN_MAIL_EMAIL_FROM = 'test@example.com';

        $soc = $this->createTestSociete(['name' => 'Empty ActionCode Test']);
        $invoice = $this->createTestInvoice($soc);

        // Row that an over-eager dedup might accidentally match on code='AC_'
        $this->insertActionComm((int) $invoice->id, (int) $soc->id, 'AC_', '');

        $result = stancerSendInvoiceMailModele('AnyTemplate', $invoice, '', 0);

        // Dedup is skipped entirely when actionCode is empty -> function proceeds and
        // early-returns null on empty recipient.
        $this->assertNull($result, 'Empty actionCode must skip dedup query');
    }
}
