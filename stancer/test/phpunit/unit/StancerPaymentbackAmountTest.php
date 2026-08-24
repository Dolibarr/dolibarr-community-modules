<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard test for audit finding M2: in the invoice branch of paymentback.php the
 * recorded amount came from $_SESSION["FinalPaymentAmt"] (seeded from a GETPOST
 * in newpayment) instead of $paymentData['amount'] (the Stancer API source of
 * truth). The recorded amount must be derived from the API and any divergence
 * with the session logged.
 */
class StancerPaymentbackAmountTest extends TestCase
{
    public function testInvoiceBranchRecordsApiAmountNotSession(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/public/paymentback.php');
        $this->assertNotFalse($src, 'Cannot read public/paymentback.php');

        // The recorded amount is derived from the API amount (cents / 100).
        $this->assertMatchesRegularExpression(
            '/FinalPaymentAmt\s*=\s*isset\(\$paymentData\[\x27amount\x27\]\)\s*\?\s*\(\(float\)\s*\$paymentData\[\x27amount\x27\]\s*\/\s*100\)/',
            $src,
            'M2: the invoice branch must record the API amount ($paymentData[amount]/100), not just the session'
        );

        // A session/API divergence must be logged as an error.
        $this->assertMatchesRegularExpression(
            '/amount mismatch[^;]*LOG_ERR/s',
            $src,
            'M2: a session/API amount mismatch must be logged (LOG_ERR)'
        );
    }
}
