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

        if (!IntlExtensionPolicy::advertisesBuiltins()) {
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

            return;
        }

        self::assertTrue(VmReflection::classExists($ctx, 'IntlDateFormatter'));
        self::assertTrue(VmReflection::classExists($ctx, 'Collator'));
        self::assertTrue(VmReflection::functionExists($ctx, 'intl_get_error_code'));
        self::assertTrue(\PHPCompiler\ext\standard\ModuleRegistry::extensionLoaded('intl'));

        $code = <<<'PHP'
<?php
echo (int) extension_loaded('intl');
echo (int) class_exists('IntlDateFormatter', false);
echo (int) class_exists('Collator', false);
echo (int) function_exists('intl_get_error_code');
echo (int) function_exists('grapheme_strlen');
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_module_icu.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('11111', ob_get_clean());
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
$c = Collator::create('en_US');
echo (int) ($c->compare('a', 'b') < 0), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_datefmt.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("2024-03-15\n1\n", ob_get_clean());
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

    public function test_messageformatter_format_via_forced_registration(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerMessageFormatter($runtime->vmContext);
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\msgfmt_create());
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\msgfmt_format());
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\msgfmt_format_message());
        $code = <<<'PHP'
<?php
$fmt = msgfmt_create('en_US', '{0, number} files');
echo msgfmt_format($fmt, [3]), "\n";
$oop = MessageFormatter::create('en_US', '{name}');
$oop->setPattern('{name} uploaded');
echo $oop->format(['name' => 'doc']), "\n";
echo msgfmt_format_message('en_US', '{0} x', [1]), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_msgfmt_forced.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("3 files\ndoc uploaded\n1 x\n", ob_get_clean());
    }

    public function test_transliterator_latin_ascii_via_forced_registration(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerTransliterator($runtime->vmContext);
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\transliterator_create());
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\transliterator_transliterate());
        $code = <<<'PHP'
<?php
$tr = transliterator_create('Any-Latin; Latin-ASCII');
echo $tr === false || $tr === null ? 'null' : 'obj', "\n";
echo transliterator_transliterate($tr, 'café'), "\n";
$bad = transliterator_create('Not-A-Real-ID-XYZ');
echo $bad === false || $bad === null ? 'bad_null' : 'bad_obj', "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_transliterator_forced.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("obj\ncafe\nbad_null\n", ob_get_clean());
    }

    public function test_resourcebundle_version_via_forced_registration(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerResourceBundle($runtime->vmContext);
        $code = <<<'PHP'
<?php
$rb = ResourceBundle::create('en', null);
echo $rb === false || $rb === null ? 'null' : 'obj', "\n";
$ver = $rb->get('Version');
echo is_string($ver) && $ver !== '' ? 'version_ok' : 'version_bad', "\n";
echo strlen($ver) > 0 ? '1' : '0', "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_resourcebundle_forced.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("obj\nversion_ok\n1\n", ob_get_clean());
    }

    public function test_breakiterator_word_parts_via_forced_registration(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerBreakIterator($runtime->vmContext);
        $code = <<<'PHP'
<?php
foreach (['IntlBreakIterator', 'IntlRuleBasedBreakIterator', 'IntlPartsIterator'] as $c) {
    echo $c, '=', class_exists($c, false) ? '1' : '0', "\n";
}
$bi = IntlBreakIterator::createWordInstance('en_US');
echo get_class($bi), "\n";
$bi->setText('Hello world');
$parts = [];
$start = $bi->first();
while (($end = $bi->next()) !== IntlBreakIterator::DONE) {
    $parts[] = substr('Hello world', $start, $end - $start);
    $start = $end;
}
echo implode('|', $parts), "\n";
$it = $bi->getPartsIterator();
$it->rewind();
$out = [];
while ($it->valid()) {
    $out[] = $it->current();
    $it->next();
}
echo implode('|', $out), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_breakiterator_forced.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "IntlBreakIterator=1\nIntlRuleBasedBreakIterator=1\nIntlPartsIterator=1\n"
            ."IntlRuleBasedBreakIterator\nHello| |world\nHello| |world\n",
            ob_get_clean()
        );
    }
}
