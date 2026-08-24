<?php

namespace Stancer\Tests\IntegrationDolibarr;

/**
 * Proof-of-concept for audit finding C1: second-order SQL injection in
 * stancer_import_check_reversements.php.
 *
 * `num_releve` is read with GETPOST('num_releve') (no type filter, page
 * line 229) and concatenated verbatim into several llx_bank statements,
 * e.g. the SET clause of the UPDATE at line 161:
 *
 *     UPDATE llx_bank SET num_releve='<payload>' WHERE rowid='<id>'
 *
 * A forged num_releve can therefore rewrite llx_bank rows the operation
 * was never meant to touch.
 *
 * These tests drive the REAL stancer_find_update() from the page (extracted
 * with the PHP tokenizer, not copied) against a live Dolibarr SQLite
 * instance. C1 is now fixed (db->escape on num_releve at every usage), so
 * they act as regression guards: a forged num_releve must be stored as a
 * plain literal on the targeted row only, and must never touch an unrelated
 * row.
 */
class StancerImportSqlInjectionIntegrationTest extends DolibarrRealTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Sets STANCER_BANK_ACCOUNT_FOR_PAYMENTS = '1' (matches fk_account below).
        $this->configureStancerSettings();
        $this->loadStancerFindUpdate();

        // Deterministic llx_bank state: this test asserts on rows it owns, and
        // the injection payload uses WHERE 1=1, so start from a clean table.
        $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "bank");
    }

    public function testC1NumReleveSqlInjectionIsNeutralised(): void
    {
        // Row the operation legitimately addresses.
        $targetChq = 'paym_target_' . uniqid();
        $targetRowid = $this->insertBankLine($targetChq, 'OLD_TARGET', 'VIR', 10.0);

        // Unrelated row: DIFFERENT num_chq, same bank account. The import must
        // never touch it; 'SENTINEL_B' is the value we check afterwards.
        $bystanderChq = 'paym_bystander_' . uniqid();
        $bystanderRowid = $this->insertBankLine($bystanderChq, 'SENTINEL_B', 'VIR', 20.0);
        $bystanderBefore = $this->fetchBankRow($bystanderRowid)->num_releve;

        // Attacker-controlled num_releve (as delivered by an unfiltered GETPOST).
        // It closes the string literal, forces WHERE 1=1, and comments out the
        // original "WHERE rowid=..." tail.
        $maliciousNumReleve = "PWNED' WHERE 1=1 -- ";

        // numrowstarget = 1 -> the SELECT matches only the target row (unique
        // num_chq), and the SET-clause UPDATE at line 161 is where it lands.
        \stancer_find_update($targetChq, '2026-01-01', 'REF', 10.0, 0.0, $maliciousNumReleve, 1);

        $target = $this->fetchBankRow($targetRowid);
        $bystander = $this->fetchBankRow($bystanderRowid);

        // Visible regression evidence: the payload is stored verbatim on the
        // target row only; the unrelated row keeps its value.
        fwrite(STDERR, "\n[C1 FIXED] num_releve payload         : " . $maliciousNumReleve . "\n");
        fwrite(STDERR, "[C1 FIXED] target row #" . $targetRowid . " num_releve   : '"
            . $target->num_releve . "' (payload stored as data, not executed)\n");
        fwrite(STDERR, "[C1 FIXED] bystander row #" . $bystanderRowid . " num_releve: '"
            . $bystanderBefore . "' -> '" . $bystander->num_releve . "' (unchanged)\n\n");

        // CORE GUARD: the unrelated row is untouched -> injection neutralised.
        $this->assertSame(
            'SENTINEL_B',
            $bystander->num_releve,
            'C1 regression: a num_releve payload must NOT touch an unrelated llx_bank row'
        );

        // The targeted row is still updated, but the payload is persisted as a
        // plain string (quote kept as text), proving it is treated as data.
        $this->assertNotSame('OLD_TARGET', $target->num_releve, 'The targeted row should still be updated');
        $this->assertStringContainsString(
            "PWNED' WHERE 1=1",
            (string) $target->num_releve,
            'The payload must be persisted verbatim as a literal value on the target row'
        );
    }

    /**
     * Counter-proof (test discriminates, not a false positive): the SAME payload
     * fed already-escaped - exactly what the C1 fix does with db->escape() at the
     * source - leaves the unrelated row untouched. The quote is doubled, so the
     * payload becomes a plain string literal and the UPDATE hits only the target
     * rowid.
     */
    public function testEscapedNumReleveLeavesBystanderIntact(): void
    {
        $targetChq = 'paym_target_' . uniqid();
        $targetRowid = $this->insertBankLine($targetChq, 'OLD_TARGET', 'VIR', 10.0);

        $bystanderChq = 'paym_bystander_' . uniqid();
        $bystanderRowid = $this->insertBankLine($bystanderChq, 'SENTINEL_B', 'VIR', 20.0);

        // Pre-escaped num_releve == what the fix produces before concatenation.
        $escapedNumReleve = $this->db->escape("PWNED' WHERE 1=1 -- ");

        \stancer_find_update($targetChq, '2026-01-01', 'REF', 10.0, 0.0, $escapedNumReleve, 1);

        $target = $this->fetchBankRow($targetRowid);
        $bystander = $this->fetchBankRow($bystanderRowid);

        // The target row is still updated (as a plain literal value)...
        $this->assertNotSame('OLD_TARGET', $target->num_releve, 'The targeted row should still be updated');
        // ...but the unrelated row is now safe: injection neutralised.
        $this->assertSame(
            'SENTINEL_B',
            $bystander->num_releve,
            'With an escaped num_releve the unrelated row must be untouched (this is the fixed behaviour)'
        );
    }

    /**
     * Insert a raw llx_bank line and return its rowid.
     */
    private function insertBankLine(string $numChq, string $numReleve, string $fkType, float $amount): int
    {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "bank"
            . " (datec, dateo, datev, amount, label, fk_account, fk_type, num_releve, num_chq)"
            . " VALUES ("
            . "'" . date('Y-m-d H:i:s') . "', "
            . "'" . date('Y-m-d') . "', "
            . "'" . date('Y-m-d') . "', "
            . ((float) $amount) . ", "
            . "'test line', "
            . "1, "
            . "'" . $this->db->escape($fkType) . "', "
            . "'" . $this->db->escape($numReleve) . "', "
            . "'" . $this->db->escape($numChq) . "')";
        $res = $this->db->query($sql);
        $this->assertNotFalse($res, 'insertBankLine failed: ' . $this->db->lasterror());
        return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . "bank");
    }

    /**
     * Fetch a single llx_bank row by rowid.
     */
    private function fetchBankRow(int $rowid): object
    {
        $res = $this->db->query("SELECT rowid, num_releve FROM " . MAIN_DB_PREFIX . "bank WHERE rowid = " . (int) $rowid);
        $this->assertNotFalse($res, 'fetchBankRow query failed: ' . $this->db->lasterror());
        $obj = $this->db->fetch_object($res);
        $this->assertNotNull($obj, 'Bank row ' . $rowid . ' not found');
        return $obj;
    }

    /**
     * Load the REAL stancer_find_update() from the page source without running
     * the page. The function text is extracted with the PHP tokenizer (exact
     * bytes, no manual copy) and eval'd into the global namespace, so the test
     * exercises production code as-is.
     */
    private function loadStancerFindUpdate(): void
    {
        if (function_exists('stancer_find_update')) {
            return;
        }

        $file = dirname(__DIR__, 3) . '/stancer_import_check_reversements.php';
        $src = file_get_contents($file);
        $this->assertNotFalse($src, 'Cannot read page source: ' . $file);

        $tokens = token_get_all($src);
        $n = count($tokens);
        $code = null;

        for ($i = 0; $i < $n; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            // Next significant token must be the function name.
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== 'stancer_find_update') {
                continue;
            }
            // Walk to the opening brace, then balance braces to the end of body.
            $k = $j;
            while ($k < $n && $tokens[$k] !== '{') {
                $k++;
            }
            $depth = 0;
            $end = $k;
            for (; $end < $n; $end++) {
                $t = $tokens[$end];
                if ($t === '{'
                    || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $code = '';
            for ($m = $i; $m <= $end; $m++) {
                $code .= is_array($tokens[$m]) ? $tokens[$m][1] : $tokens[$m];
            }
            break;
        }

        $this->assertNotNull($code, 'Could not extract stancer_find_update() from the page source');
        // Force the global namespace so the function and its unqualified core
        // calls (price2num, getDolGlobalString, ...) resolve as in production.
        eval("namespace {\n" . $code . "\n}");
        $this->assertTrue(function_exists('stancer_find_update'), 'stancer_find_update() undefined after eval');
    }
}
