<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Integration tests for Stancer triggers
 */
class StancerTriggersIntegrationTest extends DolibarrRealTestCase
{
    protected $trigger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupHttpMock();
        $this->configureStancerSettings();

        // Load the trigger class
        require_once __DIR__ . '/../../../core/triggers/interface_99_modStancer_StancerTriggers.class.php';

        $this->trigger = new \InterfaceStancerTriggers($this->db);
    }

    protected function tearDown(): void
    {
        $this->teardownHttpMock();
        parent::tearDown();
    }

    // =========================================================================
    // Trigger Constructor & Basic Tests
    // =========================================================================

    public function testTriggerCanBeInstantiated(): void
    {
        $this->assertInstanceOf(\InterfaceStancerTriggers::class, $this->trigger);
    }

    public function testTriggerHasCorrectName(): void
    {
        $name = $this->trigger->getName();
        $this->assertEquals('StancerTriggers', $name);
    }

    public function testTriggerHasDescription(): void
    {
        $desc = $this->trigger->getDesc();
        $this->assertNotEmpty($desc);
        $this->assertStringContainsString('Stancer', $desc);
    }

    // =========================================================================
    // runTrigger() Tests
    // =========================================================================

    public function testRunTriggerReturnsZeroWhenModuleDisabled(): void
    {
        global $conf, $user, $langs;
        $conf->stancer->enabled = 0;

        $soc = $this->createTestSociete(['name' => 'Trigger Test Company']);
        $invoice = $this->createTestInvoice($soc);

        $result = $this->trigger->runTrigger('BILL_PAYED', $invoice, $user, $langs, $conf);

        $this->assertEquals(0, $result);
    }

    public function testRunTriggerHandlesBillPayedAction(): void
    {
        global $conf, $user, $langs;
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS = '1';
        // Disable auto mail to avoid side effects
        $conf->global->STANCER_AUTO_MAIL_INVOICES_SEPA_PAID = '';
        $conf->global->STANCER_AUTO_MAIL_INVOICES_CB = '';

        $soc = $this->createTestSociete(['name' => 'Bill Payed Trigger Test']);
        $invoice = $this->createTestInvoice($soc);
        $invoice->mode_reglement_code = 'PRE';
        $invoice->fk_account = 1;

        $result = $this->trigger->runTrigger('BILL_PAYED', $invoice, $user, $langs, $conf);

        // 0 means no error (trigger executed but no action taken due to config)
        $this->assertEquals(0, $result);
    }

    public function testRunTriggerIgnoresUnknownActions(): void
    {
        global $conf, $user, $langs;
        $conf->stancer->enabled = 1;

        $soc = $this->createTestSociete(['name' => 'Unknown Action Test']);
        $invoice = $this->createTestInvoice($soc);

        $result = $this->trigger->runTrigger('UNKNOWN_ACTION', $invoice, $user, $langs, $conf);

        // 0 = no error, trigger ignored unknown action
        $this->assertEquals(0, $result);
    }

    public function testRunTriggerSEPAInvoiceWithMailEnabled(): void
    {
        global $conf, $user, $langs;
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS = '1';
        $conf->global->STANCER_AUTO_MAIL_INVOICES_SEPA_PAID = '1';
        $conf->global->STANCER_AUTO_MAIL_INVOICES_SEPA_PAID_MAILTYPE = '';

        $soc = $this->createTestSociete(['name' => 'SEPA Mail Trigger Test']);
        $invoice = $this->createTestInvoice($soc);
        $invoice->mode_reglement_code = 'PRE';
        $invoice->fk_account = 1;

        $result = $this->trigger->runTrigger('BILL_PAYED', $invoice, $user, $langs, $conf);

        // No error expected (mail sending would fail silently without mail config)
        $this->assertEquals(0, $result);
    }

    public function testRunTriggerCBInvoiceWithMailEnabled(): void
    {
        global $conf, $user, $langs;
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS = '1';
        $conf->global->STANCER_ENABLE_CB = '1';
        $conf->global->STANCER_AUTO_MAIL_INVOICES_CB = '1';
        $conf->global->STANCER_AUTO_MAIL_INVOICES_CB_MAILTYPE = '';

        $soc = $this->createTestSociete(['name' => 'CB Mail Trigger Test']);
        $invoice = $this->createTestInvoice($soc);
        $invoice->mode_reglement_code = 'CB';
        $invoice->fk_account = 1;

        $result = $this->trigger->runTrigger('BILL_PAYED', $invoice, $user, $langs, $conf);

        // No error expected
        $this->assertEquals(0, $result);
    }

    public function testRunTriggerDoesNotSendMailForOtherPaymentModes(): void
    {
        global $conf, $user, $langs;
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS = '1';
        $conf->global->STANCER_AUTO_MAIL_INVOICES_SEPA_PAID = '1';
        $conf->global->STANCER_AUTO_MAIL_INVOICES_CB = '1';

        $soc = $this->createTestSociete(['name' => 'Other Payment Mode Test']);
        $invoice = $this->createTestInvoice($soc);
        $invoice->mode_reglement_code = 'CHQ';
        $invoice->fk_account = 1;

        $result = $this->trigger->runTrigger('BILL_PAYED', $invoice, $user, $langs, $conf);

        // Should return 0 (no action taken)
        $this->assertEquals(0, $result);
    }

    public function testRunTriggerDoesNotSendMailForDifferentBankAccount(): void
    {
        global $conf, $user, $langs;
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_BANK_ACCOUNT_FOR_PAYMENTS = '1';
        $conf->global->STANCER_AUTO_MAIL_INVOICES_SEPA_PAID = '1';

        $soc = $this->createTestSociete(['name' => 'Different Bank Test']);
        $invoice = $this->createTestInvoice($soc);
        $invoice->mode_reglement_code = 'PRE';
        $invoice->fk_account = 999;

        $result = $this->trigger->runTrigger('BILL_PAYED', $invoice, $user, $langs, $conf);

        // Should return 0 (no action due to different bank account)
        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE / _PAYED tests
    // (global option: send invoices by mail regardless of payment mode)
    // =========================================================================

    /**
     * Reset all auto-mail options to a clean baseline so each test starts neutral
     */
    protected function resetAutoMailOptions(): void
    {
        global $conf;
        $conf->global->STANCER_AUTO_MAIL_INVOICES_SEPA_PAID = '';
        $conf->global->STANCER_AUTO_MAIL_INVOICES_SEPA_PAID_MAILTYPE = '';
        $conf->global->STANCER_AUTO_MAIL_INVOICES_CB = '';
        $conf->global->STANCER_AUTO_MAIL_INVOICES_CB_MAILTYPE = '';
        $conf->global->STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE = '';
        $conf->global->STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE_MAILTYPE = '';
        $conf->global->STANCER_AUTO_MAIL_ALL_INVOICES_PAYED = '';
        $conf->global->STANCER_AUTO_MAIL_ALL_INVOICES_PAYED_MAILTYPE = '';
    }

    /**
     * Count ActionComm rows for a given invoice and code
     */
    protected function countActionComm(\Facture $invoice, string $code): int
    {
        $sql = "SELECT COUNT(*) AS cnt FROM " . MAIN_DB_PREFIX . "actioncomm";
        $sql .= " WHERE fk_element = " . (int) $invoice->id;
        $sql .= " AND elementtype = 'facture'";
        $sql .= " AND code = '" . $this->db->escape($code) . "'";
        $res = $this->db->query($sql);
        if (!$res) {
            return 0;
        }
        $obj = $this->db->fetch_object($res);
        return (int) $obj->cnt;
    }

    public function testBillValidateDoesNothingWhenAllInvoicesOptionDisabled(): void
    {
        global $conf, $user, $langs;
        $conf->stancer->enabled = 1;
        $this->resetAutoMailOptions();

        $soc = $this->createTestSociete(['name' => 'Validate Disabled Test']);
        $invoice = $this->createTestInvoice($soc);
        $invoice->mode_reglement_code = 'CHQ';

        $result = $this->trigger->runTrigger('BILL_VALIDATE', $invoice, $user, $langs, $conf);

        $this->assertEquals(0, $result);
        // No mail action recorded
        $this->assertEquals(0, $this->countActionComm($invoice, 'AC_BILL_VALIDATE_SENTBYMAIL'));
    }

    public function testBillValidateSkipsWhenNoMailTemplateConfigured(): void
    {
        global $conf, $user, $langs;
        $conf->stancer->enabled = 1;
        $this->resetAutoMailOptions();
        // Option enabled but no template configured: must skip silently
        $conf->global->STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE = '1';
        $conf->global->STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE_MAILTYPE = '';

        $soc = $this->createTestSociete(['name' => 'Validate No Template Test']);
        $invoice = $this->createTestInvoice($soc);
        $invoice->mode_reglement_code = 'CHQ';

        $result = $this->trigger->runTrigger('BILL_VALIDATE', $invoice, $user, $langs, $conf);

        $this->assertEquals(0, $result);
        $this->assertEquals(0, $this->countActionComm($invoice, 'AC_BILL_VALIDATE_SENTBYMAIL'));
    }

    public function testBillValidateTriggersOnAnyPaymentModeWhenOptionEnabled(): void
    {
        global $conf, $user, $langs;
        $conf->stancer->enabled = 1;
        $this->resetAutoMailOptions();
        $conf->global->STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE = '1';
        // Use a non-existent template label: stancerSendInvoiceMailModele will not
        // find an email template, but it must still consider the option active and
        // attempt to process. We just verify the trigger does not error out.
        $conf->global->STANCER_AUTO_MAIL_ALL_INVOICES_VALIDATE_MAILTYPE = 'StancerTestNonExistentTemplate';

        $soc = $this->createTestSociete(['name' => 'Validate Cheque Test']);
        $soc->email = '';
        $invoice = $this->createTestInvoice($soc);
        $invoice->mode_reglement_code = 'CHQ';
        $invoice->fk_account = 0;

        $result = $this->trigger->runTrigger('BILL_VALIDATE', $invoice, $user, $langs, $conf);

        // Trigger itself does not return error, even if mail send is skipped
        // (no thirdparty email -> early return inside stancerSendInvoiceMailModele).
        $this->assertEquals(0, $result);
    }

    public function testBillPayedAllInvoicesOptionDoesNothingWhenDisabled(): void
    {
        global $conf, $user, $langs;
        $conf->stancer->enabled = 1;
        $this->resetAutoMailOptions();

        $soc = $this->createTestSociete(['name' => 'Payed Cheque Disabled Test']);
        $invoice = $this->createTestInvoice($soc);
        $invoice->mode_reglement_code = 'CHQ';
        $invoice->fk_account = 999;

        $result = $this->trigger->runTrigger('BILL_PAYED', $invoice, $user, $langs, $conf);

        $this->assertEquals(0, $result);
        $this->assertEquals(0, $this->countActionComm($invoice, 'AC_BILL_PAYED_SENTBYMAIL'));
    }

    public function testBillPayedAllInvoicesOptionFiresForNonStancerInvoice(): void
    {
        global $conf, $user, $langs;
        $conf->stancer->enabled = 1;
        $this->resetAutoMailOptions();
        $conf->global->STANCER_AUTO_MAIL_ALL_INVOICES_PAYED = '1';
        $conf->global->STANCER_AUTO_MAIL_ALL_INVOICES_PAYED_MAILTYPE = 'StancerTestNonExistentTemplate';

        $soc = $this->createTestSociete(['name' => 'Payed Cheque Enabled Test']);
        $soc->email = '';
        $invoice = $this->createTestInvoice($soc);
        // Non-Stancer mode: cheque, no Stancer bank account
        $invoice->mode_reglement_code = 'CHQ';
        $invoice->fk_account = 999;

        $result = $this->trigger->runTrigger('BILL_PAYED', $invoice, $user, $langs, $conf);

        // No error returned. The mail itself may not be sent (no template, no
        // recipient), but the new code path is exercised without breaking.
        $this->assertEquals(0, $result);
    }

    public function testBillPayedSkipsWhenNoMailTemplateConfigured(): void
    {
        global $conf, $user, $langs;
        $conf->stancer->enabled = 1;
        $this->resetAutoMailOptions();
        $conf->global->STANCER_AUTO_MAIL_ALL_INVOICES_PAYED = '1';
        $conf->global->STANCER_AUTO_MAIL_ALL_INVOICES_PAYED_MAILTYPE = '';

        $soc = $this->createTestSociete(['name' => 'Payed No Template Test']);
        $invoice = $this->createTestInvoice($soc);
        $invoice->mode_reglement_code = 'CHQ';

        $result = $this->trigger->runTrigger('BILL_PAYED', $invoice, $user, $langs, $conf);

        $this->assertEquals(0, $result);
        $this->assertEquals(0, $this->countActionComm($invoice, 'AC_BILL_PAYED_SENTBYMAIL'));
    }
}
