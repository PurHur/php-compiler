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
$c = Collator::create('en_US');
echo (int) ($c->compare('a', 'b') < 0), "\n";
echo (int) ($c->compare('b', 'a') > 0), "\n";
echo (int) (0 === $c->compare('a', 'a')), "\n";
$p = collator_create('en_US');
echo (int) ($p->compare('a', 'b') < 0), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_collator.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("1\n1\n1\n1\n", ob_get_clean());
    }

    public function test_collator_compare_asort_via_forced_registration(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerCollator($runtime->vmContext);
        // Procedural alias needs function table entry.
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\collator_create());
        $code = <<<'PHP'
<?php
$c = Collator::create('en_US');
echo $c->compare('a', 'b'), "\n";
echo $c->compare('b', 'a'), "\n";
$arr = ['x' => 'c', 'y' => 'a', 'z' => 'b'];
$c->asort($arr);
echo implode(',', $arr), "\n";
$p = collator_create('en_US');
echo get_class($p), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_collator_forced.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertMatchesRegularExpression('/^-1\n1\na,b,c\nCollator\n$/', $out);
    }
}
