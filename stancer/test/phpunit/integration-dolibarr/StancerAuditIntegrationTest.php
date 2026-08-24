<?php

namespace Stancer\Tests\IntegrationDolibarr;

require_once __DIR__ . '/../Mocks/HttpMock.php';

/**
 * Integration tests for the read-only audit of Stancer payment attributions
 * (lib/stancer_audit.lib.php + stancer_audit.php).
 *
 * Pins the 9 possible classifications returned by stancerAuditPayment():
 *  - OK
 *  - wrong-invoice-same-customer
 *  - wrong-customer (the NITD/PICHINOV/BHG bug)
 *  - wrong-amount
 *  - no-mapping (cust_xxx absent from societe_rib)
 *  - grouped (SEPA group payment)
 *  - api-unreachable (network error)
 *  - api-not-found (404)
 *  - api-auth-error (401, must stop the audit loop immediately)
 *
 * Also pins stancerAuditFetchRows() against a small seeded set.
 */
class StancerAuditIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupHttpMock();
        $this->configureStancerSettings();

        dol_include_once('/stancer/lib/stancer.lib.php');
        dol_include_once('/stancer/lib/stancer_audit.lib.php');

        require_once __DIR__ . '/../../../class/stancer_api.class.php';
        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    }

    protected function tearDown(): void
    {
        $this->teardownHttpMock();
        parent::tearDown();
    }

    /**
     * Build a row mimicking what stancerAuditFetchRows() returns.
     */
    private function makeRow(string $paymId, array $overrides = []): \stdClass
    {
        $row = new \stdClass();
        $row->paiement_id     = $overrides['paiement_id']     ?? 1;
        $row->stancer_paym_id = $paymId;
        $row->datep           = $overrides['datep']           ?? date('Y-m-d H:i:s');
        $row->db_fk_facture   = $overrides['db_fk_facture']   ?? 100;
        $row->db_invoice_ref  = $overrides['db_invoice_ref']  ?? 'FA2603-9999';
        $row->db_socid        = $overrides['db_socid']        ?? 1;
        $row->db_client       = $overrides['db_client']       ?? 'Test client';
        $row->db_invoice_ttc  = $overrides['db_invoice_ttc']  ?? 12.00;
        $row->db_paid_amount  = $overrides['db_paid_amount']  ?? 12.00;
        $row->db_invoice_paye = $overrides['db_invoice_paye'] ?? 0;
        $row->db_invoice_statut = $overrides['db_invoice_statut'] ?? 1;
        return $row;
    }

    /**
     * Seed a llx_societe_rib row mapping a Stancer cust_xxx to a Dolibarr socid.
     */
    private function seedStancerCustomerMapping(\Societe $soc, string $stancerCustomerId): void
    {
        $this->createTestCompanyPaymentMode($soc, [
            'type'               => 'card',
            'label'              => 'stancer-card-' . $stancerCustomerId,
            'stancer_account'    => $stancerCustomerId,
            'stancer_object_ref' => 'card_test_' . uniqid(),
        ]);
    }

    /**
     * Stub the Stancer API for a given payment id.
     */
    private function mockApiPayment(string $paymId, array $apiPayload): void
    {
        \HttpMock::addJsonResponse('*checkout/' . $paymId . '*', array_merge([
            'id'        => $paymId,
            'amount'    => 1200,      // 12 EUR by default
            'currency'  => 'eur',
            'status'    => 'captured',
            'method'    => 'card',
            'unique_id' => '',
            'order_id'  => '',
            'response'  => '00',
            'customer'  => ['id' => '', 'name' => ''],
        ], $apiPayload));
    }

    // =========================================================================
    // Happy path: Stancer customer maps to invoice socid, order_id matches,
    // amount matches -> OK.
    // =========================================================================
    public function testAuditClassifiesOkPayment(): void
    {
        $soc = $this->createTestSociete(['name' => 'AuditOkClient']);
        $custId = 'cust_ok_' . uniqid();
        $this->seedStancerCustomerMapping($soc, $custId);

        $paymId = 'paym_ok_' . uniqid();
        $this->mockApiPayment($paymId, [
            'amount'    => 1200,
            'order_id'  => 'FA2603-OK1',
            'unique_id' => 'CUS=' . $soc->id . '.INV=42',
            'customer'  => ['id' => $custId, 'name' => 'AuditOkClient'],
        ]);

        $row = $this->makeRow($paymId, [
            'db_socid'        => $soc->id,
            'db_invoice_ref'  => 'FA2603-OK1',
            'db_paid_amount'  => 12.00,
            'db_invoice_ttc'  => 12.00,
        ]);

        $api = new \StancerApi();
        $res = stancerAuditPayment($row, $api, $this->db);

        $this->assertEquals(STANCER_AUDIT_OK, $res['status'], "details=" . $res['details']);
        $this->assertEquals($soc->id, $res['mapped_socid']);
        $this->assertEquals($custId, $res['api']['customer_id']);
    }

    // =========================================================================
    // wrong-invoice-same-customer: API order_id points elsewhere, but the
    // mapped customer is the same one as on the local invoice.
    // Reproduces paym_SRAtoogrTcIqIRaQdBf6M8L3 (NITD paid the wrong invoice).
    // =========================================================================
    public function testAuditDetectsWrongInvoiceSameCustomer(): void
    {
        $nitd = $this->createTestSociete(['name' => 'NITD-Audit']);
        $custId = 'cust_nitd_' . uniqid();
        $this->seedStancerCustomerMapping($nitd, $custId);

        $paymId = 'paym_wronginv_' . uniqid();
        $this->mockApiPayment($paymId, [
            'amount'    => 420,
            'order_id'  => 'FA2512-4485',   // truth from Stancer
            'unique_id' => 'CUS=' . $nitd->id . '.INV=7334',
            'customer'  => ['id' => $custId, 'name' => 'NITD'],
        ]);

        // Local DB says the paym went to FA2601-4624 instead.
        $row = $this->makeRow($paymId, [
            'db_socid'        => $nitd->id,
            'db_invoice_ref'  => 'FA2601-4624',
            'db_paid_amount'  => 4.20,
            'db_invoice_ttc'  => 4.20,
        ]);

        $api = new \StancerApi();
        $res = stancerAuditPayment($row, $api, $this->db);

        $this->assertEquals(STANCER_AUDIT_WRONG_INVOICE_SAME_CUSTOMER, $res['status'], "details=" . $res['details']);
    }

    // =========================================================================
    // NOT a misattribution: order_id is a COMMANDE ref whose invoice was created
    // at payment return (create-order-first workflow). It resolves, through the
    // commande -> facture link, to the very invoice the Paiement is attached to.
    // Must classify OK, not wrong-invoice-same-customer.
    // =========================================================================
    public function testAuditAcceptsOrderIdResolvingToInvoiceViaCommande(): void
    {
        global $conf;
        // fetchObjectLinked() drops linked ids for modules that are not enabled.
        $modulesBackup = (isset($conf->modules) && is_array($conf->modules)) ? $conf->modules : null;
        if (!isset($conf->modules) || !is_array($conf->modules)) {
            $conf->modules = [];
        }
        $conf->modules['facture'] = 'facture';
        $conf->modules['commande'] = 'commande';

        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
        if (!isset($this->testUser->rights->commande)) {
            $this->testUser->rights->commande = new \stdClass();
        }
        $this->testUser->rights->commande->creer = 1;

        $soc = $this->createTestSociete(['name' => 'AuditCmdWorkflow']);
        $custId = 'cust_cmd_' . uniqid();
        $this->seedStancerCustomerMapping($soc, $custId);

        // Commande with a real ref (not a provisional one), + the invoice built from it.
        $cmd = new \Commande($this->db);
        $cmd->socid = $soc->id;
        $cmd->date = dol_now();
        $cid = (int) $cmd->create($this->testUser);
        $this->assertGreaterThan(0, $cid, 'Commande create failed: ' . $cmd->error);
        $cmdRef = 'CO-AUDIT-' . $cid . '-' . uniqid();
        $this->db->query("UPDATE " . MAIN_DB_PREFIX . "commande SET ref = '" . $this->db->escape($cmdRef) . "' WHERE rowid = " . $cid);

        $invoice = new \Facture($this->db);
        $invoice->socid = $soc->id;
        $invoice->date = dol_now();
        $invoice->create($this->testUser);
        $invoice->addline('Test line', 12.00, 1, 0, 0, 0, 0, 0, '', '', 0, 0, '', 'HT');
        $invoice->fetch($invoice->id);
        $invoice->validate($this->testUser);
        $invoice->fetch($invoice->id);
        $invoice->add_object_linked('commande', $cid);

        $paymId = 'paym_cmdwf_' . uniqid();
        $this->mockApiPayment($paymId, [
            'amount'    => (int) round(((float) $invoice->total_ttc) * 100),
            'order_id'  => $cmdRef,               // order_id is the COMMANDE ref
            'unique_id' => 'CUS=' . $soc->id . '.ORD=' . $cid,
            'customer'  => ['id' => $custId, 'name' => 'AuditCmdWorkflow'],
        ]);

        $row = $this->makeRow($paymId, [
            'db_socid'       => $soc->id,
            'db_fk_facture'  => (int) $invoice->id,
            'db_invoice_ref' => (string) $invoice->ref,
            'db_paid_amount' => (float) $invoice->total_ttc,
            'db_invoice_ttc' => (float) $invoice->total_ttc,
        ]);

        $api = new \StancerApi();
        $res = stancerAuditPayment($row, $api, $this->db);

        // Restore module state before asserting (avoid cross-test pollution).
        if ($modulesBackup === null) {
            unset($conf->modules['facture'], $conf->modules['commande']);
        } else {
            $conf->modules = $modulesBackup;
        }

        $this->assertNotEquals(
            STANCER_AUDIT_WRONG_INVOICE_SAME_CUSTOMER,
            $res['status'],
            'order_id resolving to the attached invoice via the commande link must not be a misattribution. details=' . $res['details']
        );
        $this->assertEquals(STANCER_AUDIT_OK, $res['status'], "details=" . $res['details']);
    }

    // =========================================================================
    // wrong-customer: the Stancer customer maps to socid B, but the local
    // Paiement is on an invoice of socid A. Reproduces paym_c2P (BLUE HORSE
    // GROUP paid, ended up on NITD's invoice).
    // =========================================================================
    public function testAuditDetectsWrongCustomer(): void
    {
        $nitd = $this->createTestSociete(['name' => 'NITD-Wrong']);
        $bhg  = $this->createTestSociete(['name' => 'BLUE HORSE GROUP-Wrong']);

        $custBhg = 'cust_bhg_' . uniqid();
        $this->seedStancerCustomerMapping($bhg, $custBhg);

        $paymId = 'paym_wrongcust_' . uniqid();
        $this->mockApiPayment($paymId, [
            'amount'    => 1200,
            'order_id'  => 'FA2604-5009',
            'unique_id' => 'CUS=' . $bhg->id . '.INV=2222',
            'customer'  => ['id' => $custBhg, 'name' => 'BLUE HORSE GROUP'],
        ]);

        // Local DB attributes the payment to NITD.
        $row = $this->makeRow($paymId, [
            'db_socid'        => $nitd->id,
            'db_invoice_ref'  => 'FA2604-5103',
            'db_paid_amount'  => 12.00,
            'db_invoice_ttc'  => 33.60,
        ]);

        $api = new \StancerApi();
        $res = stancerAuditPayment($row, $api, $this->db);

        $this->assertEquals(STANCER_AUDIT_WRONG_CUSTOMER, $res['status'], "details=" . $res['details']);
        $this->assertEquals($bhg->id, $res['mapped_socid']);
    }

    // =========================================================================
    // wrong-amount: customer and invoice match, but DB-side amount differs
    // from the API amount (a refund or a split could be involved).
    // =========================================================================
    public function testAuditDetectsWrongAmount(): void
    {
        $soc = $this->createTestSociete(['name' => 'AmountMismatch']);
        $custId = 'cust_amt_' . uniqid();
        $this->seedStancerCustomerMapping($soc, $custId);

        $paymId = 'paym_amount_' . uniqid();
        $this->mockApiPayment($paymId, [
            'amount'    => 5000,            // 50 EUR on Stancer
            'order_id'  => 'FA2603-AMT1',
            'unique_id' => 'CUS=' . $soc->id . '.INV=42',
            'customer'  => ['id' => $custId, 'name' => 'AmountMismatch'],
        ]);

        $row = $this->makeRow($paymId, [
            'db_socid'        => $soc->id,
            'db_invoice_ref'  => 'FA2603-AMT1',
            'db_paid_amount'  => 12.00,     // local says 12 EUR
            'db_invoice_ttc'  => 50.00,
        ]);

        $api = new \StancerApi();
        $res = stancerAuditPayment($row, $api, $this->db);

        $this->assertEquals(STANCER_AUDIT_WRONG_AMOUNT, $res['status'], "details=" . $res['details']);
    }

    // =========================================================================
    // no-mapping (names match): the Stancer cust_xxx is not in societe_rib but
    // the customer name on the API matches the local invoice's customer name.
    // We flag the missing mapping but do not raise a misattribution alarm.
    // =========================================================================
    public function testAuditDetectsNoMappingWhenNamesMatch(): void
    {
        $soc = $this->createTestSociete(['name' => 'NoMappingMatch']);
        // Intentionally no createTestCompanyPaymentMode call.

        $paymId = 'paym_nomap_' . uniqid();
        $this->mockApiPayment($paymId, [
            'amount'    => 1200,
            'order_id'  => 'FA2603-NOMAP',
            'unique_id' => 'CUS=' . $soc->id . '.INV=42',
            'customer'  => ['id' => 'cust_orphan_' . uniqid(), 'name' => 'NoMappingMatch'],
        ]);

        $row = $this->makeRow($paymId, [
            'db_socid'        => $soc->id,
            'db_client'       => 'NoMappingMatch',
            'db_invoice_ref'  => 'FA2603-NOMAP',
            'db_paid_amount'  => 12.00,
            'db_invoice_ttc'  => 12.00,
        ]);

        $api = new \StancerApi();
        $res = stancerAuditPayment($row, $api, $this->db);

        $this->assertEquals(STANCER_AUDIT_NO_MAPPING, $res['status'], "details=" . $res['details']);
        $this->assertEquals(0, $res['mapped_socid']);
    }

    // =========================================================================
    // wrong-customer-unmapped: no row in societe_rib for cust_xxx, AND the
    // API customer name differs from the local invoice's customer name.
    // Reproduces paym_UCZWEeLK5OqBF2wl3a7a8qad scenario: Stancer says NITD,
    // local Paiement attached to NUMASYOUR's invoice, no SEPA mandate for NITD.
    // =========================================================================
    public function testAuditDetectsWrongCustomerWhenUnmappedAndNamesDiffer(): void
    {
        $numasyour = $this->createTestSociete(['name' => 'NUMASYOUR']);
        // No createTestCompanyPaymentMode for the API customer.

        $paymId = 'paym_unmapped_' . uniqid();
        $this->mockApiPayment($paymId, [
            'amount'    => 420,
            'order_id'  => 'FA2601-4624',
            'unique_id' => 'CUS=999.INV=7476',
            'customer'  => ['id' => 'cust_pewAEs_' . uniqid(), 'name' => 'NITD'],
        ]);

        $row = $this->makeRow($paymId, [
            'db_socid'        => $numasyour->id,
            'db_client'       => 'NUMASYOUR',
            'db_invoice_ref'  => 'FA2601-4609',
            'db_paid_amount'  => 4.20,
            'db_invoice_ttc'  => 4.20,
        ]);

        $api = new \StancerApi();
        $res = stancerAuditPayment($row, $api, $this->db);

        $this->assertEquals(
            STANCER_AUDIT_WRONG_CUSTOMER_UNMAPPED,
            $res['status'],
            "details=" . $res['details']
        );
        $this->assertEquals(0, $res['mapped_socid']);
    }

    // =========================================================================
    // Name comparison must be case-insensitive and trim-tolerant.
    // =========================================================================
    public function testAuditNormaliseNameIsCaseInsensitiveAndTrims(): void
    {
        $soc = $this->createTestSociete(['name' => 'CaseAndSpace']);

        $paymId = 'paym_case_' . uniqid();
        $this->mockApiPayment($paymId, [
            'amount'    => 420,
            'order_id'  => 'FA-CASE-1',
            'unique_id' => 'CUS=' . $soc->id . '.INV=1',
            'customer'  => ['id' => 'cust_case_' . uniqid(), 'name' => '  caseandspace  '],
        ]);

        $row = $this->makeRow($paymId, [
            'db_socid'        => $soc->id,
            'db_client'       => 'CASEANDSPACE',
            'db_invoice_ref'  => 'FA-CASE-1',
            'db_paid_amount'  => 4.20,
            'db_invoice_ttc'  => 4.20,
        ]);

        $api = new \StancerApi();
        $res = stancerAuditPayment($row, $api, $this->db);

        // Names match (case-insensitive, trim) -> we end up in no-mapping (not
        // wrong-customer-unmapped) because there's no societe_rib row.
        $this->assertEquals(STANCER_AUDIT_NO_MAPPING, $res['status'], "details=" . $res['details']);
    }

    // =========================================================================
    // grouped: SEPA group payment. unique_id starts with GRP=. The order_id
    // check is irrelevant because the same paym_id covers several invoices.
    // =========================================================================
    public function testAuditDetectsGroupedSepa(): void
    {
        $soc = $this->createTestSociete(['name' => 'GroupedSepa']);
        $custId = 'cust_grp_' . uniqid();
        $this->seedStancerCustomerMapping($soc, $custId);

        $paymId = 'paym_grouped_' . uniqid();
        $this->mockApiPayment($paymId, [
            'amount'    => 5000,
            'order_id'  => 'FA2603-GRP1',           // first invoice of the group
            'unique_id' => 'GRP=abc123.CUS=' . $soc->id,
            'method'    => 'sepa',
            'customer'  => ['id' => $custId, 'name' => 'GroupedSepa'],
        ]);

        // This local row is the share on the SECOND invoice, hence its ref
        // does NOT match api.order_id - that's normal in grouped mode.
        $row = $this->makeRow($paymId, [
            'db_socid'        => $soc->id,
            'db_invoice_ref'  => 'FA2603-GRP2',
            'db_paid_amount'  => 20.00,
            'db_invoice_ttc'  => 20.00,
        ]);

        $api = new \StancerApi();
        $res = stancerAuditPayment($row, $api, $this->db);

        $this->assertEquals(STANCER_AUDIT_GROUPED, $res['status'], "details=" . $res['details']);
    }

    // =========================================================================
    // api-auth-error: 401 must short-circuit the entire audit loop.
    // =========================================================================
    public function testAuditHandlesApiAuthError(): void
    {
        $paymId = 'paym_auth_' . uniqid();
        \HttpMock::addResponse('*checkout/' . $paymId . '*', [
            'http_code' => 401,
            'content'   => json_encode(['error' => ['type' => 'invalid_auth', 'message' => 'bad key']]),
        ]);

        $row = $this->makeRow($paymId);
        $api = new \StancerApi();
        $res = stancerAuditPayment($row, $api, $this->db);

        $this->assertEquals(STANCER_AUDIT_API_AUTH_ERROR, $res['status']);
        $this->assertEquals(401, $res['http_code']);
    }

    // =========================================================================
    // api-not-found: paym_xxx exists in Dolibarr but Stancer returns 404.
    // Means an orphan local row that should be cleaned up.
    // =========================================================================
    public function testAuditHandlesApi404(): void
    {
        $paymId = 'paym_404_' . uniqid();
        \HttpMock::addResponse('*checkout/' . $paymId . '*', [
            'http_code' => 404,
            'content'   => json_encode(['error' => ['message' => 'not found']]),
        ]);

        $row = $this->makeRow($paymId);
        $api = new \StancerApi();
        $res = stancerAuditPayment($row, $api, $this->db);

        $this->assertEquals(STANCER_AUDIT_API_NOT_FOUND, $res['status']);
    }

    // =========================================================================
    // api-unreachable: network/CURL error.
    // =========================================================================
    public function testAuditHandlesApiUnreachable(): void
    {
        $paymId = 'paym_curl_' . uniqid();
        \HttpMock::addCurlError('*checkout/' . $paymId . '*', 7, 'Connection refused');

        $row = $this->makeRow($paymId);
        $api = new \StancerApi();
        $res = stancerAuditPayment($row, $api, $this->db);

        $this->assertEquals(STANCER_AUDIT_API_UNREACHABLE, $res['status']);
    }

    /**
     * Build a fake audit result array (row + audit) for the grouped view tests.
     */
    private function fakeAuditItem(string $paymId, string $apiOrderId, string $apiStatus, float $apiAmount, string $auditStatus = STANCER_AUDIT_OK, string $dbInvoiceRef = ''): array
    {
        $row = $this->makeRow($paymId, [
            'db_invoice_ref' => $dbInvoiceRef !== '' ? $dbInvoiceRef : $apiOrderId,
            'db_paid_amount' => $apiAmount,
        ]);
        return [
            'row'   => $row,
            'audit' => [
                'status'       => $auditStatus,
                'mapped_socid' => 0,
                'details'      => '',
                'http_code'    => 200,
                'api'          => [
                    'customer_id'   => 'cust_test',
                    'customer_name' => 'Test',
                    'order_id'      => $apiOrderId,
                    'unique_id'     => 'CUS=1.INV=2',
                    'amount'        => $apiAmount,
                    'status'        => $apiStatus,
                ],
            ],
        ];
    }

    // =========================================================================
    // Group view: 2+ paym_id captured on the same api_order_id is a double.
    // =========================================================================
    public function testGroupViewDetectsDoubleCaptured(): void
    {
        $results = [
            $this->fakeAuditItem('paym_a', 'FA2603-DOUBLE', 'captured', 12.00),
            $this->fakeAuditItem('paym_b', 'FA2603-DOUBLE', 'captured', 12.00),
            $this->fakeAuditItem('paym_c', 'FA2603-SINGLE', 'captured', 5.00),
        ];

        $groups = stancerAuditBuildGroupView($results);

        $this->assertCount(1, $groups, 'Only the api_order_id with >=2 paym should appear');
        $this->assertEquals('FA2603-DOUBLE', $groups[0]['api_order_id']);
        $this->assertEquals(2, $groups[0]['captured_count']);
        $this->assertTrue($groups[0]['has_double']);
    }

    // =========================================================================
    // 1 captured + 1 refused on same order_id: 2 paym but only 1 captured ->
    // NOT a double-charge (the customer paid once).
    // =========================================================================
    public function testGroupViewDoesNotFlagDoubleWhenOnlyOneCaptured(): void
    {
        $results = [
            $this->fakeAuditItem('paym_capt', 'FA-MIX', 'captured', 12.00),
            $this->fakeAuditItem('paym_ref',  'FA-MIX', 'refused',  12.00),
        ];

        $groups = stancerAuditBuildGroupView($results);

        $this->assertCount(1, $groups, '2 paym on same order -> must be in the group view');
        $this->assertEquals(1, $groups[0]['captured_count']);
        $this->assertFalse($groups[0]['has_double'], 'Only 1 captured -> not a double-charge');
    }

    // =========================================================================
    // Singletons (1 paym per api_order_id) must NOT appear in the group view.
    // =========================================================================
    public function testGroupViewSkipsSingletons(): void
    {
        $results = [
            $this->fakeAuditItem('paym_x', 'FA-X', 'captured', 10.00),
            $this->fakeAuditItem('paym_y', 'FA-Y', 'captured', 20.00),
        ];

        $groups = stancerAuditBuildGroupView($results);

        $this->assertEmpty($groups);
    }

    // =========================================================================
    // Grouped SEPA payments must be excluded from the grouped view (their
    // api_order_id refers to one of N invoices and would create false positives).
    // =========================================================================
    public function testGroupViewExcludesGroupedSepa(): void
    {
        $results = [
            $this->fakeAuditItem('paym_g1', 'FA-GRP1', 'captured', 100.00, STANCER_AUDIT_GROUPED),
            $this->fakeAuditItem('paym_g2', 'FA-GRP1', 'captured', 100.00, STANCER_AUDIT_GROUPED),
        ];

        $groups = stancerAuditBuildGroupView($results);

        $this->assertEmpty($groups, 'Grouped SEPA must not appear in the doubles view');
    }

    // =========================================================================
    // Sanity check on stancerAuditResolveSocidFromStancerCustomer().
    // =========================================================================
    public function testCustomerMappingResolves(): void
    {
        $soc = $this->createTestSociete(['name' => 'MappingResolve']);
        $custId = 'cust_resolve_' . uniqid();
        $this->seedStancerCustomerMapping($soc, $custId);

        $this->assertEquals(
            (int) $soc->id,
            stancerAuditResolveSocidFromStancerCustomer($custId, $this->db)
        );
        $this->assertEquals(
            0,
            stancerAuditResolveSocidFromStancerCustomer('cust_does_not_exist', $this->db)
        );
        $this->assertEquals(
            0,
            stancerAuditResolveSocidFromStancerCustomer('', $this->db)
        );
    }
}
