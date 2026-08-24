<?php

namespace Stancer\Tests\IntegrationDolibarr;

/**
 * Integration tests for the printNewTable hook implemented in
 * class/actions_stancer.class.php.
 *
 * The hook is fired by societe/paymentmodes.php at the bottom of the page to
 * let third-party PSP modules render their own table. Since Dolibarr 18 this
 * is how Stancer should display the stored cards for a customer; before that
 * the module relied on stancerFakeStripeModuleEnable() to fake isModEnabled
 * ('stripe') and piggyback the core "Carte de credit" block.
 */
class StancerPrintNewTableIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../class/actions_stancer.class.php';

        global $conf;
        if (!isset($conf->stancer) || !is_object($conf->stancer)) {
            $conf->stancer = new \stdClass();
        }
        $conf->stancer->enabled = 1;
        $conf->modules['stancer'] = $conf->stancer;
    }

    /**
     * Wipe the rows we are about to insert so reruns stay deterministic.
     */
    private function deleteRibForSoc(int $socid): void
    {
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "societe_rib WHERE fk_soc = " . $socid);
    }

    /**
     * Insert a card row in llx_societe_rib mimicking what
     * stancerSaveCardOnSocieteRib() produces.
     */
    private function insertStancerCard(int $socid, array $overrides = []): int
    {
        $defaults = [
            'label' => 'stancer-card-tst',
            'type' => 'card',
            'last_four' => '4242',
            'exp_date_month' => 12,
            'exp_date_year' => 2030,
            'proprio' => 'Jane Doe',
            'country_code' => 'FR',
            'default_rib' => 0,
            'card_type' => 'visa',
            'stancer_object_ref' => 'card_TestRef123',
            'stancer_account' => 'cust_TestAcct456',
        ];
        $data = array_merge($defaults, $overrides);

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "societe_rib"
            . " (fk_soc, label, type, last_four, exp_date_month, exp_date_year,"
            . " proprio, country_code, default_rib, card_type,"
            . " stancer_object_ref, stancer_account, datec, tms)"
            . " VALUES ("
            . $socid . ","
            . "'" . $this->db->escape($data['label']) . "',"
            . "'" . $this->db->escape($data['type']) . "',"
            . "'" . $this->db->escape($data['last_four']) . "',"
            . (int) $data['exp_date_month'] . ","
            . (int) $data['exp_date_year'] . ","
            . "'" . $this->db->escape($data['proprio']) . "',"
            . "'" . $this->db->escape($data['country_code']) . "',"
            . (int) $data['default_rib'] . ","
            . "'" . $this->db->escape($data['card_type']) . "',"
            . "'" . $this->db->escape($data['stancer_object_ref']) . "',"
            . "'" . $this->db->escape($data['stancer_account']) . "',"
            . "'" . $this->db->idate(dol_now()) . "',"
            . "'" . $this->db->idate(dol_now()) . "'"
            . ")";

        $ok = $this->db->query($sql);
        $this->assertNotFalse($ok, 'Insert into societe_rib failed: ' . $this->db->lasterror());
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . "societe_rib");
    }

    public function testPrintNewTableSkipsWhenContextDoesNotMatch(): void
    {
        $soc = $this->createTestSociete();

        $actions = new \ActionsStancer($this->db);
        $parameters = ['currentcontext' => 'someothercontext'];
        $object = $soc;
        $action = '';
        $hookmanager = null;

        $ret = $actions->printNewTable($parameters, $object, $action, $hookmanager);

        $this->assertSame(0, $ret);
        $this->assertEmpty($actions->resprints, 'Hook must produce no output outside the thirdpartybancard context');
    }

    public function testPrintNewTableSkipsWhenNoCardForThirdparty(): void
    {
        $soc = $this->createTestSociete();
        $this->deleteRibForSoc((int) $soc->id);

        $actions = new \ActionsStancer($this->db);
        $parameters = ['currentcontext' => 'thirdpartybancard'];
        $object = $soc;
        $action = '';
        $hookmanager = null;

        $ret = $actions->printNewTable($parameters, $object, $action, $hookmanager);

        $this->assertSame(0, $ret);
        $this->assertEmpty($actions->resprints, 'Hook must not render a table when the customer has no Stancer card');
    }

    public function testPrintNewTableRendersCardsForThirdparty(): void
    {
        $soc = $this->createTestSociete();
        $this->deleteRibForSoc((int) $soc->id);
        $this->insertStancerCard((int) $soc->id, [
            'label' => 'stancer-card-tst',
            'last_four' => '4242',
            'proprio' => 'Jane Doe',
            'stancer_object_ref' => 'card_AbCdEf123456',
            'default_rib' => 1,
        ]);

        $actions = new \ActionsStancer($this->db);
        $parameters = ['currentcontext' => 'thirdpartybancard'];
        $object = $soc;
        $action = '';
        $hookmanager = null;

        $ret = $actions->printNewTable($parameters, $object, $action, $hookmanager);

        $this->assertSame(0, $ret);
        $this->assertNotEmpty($actions->resprints, 'Hook must render HTML when a card exists');
        $html = $actions->resprints;
        $this->assertStringContainsString('stancer-card-tst', $html, 'Label must appear in the rendered table');
        $this->assertStringContainsString('4242', $html, 'Last 4 digits must appear in the rendered table');
        $this->assertStringContainsString('Jane Doe', $html, 'Card holder name must appear in the rendered table');
        $this->assertStringContainsString('card_AbCdEf123456', $html, 'Stancer object ref must appear in the rendered table');
        $this->assertStringContainsString('manage.stancer.com', $html, 'Link to the Stancer dashboard must be present');
    }

    public function testPrintNewTableIgnoresNonStancerCards(): void
    {
        $soc = $this->createTestSociete();
        $this->deleteRibForSoc((int) $soc->id);

        // Insert a plain (non-Stancer) card and a SEPA mandate. Neither should
        // appear in the Stancer-specific table (the SEPA BAN row also has the
        // wrong type but we add it to be sure the WHERE on type='card' filters).
        $this->insertStancerCard((int) $soc->id, [
            'label' => 'random-other-card',
            'stancer_object_ref' => '',
        ]);
        $this->insertStancerCard((int) $soc->id, [
            'label' => 'stancer-sepa',
            'type' => 'ban',
            'stancer_object_ref' => 'sepa_Test',
        ]);

        $actions = new \ActionsStancer($this->db);
        $parameters = ['currentcontext' => 'thirdpartybancard'];
        $object = $soc;
        $action = '';
        $hookmanager = null;

        $ret = $actions->printNewTable($parameters, $object, $action, $hookmanager);

        $this->assertSame(0, $ret);
        $this->assertEmpty(
            $actions->resprints,
            'Hook must not render anything when the only rows are non-Stancer cards or BAN mandates'
        );
    }

    public function testPrintNewTableSkipsWhenObjectHasNoId(): void
    {
        $actions = new \ActionsStancer($this->db);
        $parameters = ['currentcontext' => 'thirdpartybancard'];
        $object = new \stdClass();
        $object->id = 0;
        $action = '';
        $hookmanager = null;

        $ret = $actions->printNewTable($parameters, $object, $action, $hookmanager);

        $this->assertSame(0, $ret);
        $this->assertEmpty($actions->resprints, 'Hook must not query the DB when object has no id');
    }
}
