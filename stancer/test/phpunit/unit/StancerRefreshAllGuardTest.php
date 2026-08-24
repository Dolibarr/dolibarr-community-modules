<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard test for audit finding E2: the "refreshall" action of
 * stancer_payments_list.php triggers mass writes (payment creation, setPaid,
 * email notifications) and hundreds of API calls, yet the block was only
 * reachable through $permissiontoread (the page entry check). A read-only user
 * or a forged GET could launch the whole pipeline.
 *
 * The write permission ($permissiontoadd) and the anti-CSRF token must be
 * enforced BEFORE the pipeline runs. This is a structural guard: the HTTP
 * harness forces admin + all rights and NOCSRFCHECK, so a behavioural
 * permission/CSRF test is not expressible there.
 */
class StancerRefreshAllGuardTest extends TestCase
{
    public function testRefreshAllIsGuardedByWritePermissionAndToken(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/stancer_payments_list.php');
        $this->assertNotFalse($src, 'Cannot read stancer_payments_list.php');

        $start = strpos($src, 'if ($action == "refreshall"');
        $this->assertNotFalse($start, 'refreshall action block not found');

        // Everything the code runs between entering the action and the first
        // heavy pipeline call must contain the guards.
        $callPos = strpos($src, 'stancerRefreshAllPayments(', $start);
        $this->assertNotFalse($callPos, 'refreshall pipeline call not found');
        $guard = substr($src, $start, $callPos - $start);

        $this->assertStringContainsString(
            '$permissiontoadd',
            $guard,
            'E2: refreshall must be guarded by the write permission before running the pipeline'
        );
        $this->assertStringContainsString(
            'accessforbidden',
            $guard,
            'E2: refreshall must deny (accessforbidden) when the user lacks permission/token'
        );
        $this->assertMatchesRegularExpression(
            '/token/i',
            $guard,
            'E2: refreshall must verify the anti-CSRF token before running the pipeline'
        );
    }
}
