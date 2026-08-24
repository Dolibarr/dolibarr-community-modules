<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard test for audit finding M4: stancer_thirdparty.php only blocked on
 * !$permissiontoread, so its write actions (add, addsepa, deletesepa /
 * stancerDeleteSEPA, refreshStancerAccount, stancertakepayment,
 * deleteStancerAccount) ran without checking $permissiontoadd. A user with only
 * stancer/read could create/delete a SEPA mandate, push a RIB to Stancer, or
 * take a payment (read -> write elevation).
 *
 * Every write action must be denied (accessforbidden) when the user lacks the
 * write permission. Structural guard: the HTTP harness forces admin + all
 * rights, so a behavioural permission test is not expressible there.
 */
class StancerThirdpartyGuardTest extends TestCase
{
    public function testThirdpartyWriteActionsAreGatedByWritePermission(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/stancer_thirdparty.php');
        $this->assertNotFalse($src, 'Cannot read stancer_thirdparty.php');

        // The sensitive write actions must be listed in the guard.
        foreach (['add', 'addsepa', 'deletesepa', 'refreshStancerAccount', 'deleteStancerAccount', 'stancertakepayment'] as $act) {
            $this->assertMatchesRegularExpression(
                '/\$stancerWriteActions\s*=\s*array\([^)]*\x27' . $act . '\x27/s',
                $src,
                "M4: write action '$act' must be covered by the write-permission guard"
            );
        }

        // The guard must deny (accessforbidden) when the write permission is missing.
        $this->assertMatchesRegularExpression(
            '/in_array\(\$action,\s*\$stancerWriteActions[^)]*\)\s*&&\s*!\$permissiontoadd/s',
            $src,
            'M4: write actions must be gated by !$permissiontoadd'
        );
        $guardPos = strpos($src, '$stancerWriteActions');
        $this->assertNotFalse($guardPos);
        $this->assertStringContainsString(
            'accessforbidden',
            substr($src, $guardPos, 400),
            'M4: the write-action guard must accessforbidden() on refusal'
        );

        // And it must run before the actions execute (guard is above the first action handler).
        $actionPos = strpos($src, 'if ($action == "addsepa")');
        $this->assertNotFalse($actionPos);
        $this->assertLessThan($actionPos, $guardPos, 'M4: the guard must run before the write actions');
    }
}
