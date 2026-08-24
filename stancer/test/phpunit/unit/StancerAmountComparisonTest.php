<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard test for audit finding M7: amount comparisons used strict float equality
 * (e.g. $paid == $obj->total_ttc). Sub-cent drift (partial payments, multi-line
 * rounding) then wrongly failed the "fully paid" short-circuit. Comparisons must
 * use price2num(..., 'MT') with a 0.01 tolerance (the Guard 3 pattern).
 */
class StancerAmountComparisonTest extends TestCase
{
    public function testRefreshHasNoStrictFloatEqualityOnInvoiceTotals(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/lib/stancer_refresh.lib.php');
        $this->assertNotFalse($src);

        // No strict float equality against an invoice total may remain.
        $this->assertDoesNotMatchRegularExpression(
            '/\$\w+ == \$\w+->total_ttc/',
            $src,
            'M7: refresh must not compare amounts with strict float equality on invoice totals'
        );

        // The tolerant pattern must be used.
        $this->assertMatchesRegularExpression(
            '/price2num\(\$paid, \x27MT\x27\) >= price2num\([^)]*->total_ttc, \x27MT\x27\) - 0\.01/',
            $src,
            'M7: refresh must compare with price2num(...) >= price2num(...) - 0.01'
        );
    }

    public function testImportReversementsHasNoStrictFloatInequality(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/stancer_import_check_reversements.php');
        $this->assertNotFalse($src);

        // The verification CONDITIONS (not the HTML messages that display the
        // mismatch as text) must use a tolerance, not '!=' on floats.
        $this->assertDoesNotMatchRegularExpression(
            '/&&\s*\(?\$sqlamount != /',
            $src,
            'M7: import reversements conditions must not use strict float inequality on amounts'
        );
    }

    /**
     * Sanity: the tolerance semantics actually treat a sub-cent-short amount as
     * fully paid (total=19.99, paid=19.9899999).
     */
    public function testToleranceTreatsSubCentDriftAsPaid(): void
    {
        $total = 19.99;
        $paid = 19.9899999;
        $this->assertTrue(
            round($paid, 2) >= round($total, 2) - 0.01,
            'A sub-cent-short payment must be considered fully paid within tolerance'
        );
    }
}
