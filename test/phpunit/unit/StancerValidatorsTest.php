<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../lib/stancer_validators.lib.php';

/**
 * Unit tests for lib/stancer_validators.lib.php.
 *
 * Constraints checked come from two sources:
 *   1. docs/202603-openapi.json (CardIn, SepaIn, CustomerIn schemas):
 *      types, lengths, enums.
 *   2. Real-world API responses observed in production (e.g. 422
 *      "Must contain only digits" on card.number, not present in the spec).
 *
 * Lesson learned: the OpenAPI spec is necessary but not sufficient.
 * These tests intentionally cover BOTH spec rules and observed rules.
 */
class StancerValidatorsTest extends TestCase
{
    // =========================================================================
    // stancerSanitizeCardData: number sanitization
    // =========================================================================

    public function testCardNumberStripsSpacesFromInputMask(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242 4242 4242 4242',
            'cbexp_month' => 12,
            'cbexp_year' => 2030,
            'cbccv' => '123',
        ]);
        $this->assertIsArray($out);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertEquals('4242424242424242', $out['number']);
    }

    public function testCardNumberStripsDashes(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242-4242-4242-4242',
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '999',
        ]);
        $this->assertEquals('4242424242424242', $out['number']);
    }

    public function testCardNumberStripsMixedNonDigits(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242.4242 4242-4242',
            'cbexp_month' => 6,
            'cbexp_year' => 2026,
            'cbccv' => '321',
        ]);
        $this->assertEquals('4242424242424242', $out['number']);
    }

    public function testCardNumberStripsLetters(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => 'a4242b4242c4242d4242',
            'cbexp_month' => 6,
            'cbexp_year' => 2026,
            'cbccv' => '321',
        ]);
        $this->assertEquals('4242424242424242', $out['number']);
    }

    public function testCardNumberRejectsEmptyInput(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '',
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '123',
        ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsString('empty', $out['error']);
    }

    public function testCardNumberRejectsOnlySpaces(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '   ',
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '123',
        ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function testCardNumberRejectsTooShort(): void
    {
        // 12 digits: below OpenAPI minLength=13
        $out = stancerSanitizeCardData([
            'cbnumber' => '424242424242',
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '123',
        ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsString('length=12', $out['error']);
    }

    public function testCardNumberRejectsTooLong(): void
    {
        // 20 digits: above OpenAPI maxLength=19
        $out = stancerSanitizeCardData([
            'cbnumber' => '42424242424242424242',
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '123',
        ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsString('length=20', $out['error']);
    }

    public function testCardNumberAcceptsBoundary13(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424',  // exactly 13 digits
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '123',
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertEquals('4242424242424', $out['number']);
    }

    public function testCardNumberAcceptsBoundary19(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424242424',  // exactly 19 digits
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '123',
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertEquals('4242424242424242424', $out['number']);
    }

    // =========================================================================
    // stancerSanitizeCardData: cvc
    // =========================================================================

    public function testCvcStripsNonDigits(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424242',
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '1 2 3',
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertEquals('123', $out['cvc']);
    }

    public function testCvcRejectsTooShort(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424242',
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '12',
        ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsString('cbccv', $out['error']);
    }

    public function testCvcRejectsTooLong(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424242',
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '12345',
        ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function testCvcAccepts4Digits(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424242',
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '1234',
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertEquals('1234', $out['cvc']);
    }

    // =========================================================================
    // stancerSanitizeCardData: expiration
    // =========================================================================

    public function testExpMonthRejectsZero(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424242',
            'cbexp_month' => 0,
            'cbexp_year' => 2025,
            'cbccv' => '123',
        ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsString('cbexp_month', $out['error']);
    }

    public function testExpMonthRejectsAbove12(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424242',
            'cbexp_month' => 13,
            'cbexp_year' => 2025,
            'cbccv' => '123',
        ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function testExpYearRejectsBelow2019(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424242',
            'cbexp_month' => 1,
            'cbexp_year' => 2018,
            'cbccv' => '123',
        ]);
        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsString('cbexp_year', $out['error']);
    }

    public function testExpYearRejectsAbove2099(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424242',
            'cbexp_month' => 1,
            'cbexp_year' => 2100,
            'cbccv' => '123',
        ]);
        $this->assertArrayHasKey('error', $out);
    }

    // =========================================================================
    // stancerSanitizeCardData: name
    // =========================================================================

    public function testNameTruncatesAt64Chars(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424242',
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '123',
            'cbname' => str_repeat('A', 100),
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertEquals(64, strlen($out['name']));
    }

    public function testNameDefaultsToEmptyWhenAbsent(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424242',
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '123',
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertEquals('', $out['name']);
    }

    public function testNameTrimsWhitespace(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424242',
            'cbexp_month' => 1,
            'cbexp_year' => 2025,
            'cbccv' => '123',
            'cbname' => '  John Doe  ',
        ]);
        $this->assertEquals('John Doe', $out['name']);
    }

    // =========================================================================
    // stancerSanitizeCardData: full payload shape
    // =========================================================================

    public function testValidCardReturnsExactKeysExpectedByApi(): void
    {
        $out = stancerSanitizeCardData([
            'cbnumber' => '4242424242424242',
            'cbexp_month' => 12,
            'cbexp_year' => 2030,
            'cbccv' => '123',
            'cbname' => 'John Doe',
        ]);
        $this->assertArrayNotHasKey('error', $out);
        // OpenAPI CardIn keys: number, exp_month, exp_year, cvc, name
        $this->assertEquals(['number', 'exp_month', 'exp_year', 'cvc', 'name'], array_keys($out));
        $this->assertIsString($out['number']);
        $this->assertIsInt($out['exp_month']);
        $this->assertIsInt($out['exp_year']);
        $this->assertIsString($out['cvc']);
        $this->assertIsString($out['name']);
    }

    // =========================================================================
    // stancerSanitizeSepaData
    // =========================================================================

    public function testSepaIbanStripsSpaces(): void
    {
        $out = stancerSanitizeSepaData([
            'iban' => 'FR76 3000 1007 9412 3456 7890 185',
            'name' => 'Test',
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertEquals('FR7630001007941234567890185', $out['iban']);
    }

    public function testSepaIbanUppercases(): void
    {
        $out = stancerSanitizeSepaData([
            'iban' => 'fr7630001007941234567890185',
            'name' => 'Test',
        ]);
        $this->assertEquals('FR7630001007941234567890185', $out['iban']);
    }

    public function testSepaIbanRejectsEmpty(): void
    {
        $out = stancerSanitizeSepaData([
            'iban' => '',
            'name' => 'Test',
        ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function testSepaIbanRejectsNonAlphanumeric(): void
    {
        $out = stancerSanitizeSepaData([
            'iban' => 'FR76-3000-1007@941234567890185',
            'name' => 'Test',
        ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function testSepaIbanRejectsTooShort(): void
    {
        $out = stancerSanitizeSepaData([
            'iban' => 'FR76300010',  // 10 chars
            'name' => 'Test',
        ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function testSepaIbanRejectsTooLong(): void
    {
        $out = stancerSanitizeSepaData([
            'iban' => str_repeat('A', 35),
            'name' => 'Test',
        ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function testSepaBicAcceptsValid8(): void
    {
        $out = stancerSanitizeSepaData([
            'iban' => 'FR7630001007941234567890185',
            'bic'  => 'BNPAFRPP',
            'name' => 'Test',
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertEquals('BNPAFRPP', $out['bic']);
    }

    public function testSepaBicAcceptsValid11(): void
    {
        $out = stancerSanitizeSepaData([
            'iban' => 'FR7630001007941234567890185',
            'bic'  => 'BNPAFRPPXXX',
            'name' => 'Test',
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertEquals('BNPAFRPPXXX', $out['bic']);
    }

    public function testSepaBicRejectsInvalidLength(): void
    {
        $out = stancerSanitizeSepaData([
            'iban' => 'FR7630001007941234567890185',
            'bic'  => 'BNPAFR',  // 6 chars, invalid
            'name' => 'Test',
        ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function testSepaOmitsBicWhenEmpty(): void
    {
        $out = stancerSanitizeSepaData([
            'iban' => 'FR7630001007941234567890185',
            'name' => 'Test',
        ]);
        $this->assertArrayNotHasKey('bic', $out);
    }

    // =========================================================================
    // stancerSanitizeCustomerData
    // =========================================================================

    public function testCustomerEmailValid(): void
    {
        $out = stancerSanitizeCustomerData([
            'email' => 'john.doe@example.com',
            'name' => 'John Doe',
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertEquals('john.doe@example.com', $out['email']);
    }

    public function testCustomerEmailInvalid(): void
    {
        $out = stancerSanitizeCustomerData([
            'email' => 'not-an-email',
            'name' => 'John Doe',
        ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function testCustomerMobileStripsSpacesAndPunctuation(): void
    {
        $out = stancerSanitizeCustomerData([
            'mobile' => '+33 6 12.34-56 78',
            'name' => 'John Doe',
        ]);
        $this->assertArrayNotHasKey('error', $out);
        $this->assertEquals('+33612345678', $out['mobile']);
    }

    public function testCustomerMobileWithoutPlus(): void
    {
        $out = stancerSanitizeCustomerData([
            'mobile' => '06 12 34 56 78',
            'name' => 'John Doe',
        ]);
        $this->assertEquals('0612345678', $out['mobile']);
    }

    public function testCustomerNameRequired(): void
    {
        $out = stancerSanitizeCustomerData([
            'email' => 'a@b.fr',
            'name' => '',
        ]);
        $this->assertArrayHasKey('error', $out);
    }

    public function testCustomerNameTruncates(): void
    {
        $out = stancerSanitizeCustomerData([
            'name' => str_repeat('A', 100),
        ]);
        $this->assertEquals(64, strlen($out['name']));
    }

    public function testCustomerOmitsAbsentOptionalKeys(): void
    {
        $out = stancerSanitizeCustomerData([
            'name' => 'John',
        ]);
        $this->assertArrayNotHasKey('email', $out);
        $this->assertArrayNotHasKey('mobile', $out);
        $this->assertEquals(['name'], array_keys($out));
    }

    // =========================================================================
    // stancerValidateApiKey / stancerApiKeyRules
    // Catches the most common admin-setup mistake: pasting a key into the wrong
    // slot (public vs private, test vs prod). The Stancer API would otherwise
    // return a generic 401 "Invalid token" that is hard to diagnose.
    // =========================================================================

    public function testApiKeyRulesExposesFourSlots(): void
    {
        $rules = stancerApiKeyRules();
        $expected = [
            'STANCER_TEST_PUBLIC_KEY',
            'STANCER_TEST_PRIVATE_KEY',
            'STANCER_PROD_PUBLIC_KEY',
            'STANCER_PROD_PRIVATE_KEY',
        ];
        foreach ($expected as $slot) {
            $this->assertArrayHasKey($slot, $rules, "Missing rule for $slot");
            $this->assertArrayHasKey('regex', $rules[$slot]);
            $this->assertArrayHasKey('pattern', $rules[$slot]);
            $this->assertArrayHasKey('prefix', $rules[$slot]);
        }
    }

    public function testApiKeyAcceptsCorrectTestPublic(): void
    {
        $this->assertTrue(stancerValidateApiKey('STANCER_TEST_PUBLIC_KEY', 'ptest_bKsiz4RjGaDpugcrqfYOV4OW'));
    }

    public function testApiKeyAcceptsCorrectTestPrivate(): void
    {
        $this->assertTrue(stancerValidateApiKey('STANCER_TEST_PRIVATE_KEY', 'stest_aBcDeFgHiJkLmNoPqRsTuVwX'));
    }

    public function testApiKeyAcceptsProdPublicWithPprodPrefix(): void
    {
        $this->assertTrue(stancerValidateApiKey('STANCER_PROD_PUBLIC_KEY', 'pprod_abcdefghijklmnopqrstuvwx'));
    }

    public function testApiKeyAcceptsProdPublicWithPlivePrefix(): void
    {
        // 'plive_' is the legacy/alternate prefix some Stancer accounts have.
        $this->assertTrue(stancerValidateApiKey('STANCER_PROD_PUBLIC_KEY', 'plive_abcdefghijklmnopqrstuvwx'));
    }

    public function testApiKeyAcceptsProdPrivateWithSprodPrefix(): void
    {
        $this->assertTrue(stancerValidateApiKey('STANCER_PROD_PRIVATE_KEY', 'sprod_abcdefghijklmnopqrstuvwx'));
    }

    public function testApiKeyAcceptsProdPrivateWithSlivePrefix(): void
    {
        $this->assertTrue(stancerValidateApiKey('STANCER_PROD_PRIVATE_KEY', 'slive_abcdefghijklmnopqrstuvwx'));
    }

    public function testApiKeyEmptyValueIsAcceptedAsRemoval(): void
    {
        // Empty value means "delete this config key" and must be allowed.
        $this->assertTrue(stancerValidateApiKey('STANCER_TEST_PRIVATE_KEY', ''));
        $this->assertTrue(stancerValidateApiKey('STANCER_PROD_PRIVATE_KEY', ''));
    }

    // ---- The actual bug the user reported: ptest_ pasted in PRIVATE field ----

    public function testApiKeyRejectsPublicPastedInPrivateSlotTest(): void
    {
        // This is the exact scenario observed: HTTP 401 with ptest_... in the
        // Basic auth header because admin pasted the public key in the private
        // field. Must be rejected at save time.
        $this->assertFalse(stancerValidateApiKey('STANCER_TEST_PRIVATE_KEY', 'ptest_bKsiz4RjGaDpugcrqfYOV4OW'));
    }

    public function testApiKeyRejectsPrivatePastedInPublicSlotTest(): void
    {
        $this->assertFalse(stancerValidateApiKey('STANCER_TEST_PUBLIC_KEY', 'stest_aBcDeFgHiJkLmNoPqRsTuVwX'));
    }

    public function testApiKeyRejectsPublicPastedInPrivateSlotProd(): void
    {
        $this->assertFalse(stancerValidateApiKey('STANCER_PROD_PRIVATE_KEY', 'pprod_abcdefghijklmnopqrstuvwx'));
    }

    public function testApiKeyRejectsPrivatePastedInPublicSlotProd(): void
    {
        $this->assertFalse(stancerValidateApiKey('STANCER_PROD_PUBLIC_KEY', 'sprod_abcdefghijklmnopqrstuvwx'));
    }

    // ---- Cross-environment swaps (test key in prod slot and vice versa) ----

    public function testApiKeyRejectsTestKeyInProdSlot(): void
    {
        $this->assertFalse(stancerValidateApiKey('STANCER_PROD_PRIVATE_KEY', 'stest_aBcDeFgHiJkLmNoPqRsTuVwX'));
        $this->assertFalse(stancerValidateApiKey('STANCER_PROD_PUBLIC_KEY', 'ptest_aBcDeFgHiJkLmNoPqRsTuVwX'));
    }

    public function testApiKeyRejectsProdKeyInTestSlot(): void
    {
        $this->assertFalse(stancerValidateApiKey('STANCER_TEST_PRIVATE_KEY', 'sprod_aBcDeFgHiJkLmNoPqRsTuVwX'));
        $this->assertFalse(stancerValidateApiKey('STANCER_TEST_PUBLIC_KEY', 'pprod_aBcDeFgHiJkLmNoPqRsTuVwX'));
    }

    // ---- Garbage input ----

    public function testApiKeyRejectsGarbage(): void
    {
        $this->assertFalse(stancerValidateApiKey('STANCER_TEST_PRIVATE_KEY', 'hello world'));
        $this->assertFalse(stancerValidateApiKey('STANCER_TEST_PRIVATE_KEY', 'STEST_uppercase_prefix_unsupported'));
        $this->assertFalse(stancerValidateApiKey('STANCER_TEST_PRIVATE_KEY', 'stest_'));        // prefix alone
        $this->assertFalse(stancerValidateApiKey('STANCER_TEST_PRIVATE_KEY', 'stest_abc def')); // space inside
        $this->assertFalse(stancerValidateApiKey('STANCER_TEST_PRIVATE_KEY', 'stest_abc-def')); // dash inside
    }

    public function testApiKeyRejectsUnknownConstName(): void
    {
        $this->assertFalse(stancerValidateApiKey('STANCER_UNKNOWN_KEY', 'stest_abcdefghijklmnopqrstuvwx'));
    }

    public function testApiKeyPatternForHtml5MatchesSameValuesAsServerRegex(): void
    {
        // The pattern exposed for the HTML5 'pattern' attribute is anchored
        // implicitly by the browser; the server regex anchors with ^/$. Both
        // must accept/reject the same strings for the same slot, otherwise a
        // value could pass client validation and be rejected server-side (or
        // vice versa).
        $rules = stancerApiKeyRules();
        $samples = [
            'ptest_bKsiz4RjGaDpugcrqfYOV4OW',
            'stest_aBcDeFgHiJkLmNoPqRsTuVwX',
            'pprod_abcdefghijklmnopqrstuvwx',
            'plive_abcdefghijklmnopqrstuvwx',
            'sprod_abcdefghijklmnopqrstuvwx',
            'slive_abcdefghijklmnopqrstuvwx',
            'hello world',
            'PTEST_uppercase',
            'stest_',
            '',
        ];
        foreach ($rules as $slot => $rule) {
            $serverRegex = $rule['regex'];
            $clientRegex = '/^' . $rule['pattern'] . '$/'; // mimic browser anchoring
            foreach ($samples as $sample) {
                $serverMatch = (bool) preg_match($serverRegex, $sample);
                $clientMatch = (bool) preg_match($clientRegex, $sample);
                $this->assertSame(
                    $serverMatch,
                    $clientMatch,
                    "Client/server regex divergence for slot $slot on sample '$sample'"
                );
            }
        }
    }
}
