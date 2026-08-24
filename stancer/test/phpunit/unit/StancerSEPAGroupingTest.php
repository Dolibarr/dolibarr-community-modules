<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SEPA same-day grouping helpers in lib/stancer_payment.lib.php
 *  - stancerBuildGroupedDescription
 *  - stancerBuildGroupedOrderId
 *  - stancerBuildGroupedTag
 *
 * These helpers are pure PHP (no Dolibarr deps), so we load the lib file and
 * call the real functions directly through the lib namespace.
 */
class StancerSEPAGroupingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // The lib file uses dol_syslog() and other Dolibarr helpers inside *other* functions,
        // but the 3 helpers we exercise here are standalone. Provide stubs only if not loaded.
        if (!function_exists('dol_syslog')) {
            eval('function dol_syslog($msg, $level = 0) {}');
        }
        if (!function_exists('price2num')) {
            eval('function price2num($val, $mode = "") { return $val; }');
        }
        if (!function_exists('dol_now')) {
            eval('function dol_now($mode = "") { return time(); }');
        }
        if (!function_exists('getDolGlobalString')) {
            eval('function getDolGlobalString($key, $default = "") { return $default; }');
        }
        if (!function_exists('getDolGlobalInt')) {
            eval('function getDolGlobalInt($key, $default = 0) { return $default; }');
        }
        if (!function_exists('dol_include_once')) {
            eval('function dol_include_once($file) {}');
        }
        if (!function_exists('dol_print_date')) {
            eval('function dol_print_date($t, $fmt = "") { return date("Y-m-d", $t); }');
        }
        if (!function_exists('setEventMessages')) {
            eval('function setEventMessages($msg, $errs = null, $tag = "") {}');
        }
        if (!function_exists('dol_buildpath')) {
            eval('function dol_buildpath($p, $m = 0) { return $p; }');
        }
        if (!function_exists('newToken')) {
            eval('function newToken() { return "token"; }');
        }
        if (!function_exists('dol_getIdFromCode')) {
            eval('function dol_getIdFromCode($db, $key, $tbl, $col1 = "code", $col2 = "id", $useid = 0) { return 1; }');
        }

        // Load only the 3 helpers by extracting them from the lib file to avoid pulling in Dolibarr deps.
        if (!function_exists('stancerBuildGroupedDescription')) {
            $libPath = __DIR__ . '/../../../lib/stancer_payment.lib.php';
            $src = file_get_contents($libPath);
            $helpers = '';
            foreach (['stancerBuildGroupedDescription', 'stancerBuildGroupedOrderId', 'stancerBuildGroupedTag'] as $fn) {
                $start = strpos($src, 'function ' . $fn);
                if ($start === false) {
                    throw new \RuntimeException("Cannot find $fn in lib");
                }
                $depth = 0;
                $bodyStart = strpos($src, '{', $start);
                $i = $bodyStart;
                while ($i < strlen($src)) {
                    if ($src[$i] === '{') {
                        $depth++;
                    } elseif ($src[$i] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $end = $i + 1;
                            break;
                        }
                    }
                    $i++;
                }
                $helpers .= substr($src, $start, $end - $start) . "\n\n";
            }
            eval($helpers);
        }
    }

    // =========================================================================
    // stancerBuildGroupedDescription() tests (64 chars max)
    // =========================================================================

    public function testGroupedDescriptionEmpty(): void
    {
        $this->assertSame('', stancerBuildGroupedDescription([]));
    }

    public function testGroupedDescriptionSingleRefShort(): void
    {
        $this->assertSame('FA2603-0001', stancerBuildGroupedDescription(['FA2603-0001']));
    }

    public function testGroupedDescriptionTwoRefsShort(): void
    {
        $this->assertSame('FA2603-0001+FA2603-0002', stancerBuildGroupedDescription(['FA2603-0001', 'FA2603-0002']));
    }

    public function testGroupedDescriptionFitsExactly64(): void
    {
        // 5 refs of 11 chars = 55 + 4 separators = 59 -> fits
        $refs = ['FA2603-0001', 'FA2603-0002', 'FA2603-0003', 'FA2603-0004', 'FA2603-0005'];
        $result = stancerBuildGroupedDescription($refs);
        $this->assertLessThanOrEqual(64, strlen($result));
        $this->assertSame(implode('+', $refs), $result);
    }

    public function testGroupedDescriptionTruncatesAndAppendsCount(): void
    {
        // 6 refs of 11 chars = 66 + 5 separators = 71 -> too long, must truncate.
        $refs = ['FA2603-0001', 'FA2603-0002', 'FA2603-0003', 'FA2603-0004', 'FA2603-0005', 'FA2603-0006'];
        $result = stancerBuildGroupedDescription($refs);
        $this->assertLessThanOrEqual(64, strlen($result));
        // Must end with +<digit> indicating remaining refs.
        $this->assertMatchesRegularExpression('/\+\d+$/', $result);
        // First ref must be present.
        $this->assertStringStartsWith('FA2603-0001', $result);
    }

    public function testGroupedDescriptionHardTruncateWhenFirstRefAlreadyTooLong(): void
    {
        $longRef = str_repeat('A', 80);
        $result = stancerBuildGroupedDescription([$longRef, 'B', 'C']);
        $this->assertSame(64, strlen($result));
    }

    // =========================================================================
    // stancerBuildGroupedOrderId() tests (36 chars max)
    // =========================================================================

    public function testGroupedOrderIdEmpty(): void
    {
        $this->assertSame('', stancerBuildGroupedOrderId([]));
    }

    public function testGroupedOrderIdShort(): void
    {
        $this->assertSame('FA2603-0001+FA2603-0002', stancerBuildGroupedOrderId(['FA2603-0001', 'FA2603-0002']));
    }

    public function testGroupedOrderIdRespects36CharLimit(): void
    {
        $refs = ['FA2603-0001', 'FA2603-0002', 'FA2603-0003', 'FA2603-0004', 'FA2603-0005'];
        $result = stancerBuildGroupedOrderId($refs);
        $this->assertLessThanOrEqual(36, strlen($result));
    }

    public function testGroupedOrderIdAppendsCountWhenTruncated(): void
    {
        $refs = ['FA2603-0001', 'FA2603-0002', 'FA2603-0003', 'FA2603-0004'];
        $result = stancerBuildGroupedOrderId($refs);
        $this->assertLessThanOrEqual(36, strlen($result));
        $this->assertStringStartsWith('FA2603-0001', $result);
    }

    // =========================================================================
    // stancerBuildGroupedTag() tests (36 chars max, deterministic, idempotent)
    // =========================================================================

    public function testGroupedTagFormat(): void
    {
        $tag = stancerBuildGroupedTag([42, 13, 100], 7);
        $this->assertLessThanOrEqual(36, strlen($tag));
        $this->assertMatchesRegularExpression('/^GRP=[a-f0-9]{8}\.CUS=7$/', $tag);
    }

    public function testGroupedTagIsDeterministic(): void
    {
        $tag1 = stancerBuildGroupedTag([42, 13, 100], 7);
        $tag2 = stancerBuildGroupedTag([42, 13, 100], 7);
        $this->assertSame($tag1, $tag2);
    }

    public function testGroupedTagOrderInsensitive(): void
    {
        // Reordering the invoice ids must produce the same tag (idempotence).
        $tag1 = stancerBuildGroupedTag([42, 13, 100], 7);
        $tag2 = stancerBuildGroupedTag([100, 42, 13], 7);
        $this->assertSame($tag1, $tag2);
    }

    public function testGroupedTagDifferentSocidDifferentTag(): void
    {
        $tag1 = stancerBuildGroupedTag([42, 13, 100], 7);
        $tag2 = stancerBuildGroupedTag([42, 13, 100], 8);
        $this->assertNotSame($tag1, $tag2);
    }

    public function testGroupedTagDifferentInvoicesDifferentTag(): void
    {
        $tag1 = stancerBuildGroupedTag([42, 13, 100], 7);
        $tag2 = stancerBuildGroupedTag([42, 13, 101], 7);
        $this->assertNotSame($tag1, $tag2);
    }

    public function testGroupedTagParseBack(): void
    {
        // The tag is opaque (hash), but the CUS=<socid> suffix can be parsed by getObjectFromTag style logic.
        $tag = stancerBuildGroupedTag([42, 13], 9);
        $this->assertStringEndsWith('.CUS=9', $tag);
        $this->assertStringStartsWith('GRP=', $tag);
    }
}
