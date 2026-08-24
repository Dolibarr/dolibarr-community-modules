<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Integration tests for the dedup logic of stancerSendInvoiceMailModele() and
 * stancerSendOrderMailModele(), and for the per object type mail metadata they rely on.
 *
 * Background 1: a previous bug caused the dedup filter to look for code='AC_<actionCode>'
 * while stancerAddActionComm stored emails under code='AC_EMAIL'. Result: the same
 * notification could be sent multiple times for the same invoice. The fix records the
 * module-specific actionCode in extraparams and broadens the dedup filter to match
 * either shape.
 *
 * Background 2: stancerSendOrderMailModele() is called by the CB payment start with
 * whatever getObjectFromTag() returned (invoice, order, proposal, member, donation) but
 * used to hardcode "order" as the ActionComm elementtype and 'ord' as the track id
 * prefix. On an invoice the dedup could never match, since ActionComm::create() stores
 * 'facture' as 'invoice', and the email collector reattached the customer answers to
 * the order carrying the same rowid. Both now come from stancerGetObjectMailContext().
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
     *
     * $elementtype is passed as $object->element would be: ActionComm::create() rewrites
     * 'facture' into 'invoice' and 'commande' into 'order' before the INSERT.
     */
    private function insertActionComm(int $elementId, int $socid, string $code, string $extraparams = '', string $elementtype = 'facture'): int
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
        $ac->fk_element = $elementId;
        $ac->elementtype = $elementtype;
        $ac->extraparams = $extraparams;
        $id = $ac->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'ActionComm fixture insertion failed: ' . ($ac->error ?? ''));
        return (int) $id;
    }

    /**
     * Count ActionComm rows attached to an invoice (any code).
     *
     * The stored elementtype is 'invoice', not 'facture': ActionComm::create() rewrites it.
     */
    private function countAllActionComms(int $invoiceId): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "actioncomm"
            . " WHERE fk_element = " . $invoiceId . " AND elementtype = 'invoice'";
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

    /**
     * stancerSendOrderMailModele() is also called with invoices. Its dedup must then read
     * the events back as 'invoice' (the value ActionComm::create stores for a Facture),
     * not as the hardcoded 'order' it used before.
     */
    public function testOrderMailDedupMatchesInvoiceEvent(): void
    {
        global $conf;
        $conf->global->MAIN_MAIL_EMAIL_FROM = 'test@example.com';

        $soc = $this->createTestSociete(['name' => 'Order Mail On Invoice Test']);
        $invoice = $this->createTestInvoice($soc);

        $this->insertActionComm((int) $invoice->id, (int) $soc->id, 'AC_EMAIL', 'AC_BILL_CBSTART_SENTBYMAIL');
        $before = $this->countAllActionComms((int) $invoice->id);

        $result = stancerSendOrderMailModele('AnyTemplate', $invoice, 'BILL_CBSTART_SENTBYMAIL', 0);

        $this->assertSame(0, $result, 'Expected dedup short-circuit (return 0) on an invoice');
        $this->assertSame($before, $this->countAllActionComms((int) $invoice->id), 'No new ActionComm must be created when dedup matches');
    }

    /**
     * The reverse mistake must not happen either: an event attached to an ORDER carrying
     * the same rowid as the invoice must never silence the invoice mail.
     */
    public function testOrderMailDedupDoesNotMatchAnotherObjectType(): void
    {
        global $conf;
        $conf->global->MAIN_MAIL_EMAIL_FROM = 'test@example.com';

        $soc = $this->createTestSociete(['name' => 'Cross Element Dedup Test']);
        $invoice = $this->createTestInvoice($soc);

        // Same rowid, same thirdparty, but attached to an order: stored as elementtype='order'
        $this->insertActionComm((int) $invoice->id, (int) $soc->id, 'AC_EMAIL', 'AC_BILL_CBSTART_SENTBYMAIL', 'commande');

        // Dedup must not match -> the function goes on and returns null on the empty recipient
        $result = stancerSendOrderMailModele('AnyTemplate', $invoice, 'BILL_CBSTART_SENTBYMAIL', 0);

        $this->assertNull($result, 'An event of another element type must not deduplicate this mail');
    }

    /**
     * Track id prefix, ActionComm elementtype, template type and document directory must
     * all follow the object type, since the CB payment start accepts five of them.
     */
    public function testMailContextFollowsObjectType(): void
    {
        global $conf;

        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
        require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
        require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';
        require_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';

        $soc = $this->createTestSociete(['name' => 'Mail Context Test']);
        $invoice = $this->createTestInvoice($soc);

        $ctx = stancerGetObjectMailContext($invoice);
        $this->assertSame('inv', $ctx['trackidprefix'], "'ord' would make the email collector answer to a Commande");
        $this->assertSame('invoice', $ctx['elementtype'], 'ActionComm::create() rewrites facture into invoice');
        $this->assertSame('facture_send', $ctx['templatetype']);
        $this->assertNotEmpty($ctx['diroutput'], 'The invoice PDF directory must be resolved');

        $expected = [
            'commande' => ['ord', 'order', 'order_send'],
            'propal' => ['pro', 'propal', 'propal_send'],
            'member' => ['mem', 'member', 'member'],
            // No prefix is decoded for donations, so none must be emitted
            'don' => ['', 'don', ''],
        ];
        $objects = [
            'commande' => new \Commande($this->db),
            'propal' => new \Propal($this->db),
            'member' => new \Adherent($this->db),
            'don' => new \Don($this->db),
        ];
        foreach ($objects as $key => $object) {
            $ctx = stancerGetObjectMailContext($object);
            $this->assertSame($expected[$key][0], $ctx['trackidprefix'], "Wrong track id prefix for $key");
            $this->assertSame($expected[$key][1], $ctx['elementtype'], "Wrong ActionComm elementtype for $key");
            $this->assertSame($expected[$key][2], $ctx['templatetype'], "Wrong template type for $key");
        }

        // Unknown type: everything must be neutral rather than wrong
        $unknown = new \stdClass();
        $unknown->element = 'somethingelse';
        $ctx = stancerGetObjectMailContext($unknown);
        $this->assertSame('', $ctx['trackidprefix'], 'An unsupported type must emit no track id at all');
        $this->assertSame('', $ctx['templatetype']);
        $this->assertSame('', $ctx['diroutput']);

        // The document directory follows the object too, multi entity first
        $conf->commande = isset($conf->commande) && is_object($conf->commande) ? $conf->commande : new \stdClass();
        $conf->commande->dir_output = DOL_DATA_ROOT . '/commande';
        $order = new \Commande($this->db);
        $order->entity = 0;
        $this->assertSame(DOL_DATA_ROOT . '/commande', stancerGetObjectMailContext($order)['diroutput']);

        $conf->commande->multidir_output = [(int) $conf->entity => DOL_DATA_ROOT . '/commande-entity'];
        $order->entity = (int) $conf->entity;
        $this->assertSame(DOL_DATA_ROOT . '/commande-entity', stancerGetObjectMailContext($order)['diroutput']);
    }
}
