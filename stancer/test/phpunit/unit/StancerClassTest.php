<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Stancer class
 */
class StancerClassTest extends TestCase
{
    private $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(\DoliDB::class);
    }

    public function testStancerClassCanBeInstantiated(): void
    {
        require_once __DIR__ . '/../../../class/stancer.class.php';

        $stancer = new \Stancer($this->db);

        $this->assertInstanceOf(\Stancer::class, $stancer);
        $this->assertEquals('stancer', $stancer->module);
        $this->assertEquals('Stancer', $stancer->element);
    }

    public function testStancerFakeStripeClassExists(): void
    {
        require_once __DIR__ . '/../../../class/stancer.class.php';

        $fakeStripe = new \StancerFakeStripe();

        $this->assertTrue($fakeStripe->enabled);
        $this->assertEquals('stripe', $fakeStripe->module);
        $this->assertEquals('Stripe', $fakeStripe->element);
    }

    /**
     * Pin down the Stancer hook priority. Dolibarr's HookManager sorts
     * doAddButton handlers by `$priority`, lower first. We want Stancer's
     * payment button to render BEFORE other PSP modules (Mollie, Payzen,
     * Stripe...) that typically leave priority undefined (defaults to 50).
     * If anyone bumps this value above 50, the button order regresses.
     *
     * We grep the source instead of instantiating ActionsStancer because the
     * class pulls heavy Dolibarr dependencies that the unit-test mock layer
     * does not stub (e.g. fourn/class/paiementfourn.class.php).
     */
    public function testActionsStancerPriorityIsLowerThanDefault(): void
    {
        $classPath = __DIR__ . '/../../../class/actions_stancer.class.php';
        $this->assertFileExists($classPath);

        $content = file_get_contents($classPath);

        // Match `public $priority = NN;` in the class declaration
        $this->assertMatchesRegularExpression('/public\s+\$priority\s*=\s*(\d+)\s*;/', $content);

        preg_match('/public\s+\$priority\s*=\s*(\d+)\s*;/', $content, $m);
        $priority = (int) $m[1];

        $this->assertLessThan(50, $priority, 'ActionsStancer::priority must be < 50 to render before default-priority PSP modules (Mollie, Payzen...)');
        $this->assertEquals(30, $priority, 'Expected Stancer priority is 30 (current convention). Bumping it requires updating the doc.');
    }

    public function testDoScheduledJobReturnsErrorWhenModuleDisabled(): void
    {
        global $conf;
        $conf->stancer = new \stdClass();
        $conf->stancer->enabled = 0;

        require_once __DIR__ . '/../../../class/stancer.class.php';

        $stancer = new \Stancer($this->db);
        $result = $stancer->doScheduledJob();

        $this->assertEquals(-1, $result);
        $this->assertEquals('Error, Stancer module not enabled', $stancer->error);
    }

    public function testDoScheduledJobReturnsErrorWhenNotInProdMode(): void
    {
        global $conf;
        $conf->stancer = new \stdClass();
        $conf->stancer->enabled = 1;
        $conf->global->STANCER_IS_PROD = '0';

        require_once __DIR__ . '/../../../class/stancer.class.php';

        $stancer = new \Stancer($this->db);
        $result = $stancer->doScheduledJob();

        $this->assertEquals(-1, $result);
        $this->assertEquals('Error, Stancer module is not in production mode', $stancer->error);
    }

    public function testDoTakePaymentStancerReturnsErrorWhenModuleDisabled(): void
    {
        global $conf;
        $conf->stancer = new \stdClass();
        $conf->stancer->enabled = 0;

        require_once __DIR__ . '/../../../class/stancer.class.php';

        $stancer = new \Stancer($this->db);
        $result = $stancer->doTakePaymentStancer();

        $this->assertEquals(-1, $result);
        $this->assertEquals('Error, Stancer module not enabled', $stancer->error);
    }

    public function testDoCheckInvoicesPaidReturnsErrorWhenModuleDisabled(): void
    {
        global $conf;
        $conf->stancer = new \stdClass();
        $conf->stancer->enabled = 0;

        require_once __DIR__ . '/../../../class/stancer.class.php';

        $stancer = new \Stancer($this->db);
        $result = $stancer->doCheckInvoicesPaid();

        $this->assertEquals(-1, $result);
        $this->assertEquals('Error, Stancer module not enabled', $stancer->error);
    }
}
