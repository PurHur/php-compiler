<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\intl\VmLocale;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/** locale_get_default()/Locale gated on extension_loaded('intl') (#19670, re-#16214). */
final class LocaleDefaultTest extends TestCase
{
    protected function tearDown(): void
    {
        VmLocale::resetDefaultForTests();
    }

    public function test_locale_default_withheld_without_intl_extension(): void
    {
        if (IntlExtensionPolicy::advertisesLocale()) {
            self::markTestSkipped('Locale advertises with host php-intl (#22691)');
        }
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertFalse(IntlExtensionPolicy::advertisesLocale());
        self::assertFalse(VmReflection::functionExists($ctx, 'locale_get_default'));
        self::assertFalse(VmReflection::functionExists($ctx, 'locale_set_default'));
        self::assertFalse(VmReflection::classExists($ctx, 'Locale'));
        self::assertFalse(VmReflection::functionExists($ctx, 'grapheme_str_contains'));
        self::assertFalse(\PHPCompiler\ext\standard\ModuleRegistry::extensionLoaded('intl'));

        $code = <<<'PHP'
<?php
echo (int) extension_loaded('intl');
echo (int) function_exists('locale_get_default');
echo (int) class_exists('Locale', false);
echo (int) function_exists('grapheme_str_contains');
PHP;
        $block = $runtime->parseAndCompile($code, 'locale_default.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('0000', ob_get_clean());
    }

    public function test_locale_default_advertised_with_icu_intl(): void
    {
        if (!IntlExtensionPolicy::advertisesLocale()) {
            self::markTestSkipped('ICU-backed ext/intl not available');
        }
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::functionExists($ctx, 'locale_get_default'));
        self::assertTrue(VmReflection::classExists($ctx, 'Locale'));
        self::assertTrue(\PHPCompiler\ext\standard\ModuleRegistry::extensionLoaded('intl'));

        $code = <<<'PHP'
<?php
echo (int) extension_loaded('intl');
echo (int) function_exists('locale_get_default');
echo (int) class_exists('Locale', false);
PHP;
        $block = $runtime->parseAndCompile($code, 'locale_default_icu.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('111', ob_get_clean());
    }

    public function test_locale_set_default_repro(): void
    {
        if (!IntlExtensionPolicy::advertisesLocale()) {
            self::markTestSkipped('locale_* withheld until extension_loaded(\'intl\') (#19670)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
declare(strict_types=1);
var_dump(locale_set_default('en_US'));
echo locale_get_default(), "\n";
echo Locale::getDefault(), "\n";
var_dump(Locale::setDefault('de_DE'));
echo Locale::getDefault(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'locale_set.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "bool(true)\nen_US\nen_US\nbool(true)\nde_DE\n",
            ob_get_clean()
        );
    }

    /** Idle default: C.UTF-8 / C / POSIX → en_US_POSIX like Zend/ICU (#22578). */
    public function test_idle_default_maps_c_locale_to_en_us_posix(): void
    {
        if (!IntlExtensionPolicy::advertisesLocale()) {
            self::markTestSkipped('locale_* withheld until extension_loaded(\'intl\') (#19670)');
        }
        $prevLang = getenv('LANG');
        $prevLcAll = getenv('LC_ALL');
        putenv('LC_ALL=');
        putenv('LANG=C.UTF-8');
        VmLocale::resetDefaultForTests();
        try {
            self::assertSame('en_US_POSIX', VmLocale::getDefault());
            putenv('LANG=C');
            VmLocale::resetDefaultForTests();
            self::assertSame('en_US_POSIX', VmLocale::getDefault());
            putenv('LANG=POSIX');
            VmLocale::resetDefaultForTests();
            self::assertSame('en_US_POSIX', VmLocale::getDefault());
        } finally {
            false === $prevLang ? putenv('LANG') : putenv('LANG='.$prevLang);
            false === $prevLcAll ? putenv('LC_ALL') : putenv('LC_ALL='.$prevLcAll);
            VmLocale::resetDefaultForTests();
        }
    }

    public function test_locale_get_primary_language_and_display_name(): void
    {
        if (!IntlExtensionPolicy::advertisesLocale()) {
            self::markTestSkipped('Locale OOP withheld until extension_loaded(\'intl\') (#19670)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
declare(strict_types=1);
echo Locale::getPrimaryLanguage('en_US'), "\n";
echo Locale::getRegion('en_US'), "\n";
echo Locale::getDisplayName('en_US'), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'locale_parts_oop.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("en\nUS\nEnglish (United States)\n", ob_get_clean());
    }
}
