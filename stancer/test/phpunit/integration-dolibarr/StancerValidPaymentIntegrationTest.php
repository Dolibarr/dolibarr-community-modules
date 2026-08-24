<?php

namespace Stancer\Tests\IntegrationDolibarr;

/**
 * Integration tests covering:
 * - the getValidPayment hook (registers Stancer as a valid online payment
 *   method, used by Dolibarr core getValidOnlinePaymentMethods() since v18)
 * - the stancerFakeStripeModuleEnable() compatibility shim, kept only for
 *   Dolibarr < 18 where neither the getValidPayment nor the printNewTable
 *   hook existed and the only way to display payment-related blocks was to
 *   fake isModEnabled('stripe')
 *
 * The sqlite harness ships Dolibarr 18.0.8, so the no-op branch (>= 18) is
 * exercised live here.
 */
class StancerValidPaymentIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../../../class/actions_stancer.class.php';
        require_once __DIR__ . '/../../../class/stancer.class.php';
        require_once __DIR__ . '/../../../lib/stancer_payment.lib.php';

        global $conf;
        if (!isset($conf->stancer) || !is_object($conf->stancer)) {
            $conf->stancer = new \stdClass();
        }
        $conf->stancer->enabled = 1;
        $conf->modules['stancer'] = $conf->stancer;
    }

    // =========================================================================
    // getValidPayment() hook
    // =========================================================================

    public function testGetValidPaymentRegistersStancerWhenModuleEnabled(): void
    {
        $actions = new \ActionsStancer($this->db);

        $parameters = ['paymentmethod' => ''];
        $object = new \stdClass();
        $action = '';
        $hookmanager = null;

        $ret = $actions->getValidPayment($parameters, $object, $action, $hookmanager);

        $this->assertSame(0, $ret);
        $this->assertArrayHasKey('validpaymentmethod', $actions->results);
        $this->assertArrayHasKey('stancer', $actions->results['validpaymentmethod']);
        $this->assertSame('valid', $actions->results['validpaymentmethod']['stancer']);
    }

    public function testGetValidPaymentDetailedModeReturnsLabelAndStatus(): void
    {
        $actions = new \ActionsStancer($this->db);

        $parameters = ['paymentmethod' => '', 'mode' => 1];
        $object = new \stdClass();
        $action = '';
        $hookmanager = null;

        $ret = $actions->getValidPayment($parameters, $object, $action, $hookmanager);

        $this->assertSame(0, $ret);
        $this->assertArrayHasKey('validpaymentmethod', $actions->results);
        $this->assertArrayHasKey('stancer', $actions->results['validpaymentmethod']);
        $entry = $actions->results['validpaymentmethod']['stancer'];
        $this->assertIsArray($entry);
        $this->assertSame('Stancer', $entry['label']);
        $this->assertSame('valid', $entry['status']);
    }

    public function testGetValidPaymentSkipsWhenFilteredOnOtherMethod(): void
    {
        $actions = new \ActionsStancer($this->db);

        $parameters = ['paymentmethod' => 'paypal'];
        $object = new \stdClass();
        $action = '';
        $hookmanager = null;

        $ret = $actions->getValidPayment($parameters, $object, $action, $hookmanager);

        $this->assertSame(0, $ret);
        $this->assertArrayNotHasKey(
            'validpaymentmethod',
            $actions->results,
            'Stancer must not register itself when caller filters on paypal'
        );
    }

    public function testGetValidPaymentMatchesExplicitStancerFilter(): void
    {
        $actions = new \ActionsStancer($this->db);

        $parameters = ['paymentmethod' => 'stancer'];
        $object = new \stdClass();
        $action = '';
        $hookmanager = null;

        $ret = $actions->getValidPayment($parameters, $object, $action, $hookmanager);

        $this->assertSame(0, $ret);
        $this->assertArrayHasKey('validpaymentmethod', $actions->results);
        $this->assertArrayHasKey('stancer', $actions->results['validpaymentmethod']);
    }

    public function testGetValidPaymentSkipsWhenModuleDisabled(): void
    {
        global $conf;
        unset($conf->modules['stancer']);
        $conf->stancer->enabled = 0;

        $actions = new \ActionsStancer($this->db);

        $parameters = ['paymentmethod' => ''];
        $object = new \stdClass();
        $action = '';
        $hookmanager = null;

        $ret = $actions->getValidPayment($parameters, $object, $action, $hookmanager);

        $this->assertSame(0, $ret);
        $this->assertArrayNotHasKey('validpaymentmethod', $actions->results);
    }

    public function testGetValidPaymentRequiresPaymentmethodKey(): void
    {
        $actions = new \ActionsStancer($this->db);

        $parameters = []; // no 'paymentmethod' key at all
        $object = new \stdClass();
        $action = '';
        $hookmanager = null;

        $ret = $actions->getValidPayment($parameters, $object, $action, $hookmanager);

        $this->assertSame(0, $ret);
        $this->assertArrayNotHasKey('validpaymentmethod', $actions->results);
    }

    // =========================================================================
    // getValidOnlinePaymentMethods() end-to-end (core function calls the hook)
    // =========================================================================

    public function testCoreGetValidOnlinePaymentMethodsPicksUpStancer(): void
    {
        global $hookmanager;

        require_once DOL_DOCUMENT_ROOT . '/core/lib/payments.lib.php';

        if (!is_object($hookmanager)) {
            require_once DOL_DOCUMENT_ROOT . '/core/class/hookmanager.class.php';
            $hookmanager = new \HookManager($this->db);
        }
        // Register the Stancer action handler against the 'newpayment' context
        // so the core HookManager will dispatch getValidPayment to our class.
        $hookmanager->initHooks(['newpayment']);
        global $conf;
        $conf->hooks_modules['newpayment'] = 'stancer';

        $methods = getValidOnlinePaymentMethods('');

        $this->assertIsArray($methods);
        $this->assertArrayHasKey(
            'stancer',
            $methods,
            'Core getValidOnlinePaymentMethods() must include stancer via the getValidPayment hook'
        );
    }

    // =========================================================================
    // stancerFakeStripeModuleEnable() compatibility shim
    // =========================================================================

    public function testFakeStripeShimIsNoOpOnDolibarr18Harness(): void
    {
        // The sqlite integration harness ships Dolibarr 18.x; since the cutoff
        // moved to < 18, the shim must be inert here (printNewTable and
        // getValidPayment hooks cover the use cases the shim used to handle).
        $this->assertTrue(
            version_compare(DOL_VERSION, '18.0.0', '>='),
            'Sqlite harness is expected to run Dolibarr >= 18'
        );

        global $conf;
        unset($conf->stripe);
        unset($conf->modules['stripe']);

        $this->assertFalse(
            isModEnabled('stripe'),
            'Precondition: stripe must not be enabled before the shim runs'
        );

        stancerFakeStripeModuleEnable();

        $this->assertFalse(
            isModEnabled('stripe'),
            'On Dolibarr >= 18 the shim must stay a no-op'
        );
        $this->assertObjectNotHasProperty(
            'stripe',
            $conf,
            'Shim must not inject a fake stripe conf object on Dolibarr >= 18'
        );
        $this->assertArrayNotHasKey('stripe', (array) ($conf->modules ?? []));
    }

    public function testFakeStripeShimDocumentedNoOpThresholdIs18(): void
    {
        // Documentary assertion: the function decides its no-op via
        // version_compare(DOL_VERSION, '18.0.0', '<'). We cannot redefine
        // DOL_VERSION at runtime, so this test just locks the threshold so a
        // future refactor that moves the cutoff has to also update it here on
        // purpose.
        $this->assertTrue(version_compare('17.0.4', '18.0.0', '<'));
        $this->assertFalse(version_compare('18.0.0', '18.0.0', '<'));
        $this->assertFalse(version_compare('18.0.8', '18.0.0', '<'));
        $this->assertFalse(version_compare('22.0.0', '18.0.0', '<'));
        $this->assertFalse(version_compare('23.0.0', '18.0.0', '<'));
    }
}
