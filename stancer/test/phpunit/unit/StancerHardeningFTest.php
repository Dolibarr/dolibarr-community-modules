<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard tests for the F hardening lot (F1 skipped by decision):
 *  F2 - never log the raw API error body (IBAN/email/PII leak).
 *  F5 - the raw-API ajax endpoint is admin-only.
 *  F6 - the trigger dispatches via an explicit allowlist (not dynamic
 *       is_callable) and guards $object->id.
 *  F7 - stancerRefreshOnePayout returns the $output ->error contract; mail
 *       callers treat null/0 as a skip, not a failure.
 *  F8 - the informational IBAN check no longer blocks the worker with sleep().
 */
class StancerHardeningFTest extends TestCase
{
    private function src(string $rel): string
    {
        $s = file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
        $this->assertNotFalse($s, "Cannot read $rel");
        return $s;
    }

    public function testF2ApiDoesNotLogRawBody(): void
    {
        $src = $this->src('class/stancer_api.class.php');
        $this->assertStringNotContainsString(
            'raw body: " . $content',
            $src,
            'F2: the raw response body must not be logged'
        );
        $this->assertStringContainsString('never log the raw response body', $src);
    }

    public function testF5RawEndpointIsAdminOnly(): void
    {
        $src = $this->src('ajax/fetch_stancer_raw.php');
        $this->assertMatchesRegularExpression(
            '/empty\(\$user->id\)\s*\|\|\s*empty\(\$user->admin\)/',
            $src,
            'F5: the raw-API endpoint must require $user->admin'
        );
    }

    public function testF6TriggerUsesAllowlistAndGuardsObjectId(): void
    {
        $src = $this->src('core/triggers/interface_99_modStancer_StancerTriggers.class.php');
        $this->assertStringNotContainsString(
            'if (is_callable($callback))',
            $src,
            'F6: the dynamic is_callable dispatch must be removed'
        );
        $this->assertStringContainsString('$actionHandlers', $src, 'F6: must use an explicit action allowlist');
        $this->assertMatchesRegularExpression(
            '/\$objId = is_object\(\$object\) \? \(\$object->id \?\? \x27\?\x27\) : \x27\?\x27;/',
            $src,
            'F6: $object->id must be guarded before logging'
        );
    }

    public function testF7PayoutReturnsObjectContract(): void
    {
        $src = $this->src('lib/stancer_refresh.lib.php');
        $this->assertMatchesRegularExpression(
            '/fillDataFromApi failed for \$payoutID[^;]*;\s*dol_syslog[^;]*;\s*return \$output;/s',
            $src,
            'F7: stancerRefreshOnePayout must return the $output ->error contract, not -1'
        );
        $this->assertStringContainsString(
            'null/0 is a skip',
            $src,
            'F7: mail callers must treat null/0 as a skip, not a failure'
        );
    }

    public function testF3ReopenSequenceIsTransactional(): void
    {
        $src = $this->src('lib/stancer_dispute.lib.php');
        $this->assertStringContainsString(
            'wrap the whole reopen sequence',
            $src,
            'F3: the invoice reopen sequence must be wrapped in a transaction'
        );
        // begin, rollback (on partial failure) and commit must all be present.
        $this->assertStringContainsString('$db->begin();', $src, 'F3: must open a transaction');
        $this->assertStringContainsString('$db->rollback();', $src, 'F3: must rollback on partial failure');
        $this->assertStringContainsString('$db->commit();', $src, 'F3: must commit on success');
    }

    public function testF4RefundBankLineGuardedAndDedupedById(): void
    {
        $src = $this->src('lib/stancer_dispute.lib.php');
        $this->assertStringContainsString(
            'stancerIsBankLineDateLocked($dateo)',
            $src,
            'F4: the refund bank line must go through the fiscal-date lock'
        );
        $this->assertStringContainsString(
            'dedup on the Stancer refund id ALONE',
            $src,
            'F4: the refund bank line must dedup on the refund id alone (not id+amount)'
        );
    }

    public function testF8NoBlockingSleepInIbanCheck(): void
    {
        $src = $this->src('lib/stancer_customer.lib.php');
        // Match a real call (start of statement), not the explanatory comment.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*sleep\s*\(/m',
            $src,
            'F8: the blocking sleep() polling in the IBAN check must be removed'
        );
    }
}
