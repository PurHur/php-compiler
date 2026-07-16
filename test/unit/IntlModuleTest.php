<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * intl module skeleton registration (issue #5774, #19670).
 *
 * @group intl_module_skeleton
 */
final class IntlModuleTest extends TestCase
{
    public function test_intl_module_skeleton_classes_and_function(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertFalse(VmReflection::classExists($ctx, 'IntlDateFormatter'));
        self::assertFalse(VmReflection::classExists($ctx, 'Collator'));
        self::assertFalse(VmReflection::classExists($ctx, 'IntlException'));
        self::assertFalse(VmReflection::functionExists($ctx, 'intl_get_error_code'));
        self::assertFalse(VmReflection::functionExists($ctx, 'grapheme_str_contains'));

        $code = <<<'PHP'
<?php
echo (int) class_exists('IntlDateFormatter', false);
echo (int) class_exists('Collator', false);
echo (int) function_exists('intl_get_error_code');
echo (int) function_exists('grapheme_str_contains');
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('0000', ob_get_clean());
    }

    public function test_intl_date_formatter_pattern_create_format(): void
    {
        if (!IntlExtensionPolicy::advertisesIntlDateFormatter()) {
            self::markTestSkipped('IntlDateFormatter withheld until extension_loaded(\'intl\') (#19670)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$f = IntlDateFormatter::create('en_US', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, 'yyyy-MM-dd');
echo $f->format(new DateTime('2024-03-15 12:34:56', new DateTimeZone('UTC'))), "\n";
try {
    Collator::create('en_US');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_datefmt.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "2024-03-15\nError: Class \"Collator\" not found\n",
            ob_get_clean()
        );
    }

    public function test_intl_skeleton_create_stubs_throw_when_advertised(): void
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            self::markTestSkipped('intl extension not advertised on reference profile');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
try {
    Collator::create('en_US');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_skeleton.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "Error: Collator::create() is not implemented in this compiler build (issue #5747)\n",
            ob_get_clean()
        );
    }
}
