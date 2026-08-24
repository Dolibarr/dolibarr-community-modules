<?php

namespace Stancer\Tests\IntegrationDolibarr;

/**
 * Integration test for audit finding M6 (no silent failures), focused on the
 * two concrete fixes:
 *  - stancerRemoveOldDraftPayments() ignored fetchAll/update returns and
 *    returned nothing, although the payments-list pipeline chains it as
 *    `if (empty($res->error))`. It must now return an object carrying ->error
 *    and log any update failure.
 *  - stancerDeleteSEPA() used the results of $user->fetch()/companypaymentmode
 *    ->fetch() without checking them (now guarded + logged).
 */
class StancerRemoveDraftReturnIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../../class/stancer_payments.class.php';
        dol_include_once('/stancer/lib/stancer.lib.php');
        dol_include_once('/stancer/lib/stancer_refresh.lib.php');
    }

    public function testRemoveOldDraftPaymentsReturnsChainableObject(): void
    {
        $res = stancerRemoveOldDraftPayments();

        // M6: must return an object with an ->error property so the pipeline can
        // safely chain on `empty($res->error)` (it used to return null).
        $this->assertIsObject($res, 'M6: stancerRemoveOldDraftPayments must return an object');
        $this->assertTrue(property_exists($res, 'error'), 'M6: the returned object must expose ->error');
        $this->assertSame('', $res->error, 'No error expected on an empty/clean draft set');
    }

    public function testDeleteSepaGuardsFetchReturns(): void
    {
        // Structural: the two fetch() results must be checked before their
        // properties are used (guarded + logged), not consumed blindly.
        $src = file_get_contents(dirname(__DIR__, 3) . '/lib/stancer_customer.lib.php');
        $this->assertMatchesRegularExpression(
            '/\$res = \$user->fetch\([^;]*\);\s*if \(\$res <= 0\)/s',
            $src,
            'M6: stancerDeleteSEPA must check $user->fetch() before using the user'
        );
        $this->assertMatchesRegularExpression(
            '/\$resStancer = \$companypaymentmode->fetch\([^;]*\);\s*if \(\$resStancer <= 0\)/s',
            $src,
            'M6: stancerDeleteSEPA must check companypaymentmode->fetch() before using it'
        );
    }
}
