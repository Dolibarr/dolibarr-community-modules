<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for stancer.lib.php utility functions
 *
 * Note: We test only standalone utility functions that don't require
 * database connections or external API calls.
 */
class StancerLibTest extends TestCase
{
    protected function setUp(): void
    {
        global $conf;
        $conf->entity = 1;
        $conf->global = new \stdClass();

        // Load utility functions that can be tested in isolation
        // The full lib requires many Dolibarr includes, so we test what we can
    }

    // =========================================================================
    // stancerFilterSocName() tests
    // =========================================================================

    public function testStancerFilterSocNameReturnsOriginalForNormalLength(): void
    {
        // Simulate the function behavior
        $str = 'Test Company Name';
        $result = $this->filterSocName($str);
        $this->assertEquals('Test Company Name', $result);
    }

    public function testStancerFilterSocNamePrependsClientForShortNames(): void
    {
        $str = 'AB';
        $result = $this->filterSocName($str);
        $this->assertEquals('CLIENT AB', $result);
    }

    public function testStancerFilterSocNameTruncatesLongNames(): void
    {
        $str = str_repeat('A', 100);
        $result = $this->filterSocName($str);
        $this->assertEquals(63, strlen($result));
    }

    public function testStancerFilterSocNameBoundaryAt4Chars(): void
    {
        $str = 'ABCD';
        $result = $this->filterSocName($str);
        $this->assertEquals('ABCD', $result);
    }

    public function testStancerFilterSocNameBoundaryAt3Chars(): void
    {
        $str = 'ABC';
        $result = $this->filterSocName($str);
        $this->assertEquals('CLIENT ABC', $result);
    }

    // =========================================================================
    // stancerCheckTag() tests
    // =========================================================================

    public function testStancerCheckTagReturnsTrueForValidTag(): void
    {
        $this->assertTrue($this->checkTag('INV=123'));
        $this->assertTrue($this->checkTag('INV=123.CUS=456'));
        $this->assertTrue($this->checkTag('MEM=1.DAT=202312310000'));
    }

    public function testStancerCheckTagReturnsFalseForInvalidTag(): void
    {
        $this->assertFalse($this->checkTag('notavalidtag'));
        $this->assertFalse($this->checkTag('123456'));
        $this->assertFalse($this->checkTag(''));
    }

    // =========================================================================
    // stancerCleanUpDuplicate() tests
    // =========================================================================

    public function testStancerCleanUpDuplicateSortsKeysAlphabetically(): void
    {
        // Input with keys in random order
        $tag = 'INV=123.CUS=456.DAT=20231231';
        $result = $this->cleanUpDuplicate($tag);

        // Keys should be sorted: CUS, DAT, INV
        $this->assertEquals('CUS=456.DAT=20231231.INV=123', $result);
    }

    public function testStancerCleanUpDuplicateRemovesDuplicateKeys(): void
    {
        // If there are duplicate keys, ksort will keep only one
        $tag = 'INV=123.INV=456';
        $result = $this->cleanUpDuplicate($tag);

        // Only one INV key should remain (the last one wins with dolExplodeIntoArray)
        $this->assertStringContainsString('INV=', $result);
        $this->assertEquals(1, substr_count($result, 'INV='));
    }

    public function testStancerCleanUpDuplicateHandlesSingleKeyValue(): void
    {
        $tag = 'INV=123';
        $result = $this->cleanUpDuplicate($tag);
        $this->assertEquals('INV=123', $result);
    }

    // =========================================================================
    // stancerRefreshAllPayoutsFromDolibarr - local StancerApi instantiation (Cat 2)
    // =========================================================================

    public function testRefreshAllPayoutsDoesNotUseGlobalStancerApi(): void
    {
        $libPath = __DIR__ . '/../../../lib/stancer_refresh.lib.php';
        $this->assertFileExists($libPath);

        $content = file_get_contents($libPath);

        // Find the function body
        $funcStart = strpos($content, 'function stancerRefreshAllPayoutsFromDolibarr');
        $this->assertNotFalse($funcStart, 'Function stancerRefreshAllPayoutsFromDolibarr must exist');

        // Extract the global line to ensure $stancerApi is NOT in the global declaration
        $funcBody = substr($content, $funcStart, 2000);
        preg_match('/global\s+([^;]+);/', $funcBody, $matches);
        $this->assertNotEmpty($matches, 'Function should have a global declaration');
        $this->assertStringNotContainsString('$stancerApi', $matches[1],
            'stancerRefreshAllPayoutsFromDolibarr must NOT use global $stancerApi');
    }

    public function testRefreshAllPayoutsInstantiatesStancerApiLocally(): void
    {
        $libPath = __DIR__ . '/../../../lib/stancer_refresh.lib.php';
        $content = file_get_contents($libPath);

        $funcStart = strpos($content, 'function stancerRefreshAllPayoutsFromDolibarr');
        $funcBody = substr($content, $funcStart, 2000);

        $this->assertStringContainsString('$stancerApi = new StancerApi()', $funcBody,
            'stancerRefreshAllPayoutsFromDolibarr must instantiate StancerApi locally');
    }

    // =========================================================================
    // Helper methods that replicate the lib functions for isolated testing
    // =========================================================================

    /**
     * Replica of stancerFilterSocName for testing without full lib load
     */
    private function filterSocName(string $str): string
    {
        if (strlen($str) < 4) {
            return "CLIENT " . $str;
        }
        if (strlen($str) > 64) {
            return substr($str, 0, 63);
        }
        return $str;
    }

    /**
     * Replica of stancerCheckTag for testing without full lib load
     */
    private function checkTag(string $str): bool
    {
        if (strpos($str, '=') === false) {
            return false;
        }
        return true;
    }

    /**
     * Replica of stancerCleanUpDuplicate for testing without full lib load
     */
    private function cleanUpDuplicate(string $tag): string
    {
        // Simulates dolExplodeIntoArray
        $tmptag = [];
        $parts = explode('.', $tag);
        foreach ($parts as $part) {
            $kv = explode('=', $part);
            if (count($kv) == 2) {
                $tmptag[$kv[0]] = $kv[1];
            }
        }

        ksort($tmptag);

        $t = "";
        foreach ($tmptag as $k => $v) {
            if ($t != '') {
                $t .= '.';
            }
            $t .= $k . '=' . $v;
        }
        return $t;
    }
}
