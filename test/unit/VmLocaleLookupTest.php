<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\IntlError;
use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\intl\VmLocale;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/** @group intl_oop */
final class VmLocaleLookupTest extends TestCase
{
    public function test_withheld_without_intl(): void
    {
        if (IntlExtensionPolicy::advertisesLocale()) {
            self::markTestSkipped('Locale advertises with host php-intl (#22691)');
        }
        $runtime = new Runtime();
        self::assertFalse(IntlExtensionPolicy::advertisesLocale());
        self::assertFalse(VmReflection::classExists($runtime->vmContext, 'Locale'));
        self::assertFalse(VmReflection::functionExists($runtime->vmContext, 'locale_lookup'));
    }

    public function test_lookup_filter_accept_forced_registration(): void
    {
        $runtime = new Runtime();
        BuiltinClasses::registerLocale($runtime->vmContext);

        $code = <<<'PHP'
<?php
echo Locale::lookup(['de-DEDE', 'de-DE', 'de', 'fr'], 'de-DE-1996'), "\n";
echo Locale::lookup(['de-DE', 'fr-FR'], 'de-CH', false, 'en_US'), "\n";
echo (int) Locale::filterMatches('de-DE', 'de'), "\n";
echo (int) Locale::filterMatches('fr-FR', 'de'), "\n";
echo Locale::acceptFromHttp('en-US,en;q=0.9,fr;q=0.8'), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'locale_lookup_basic.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("de-DE\nen_US\n1\n0\nen_US\n", $out);
    }

    public function test_vm_helpers_match_php_src_lookup(): void
    {
        self::assertSame(
            'de-DE',
            VmLocale::lookup(['de-DEDE', 'de-DE', 'de', 'fr'], 'de-DE-1996')
        );
        self::assertSame(
            'en_US',
            VmLocale::lookup(['de-DE', 'fr-FR'], 'de-CH', false, 'en_US')
        );
        self::assertTrue(VmLocale::filterMatches('de-DE', 'de'));
        self::assertFalse(VmLocale::filterMatches('fr-FR', 'de'));
        $accepted = VmLocale::acceptFromHttp('en-US,en;q=0.9,fr;q=0.8');
        self::assertIsString($accepted);
        self::assertSame('en_US', $accepted);
    }

    /** #22853 — ICU reject sets U_ILLEGAL_ARGUMENT_ERROR (php-src locale_methods.c). */
    public function test_accept_from_http_invalid_sets_illegal_argument(): void
    {
        IntlError::clear();
        $r = VmLocale::acceptFromHttp('!!!invalid!!!');
        self::assertFalse($r);
        self::assertSame(IntlError::U_ILLEGAL_ARGUMENT_ERROR, IntlError::getCode());
        self::assertSame(
            'locale_accept_from_http: failed to find acceptable locale: U_ILLEGAL_ARGUMENT_ERROR',
            IntlError::getMessage()
        );
        self::assertTrue(IntlError::isFailure(IntlError::getCode()));

        $ok = VmLocale::acceptFromHttp('en-US,en;q=0.5');
        self::assertSame('en_US', $ok);
        self::assertFalse(IntlError::isFailure(IntlError::getCode()));
    }
}
