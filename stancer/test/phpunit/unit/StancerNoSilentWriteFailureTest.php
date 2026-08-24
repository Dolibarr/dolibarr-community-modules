<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard test for audit finding M6: write calls (update/setPaid/cloture/validate)
 * in the refresh short-circuits and in stancerDeleteSEPA used to ignore their
 * return value, so a DB failure desynchronised the local status / invoice silently.
 * Every such write must now check its return and log the failure (no silent
 * failure). This test pins that no BARE standalone write statement remains and
 * that the failure logs were added.
 */
class StancerNoSilentWriteFailureTest extends TestCase
{
    public function testRefreshHasNoBareUncheckedWriteStatements(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/lib/stancer_refresh.lib.php');
        $this->assertNotFalse($src);

        // A bare standalone write statement (whole line "$x->update($user);",
        // "$x->setPaid($user);", "$x->cloture($user, 1);") ignores the return value.
        // After the M6 fix every write is either captured ($res2 = ...) or wrapped
        // in an "if (... < 0)" guard, so none of these bare forms may remain.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*\$\w+->(update|setPaid|cloture|validate)\(\$user(,\s*\d+)?\);\s*$/m',
            $src,
            'M6: refresh must not contain a bare unchecked write statement (update/setPaid/cloture/validate)'
        );

        // The failure logs added by the fix must be present.
        $this->assertStringContainsString('local status update failed', $src, 'M6: missing update-failure log');
        $this->assertStringContainsString('setPaid failed on invoice', $src, 'M6: missing setPaid-failure log');
    }

    public function testDeleteSepaLogsUpdateFailure(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/lib/stancer_customer.lib.php');
        $this->assertNotFalse($src);

        // stancerDeleteSEPA must capture the local update result and log on failure.
        $this->assertMatchesRegularExpression(
            '/\$resUpdate\s*=\s*\$companypaymentmode->update\(\$user\);/',
            $src,
            'M6: stancerDeleteSEPA must capture the update() return'
        );
        $this->assertMatchesRegularExpression(
            '/stancerDeleteSEPA failed to (update|clean up) local payment mode/',
            $src,
            'M6: stancerDeleteSEPA must log a failed local update'
        );
    }
}
