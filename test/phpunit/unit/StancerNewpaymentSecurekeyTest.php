<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard tests for audit finding M1: public/newpayment.php and
 * public/newpayment_propal.php read GETPOST("securekey") but never verified it.
 * Objects were loaded by (predictable) ref, allowing enumeration of other
 * customers' invoices/orders and arbitrary Stancer customer/payment creation.
 *
 * The securekey must be verified with the SAME scheme used to generate the link:
 *  - newpayment.php (invoice/order): dol_hash(PAYMENT_SECURITY_TOKEN + source + ref)
 *    (core getOnlinePaymentUrl), verified BEFORE loading any object by ref.
 *  - newpayment_propal.php (propal): dol_hash(PAYMENT_SECURITY_TOKEN +
 *    'propalpayment' + id + ref) (stancerGetPropalPaymentUrl), verified once the
 *    propal is loaded and before any sensitive action.
 */
class StancerNewpaymentSecurekeyTest extends TestCase
{
    public function testNewpaymentVerifiesSecurekeyBeforeLoadingObject(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/public/newpayment.php');
        $this->assertNotFalse($src, 'Cannot read public/newpayment.php');

        $verifyPos = strpos($src, 'dol_verifyHash');
        $this->assertNotFalse($verifyPos, 'M1: newpayment.php must verify the securekey (dol_verifyHash)');

        $fetchPos = strpos($src, '->fetch(0, $ref)');
        $this->assertNotFalse($fetchPos, 'fetch by ref not found');

        $this->assertLessThan(
            $fetchPos,
            $verifyPos,
            'M1: the securekey must be verified BEFORE loading any object by ref'
        );

        // The verification must deny on mismatch.
        $guard = substr($src, $verifyPos, $fetchPos - $verifyPos);
        $this->assertStringContainsString(
            'accessforbidden',
            $guard,
            'M1: newpayment.php must accessforbidden() on an invalid securekey'
        );
    }

    public function testNewpaymentPropalVerifiesSecurekey(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/public/newpayment_propal.php');
        $this->assertNotFalse($src, 'Cannot read public/newpayment_propal.php');

        // Verification must use the propal-specific hash scheme (aligned with
        // stancerGetPropalPaymentUrl) and deny on mismatch.
        $this->assertMatchesRegularExpression(
            '/dol_verifyHash\([^;]*\x27propalpayment\x27[^;]*\$SECUREKEY/s',
            $src,
            'M1: newpayment_propal.php must verify the securekey with the propalpayment hash scheme'
        );
        $this->assertStringContainsString(
            'accessforbidden',
            $src,
            'M1: newpayment_propal.php must accessforbidden() on an invalid securekey'
        );
    }
}
