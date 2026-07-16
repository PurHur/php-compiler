<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\VmLocale;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/** locale_get_default()/Locale partial surface without extension_loaded('intl') (#6696, #9576). */
final class LocaleDefaultTest extends TestCase
{
    protected function tearDown(): void
    {
        VmLocale::resetDefaultForTests();
    }

    public function test_locale_default_advertised_without_intl_extension(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::functionExists($ctx, 'locale_get_default'));
        self::assertTrue(VmReflection::functionExists($ctx, 'locale_set_default'));
        self::assertTrue(VmReflection::classExists($ctx, 'Locale'));
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
        self::assertSame('0110', ob_get_clean());
    }

    public function test_locale_set_default_repro(): void
    {
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

    public function test_locale_get_primary_language_and_display_name(): void
    {
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
