<?php

namespace Stancer\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guard tests for audit finding M3: reflected XSS on the public CB and IBAN
 * capture pages (public/cb.php, public/sepa-iban.php).
 *
 * POST values ($cbname, $cbnumber, $iban, $bic, ...) and $societe->name /
 * $mysoc->name were echoed raw inside value="..." / alt="..." attributes. The
 * 'alpha' GETPOST filter strips < and > but NOT the double quote, so a payload
 * like `" onfocus=alert(1) autofocus x="` breaks out of the attribute and
 * injects an event handler - critical on a page that collects a card number.
 *
 * Every reflected value must go through dol_escape_htmltag() (which
 * htmlspecialchars-escapes the double quote to &quot;). These are structural
 * guards: the pages require a valid securekey, so an end-to-end HTTP reflection
 * test would need to forge that hash.
 */
class StancerPublicPagesXssTest extends TestCase
{
    /**
     * No reflected variable may be echoed raw inside a value= or alt= attribute.
     */
    public function testNoRawReflectedValueInHtmlAttributes(): void
    {
        foreach (['public/cb.php', 'public/sepa-iban.php'] as $rel) {
            $src = file_get_contents(dirname(__DIR__, 3) . '/' . $rel);
            $this->assertNotFalse($src, "Cannot read $rel");
            $this->assertDoesNotMatchRegularExpression(
                '/(value|alt)="<\?php\s+echo\s+\$[A-Za-z_]/',
                $src,
                "M3: $rel still echoes a raw reflected value inside an HTML attribute"
            );
        }
    }

    public function testCbSensitiveFieldsAreHtmlEscaped(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/public/cb.php');
        foreach (['cbname', 'cbnumber', 'cbexpiry', 'cbccv'] as $var) {
            $this->assertMatchesRegularExpression(
                '/value="<\?php echo dol_escape_htmltag\(\$' . $var . '\)/',
                $src,
                "M3: cb.php field \$$var must be escaped with dol_escape_htmltag in its value attribute"
            );
        }
    }

    public function testIbanSensitiveFieldsAreHtmlEscaped(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/public/sepa-iban.php');
        foreach (['iban', 'bic', 'stancerCustomerNameOnIBAN', 'stancerIBANBankName'] as $var) {
            $this->assertMatchesRegularExpression(
                '/value="<\?php echo dol_escape_htmltag\(\$' . $var . '\)/',
                $src,
                "M3: sepa-iban.php field \$$var must be escaped with dol_escape_htmltag"
            );
        }
    }
}
