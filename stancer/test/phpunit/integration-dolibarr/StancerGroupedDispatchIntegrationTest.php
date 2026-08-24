<?php

namespace Stancer\Tests\IntegrationDolibarr;

/**
 * Integration test for audit finding M5: in stancerAddPaymentOnInvoices() the
 * rounding residue (target - dispatched) was added in full to the last non-zero
 * invoice, with no per-invoice cap. When the captured amount exceeds the sum of
 * the invoices' remaining-to-pay, that surplus over-paid one invoice (the solo
 * path has Guard 3, the grouped path did not).
 *
 * The fix must never impute a positive surplus: each invoice keeps its exact
 * remaining, and the residue is logged for manual action.
 */
class StancerGroupedDispatchIntegrationTest extends DolibarrRealTestCase
{
    /** @var int */
    private $bankAccountId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        global $conf;

        require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
        dol_include_once('/stancer/lib/stancer.lib.php');
        dol_include_once('/stancer/lib/stancer_bank.lib.php');

        if (!isset($conf->banque) || !is_object($conf->banque)) {
            $conf->banque = new \stdClass();
        }
        $conf->banque->enabled = 1;

        $this->bankAccountId = $this->createBankAccount();
        $this->configureStancerSettings([
            'STANCER_BANK_ACCOUNT_FOR_PAYMENTS' => (string) $this->bankAccountId,
        ]);
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
        parent::tearDown();
    }

    public function testGroupedDispatchDoesNotOverpayInvoices(): void
    {
        $soc = $this->createTestSociete(['name' => 'M5Group']);
        $inv1 = $this->buildValidatedInvoice($soc, 10.00); // remaining 10
        $inv2 = $this->buildValidatedInvoice($soc, 10.00); // remaining 10
        $ttc1 = (float) $inv1->total_ttc;
        $ttc2 = (float) $inv2->total_ttc;

        // Captured MORE than the sum of remainings (20): 25.
        $data = array(
            'payment_id'      => 'paym_m5_' . uniqid(),
            'date'            => dol_print_date(dol_now(), '%Y-%m-%d'),
            'FinalPaymentAmt' => 25.00,
            'paymentTypeId'   => (int) dol_getIdFromCode($this->db, 'CB', 'c_paiement', 'code', 'id', 1),
            'ipaddress'       => 'test',
            'TRANSACTIONID'   => 'paym_m5',
            'service'         => 'stancer',
            'paymentmethod'   => 'card',
        );

        $errorMessage = '';
        $res = stancerAddPaymentOnInvoices(array($inv1, $inv2), $data, $errorMessage);
        $this->assertSame(0, $res, 'Grouped payment should succeed: ' . $errorMessage);

        $inv1->fetch($inv1->id);
        $inv2->fetch($inv2->id);
        $paid1 = (float) $inv1->getSommePaiement();
        $paid2 = (float) $inv2->getSommePaiement();

        // Neither invoice is over-paid (payments must not exceed its total_ttc).
        $this->assertLessThanOrEqual($ttc1 + 0.01, $paid1, 'M5: invoice 1 must not be over-paid');
        $this->assertLessThanOrEqual($ttc2 + 0.01, $paid2, 'M5: invoice 2 must not be over-paid');

        // Total dispatched must stay at the sum of remainings (20), not 25.
        $this->assertLessThanOrEqual(
            $ttc1 + $ttc2 + 0.01,
            $paid1 + $paid2,
            'M5: total dispatched must not exceed the sum of the invoices remaining-to-pay'
        );
    }

    private function createBankAccount(): int
    {
        require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
        global $mysoc;
        $acc = new \Account($this->db);
        $acc->ref           = 'M5TEST' . uniqid();
        $acc->label         = 'M5 Test Bank';
        $acc->country_id    = (int) (!empty($mysoc->country_id) ? $mysoc->country_id : 1);
        $acc->date_solde    = dol_now();
        $acc->solde         = 0;
        $acc->currency_code = 'EUR';
        $acc->type          = 1;
        $acc->courant       = 1;
        $acc->clos          = 0;
        $id = $acc->create($this->testUser);
        $this->assertGreaterThan(0, $id, 'createBankAccount failed: ' . $acc->error);
        return (int) $id;
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
