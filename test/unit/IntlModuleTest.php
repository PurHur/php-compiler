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

    public function test_messageformatter_missing_args_strip_skeleton(): void
    {
        // php-src msgformat_format.c / ICU — missing args leave {n}/{name} (#22946).
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerMessageFormatter($runtime->vmContext);
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\msgfmt_create());
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\msgfmt_format());
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\msgfmt_format_message());
        $code = <<<'PHP'
<?php
$f = MessageFormatter::create('en_US', 'Item {0,number} of {1,number}');
echo 'both=', $f->format([3, 10]), "\n";
echo 'one=', $f->format([3]), "\n";
echo 'none=', $f->format([]), "\n";
$f2 = MessageFormatter::create('en_US', '{0,select,male{he}female{she}other{they}} went');
echo 'selMiss=', $f2->format([]), "\n";
$f3 = MessageFormatter::create('en_US', 'Hi {name}');
echo 'named=', $f3->format(['name' => 'Bob']), "\n";
$f4 = MessageFormatter::create('en_US', 'Hi {name,select,other{X}}');
echo 'namedMiss=', $f4->format([]), "\n";
$f5 = MessageFormatter::create('en_US', '{0,plural,one{# item} other{# items}}');
echo 'plMiss=', $f5->format([]), "\n";
echo 'proc=', msgfmt_format_message('en_US', 'Item {0,number} of {1,number}', [3]), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_msgfmt_missing_args.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "both=Item 3 of 10\n"
            ."one=Item 3 of {1}\n"
            ."none=Item {0} of {1}\n"
            ."selMiss={0} went\n"
            ."named=Hi Bob\n"
            ."namedMiss=Hi {name}\n"
            ."plMiss={0}\n"
            ."proc=Item 3 of {1}\n",
            ob_get_clean()
        );
    }

    public function test_messageformatter_invalid_pattern_create_null_construct_throws(): void
    {
        require_once dirname(__DIR__, 2).'/ext/intl/bootstrap_intlexception.php';
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerMessageFormatter($runtime->vmContext);
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\msgfmt_create());
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\intl_get_error_code());
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\intl_get_error_message());
        $code = <<<'PHP'
<?php
echo 'idle=', var_export(intl_get_error_message(), true), "\n";
$bad = MessageFormatter::create('en_US', '{invalid');
echo 'type=', get_debug_type($bad), "\n";
echo 'msg=', intl_get_error_message(), "\n";
echo 'code=', intl_get_error_code(), "\n";
try {
    new MessageFormatter('en_US', '{invalid');
    echo "no_throw\n";
} catch (Throwable $e) {
    echo 'err=', get_class($e), ':', $e->getMessage(), "\n";
}
$proc = msgfmt_create('en_US', '{invalid');
echo 'proc=', get_debug_type($proc), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_msgfmt_bad_pattern.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "idle='U_ZERO_ERROR'\n"
            ."type=null\n"
            ."msg=msgfmt_create: message formatter creation failed: U_UNMATCHED_BRACES\n"
            ."code=65801\n"
            ."err=IntlException:msgfmt_create: message formatter creation failed: U_UNMATCHED_BRACES\n"
            ."proc=null\n",
            ob_get_clean()
        );
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

    public function test_transliterator_string_id_via_forced_registration(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerTransliterator($runtime->vmContext);
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\transliterator_create());
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\transliterator_transliterate());
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\intl_get_error_message());
        $code = <<<'PHP'
<?php
$id = 'Any-Latin; Latin-ASCII';
echo 'str=', transliterator_transliterate($id, '東京'), "\n";
$bad = @transliterator_transliterate('Not-A-Real-ID-XYZ', '東京');
echo 'bad=', var_export($bad, true), "\n";
echo 'has_create_err=', (int) (false !== strpos(intl_get_error_message(), 'transliterator_create')), "\n";
try {
    transliterator_transliterate([], 'x');
    echo "array_ok\n";
} catch (TypeError $e) {
    echo 'union=', (int) (false !== strpos($e->getMessage(), 'Transliterator|string')), "\n";
}
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_transliterator_string_id.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("str=dong jing\nbad=false\nhas_create_err=1\nunion=1\n", ob_get_clean());
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

    /** @requires extension FFI */
    public function test_resourcebundle_create_fallback_warning_22854(): void
    {
        if (!\PHPCompiler\ext\intl\IntlExtensionPolicy::icuAvailable()) {
            $this->markTestSkipped('ICU FFI unavailable');
        }
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerResourceBundle($runtime->vmContext);
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\intl_get_error_code());
        $runtime->vmContext->declareFunction(new \PHPCompiler\ext\intl\intl_get_error_message());
        $code = <<<'PHP'
<?php
$b = ResourceBundle::create('xx_YY', 'ICUDATA', true);
echo is_object($b) ? "object\n" : "null\n";
echo intl_get_error_code(), "\n";
echo intl_get_error_message(), "\n";
echo $b->getErrorCode(), "\n";
echo $b->getErrorMessage(), "\n";
$none = ResourceBundle::create('xx_YY', 'ICUDATA', false);
echo is_object($none) ? "ff_obj\n" : "ff_null\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_resourcebundle_fallback_22854.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "object\n-127\nU_USING_DEFAULT_WARNING\n-127\nU_USING_DEFAULT_WARNING\nff_null\n",
            ob_get_clean()
        );
    }

    public function test_numberformatter_pattern_fraction_zeros_22579(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerNumberFormatter($runtime->vmContext);
        $code = <<<'PHP'
<?php
$nf = new NumberFormatter('en_US', NumberFormatter::PATTERN_DECIMAL, '#,##0.00');
echo 'fmt=', $nf->format(1234.5), "\n";
$nf2 = new NumberFormatter('en_US', NumberFormatter::DECIMAL);
$nf2->setPattern('#,##0.00');
echo 'set_fmt=', $nf2->format(1234.5), "\n";
echo 'min=', $nf2->getAttribute(NumberFormatter::MIN_FRACTION_DIGITS), "\n";
echo 'max=', $nf2->getAttribute(NumberFormatter::MAX_FRACTION_DIGITS), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_numfmt_pattern_frac_22579.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("fmt=1,234.50\nset_fmt=1,234.50\nmin=2\nmax=2\n", ob_get_clean());
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
$pi2 = $bi->getPartsIterator();
echo 'owner_same=', ($pi2->getBreakIterator() === $bi ? '1' : '0'), "\n";
echo 'pi_status=', (int) method_exists($pi2, 'getRuleStatus'), "\n";
$pi2->rewind();
echo 'first_status=', $pi2->getRuleStatus(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_breakiterator_forced.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "IntlBreakIterator=1\nIntlRuleBasedBreakIterator=1\nIntlPartsIterator=1\n"
            ."IntlRuleBasedBreakIterator\nHello| |world\nHello| |world\n"
            ."owner_same=1\npi_status=1\nfirst_status=200\n",
            ob_get_clean()
        );
    }

    public function test_breakiterator_preceding_following_locale_via_forced_registration(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerBreakIterator($runtime->vmContext);
        $code = <<<'PHP'
<?php
$bi = IntlBreakIterator::createWordInstance('en_US');
$bi->setText('Hello world');
echo (int) method_exists($bi, 'preceding'), "\n";
echo $bi->preceding(6), "\n";
echo $bi->current(), "\n";
echo $bi->following(6), "\n";
echo (int) $bi->isBoundary(5), (int) $bi->isBoundary(6), (int) $bi->isBoundary(7), "\n";
echo var_export($bi->getLocale(0), true), "\n";
echo var_export($bi->getLocale(1), true), "\n";
echo $bi->getErrorCode(), ':', $bi->getErrorMessage(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_breakiterator_preceding.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "1\n5\n5\n11\n110\n''\n'en_US'\n0:U_ZERO_ERROR\n",
            ob_get_clean()
        );
    }

    public function test_breakiterator_codepoint_via_forced_registration(): void
    {
        $runtime = new Runtime();
        \PHPCompiler\ext\intl\BuiltinClasses::registerBreakIterator($runtime->vmContext);
        $code = <<<'PHP'
<?php
echo (int) class_exists('IntlCodePointBreakIterator', false), "\n";
echo (int) method_exists('IntlBreakIterator', 'createCodePointInstance'), "\n";
$bi = IntlBreakIterator::createCodePointInstance();
echo get_class($bi), "\n";
$bi->setText("A\u{1F600}B");
$out = [];
for ($p = $bi->first(); $p !== IntlBreakIterator::DONE; $p = $bi->next()) {
    $out[] = $p;
}
echo json_encode($out), "\n";
$bi2 = IntlBreakIterator::createCodePointInstance();
$bi2->setText("A\u{1F600}B");
$bi2->first();
echo $bi2->getLastCodePoint(), "\n";
echo $bi2->next(), ':', $bi2->getLastCodePoint(), "\n";
echo $bi2->next(), ':', $bi2->getLastCodePoint(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_breakiterator_codepoint.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "1\n1\nIntlCodePointBreakIterator\n[0,1,5,6]\n-1\n1:65\n5:128512\n",
            ob_get_clean()
        );
    }

    public function test_locale_display_methods_via_vm(): void
    {
        if (!IntlExtensionPolicy::advertisesLocale()) {
            self::markTestSkipped('Locale withheld until extension_loaded(\'intl\') (#19670)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo Locale::getDisplayLanguage('en_US', 'en'), "\n";
echo Locale::getDisplayRegion('en_US', 'en'), "\n";
echo Locale::getDisplayScript('zh_Hans_CN', 'en'), "\n";
echo Locale::getDisplayVariant('en_US_POSIX', 'en'), "\n";
echo json_encode(Locale::getAllVariants('sl_IT_NEDIS_ROJAZ_ALBA')), "\n";
echo locale_get_display_language('fr', 'en'), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_locale_display.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "English\nUnited States\nSimplified Han\nComputer\n[\"NEDIS\",\"ROJAZ\",\"ALBA\"]\nFrench\n",
            ob_get_clean()
        );
    }

    public function test_msgfmt_format_message_null_strict_types_typeerror(): void
    {
        // php-src msgformat.stub.php Z_PARAM_STR — null under strict_types (#29921).
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            self::markTestSkipped('MessageFormatter withheld until extension_loaded(\'intl\') (#19670)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
declare(strict_types=1);
foreach ([
    'proc_locale' => static fn () => msgfmt_format_message(null, 'Hi {0}', [1]),
    'proc_pattern' => static fn () => msgfmt_format_message('en', null, [1]),
    'static_locale' => static fn () => MessageFormatter::formatMessage(null, 'Hi {0}', [1]),
    'static_pattern' => static fn () => MessageFormatter::formatMessage('en', null, [1]),
] as $name => $call) {
    try {
        $r = $call();
        echo $name, ' OK ', var_export($r, true), "\n";
    } catch (TypeError $e) {
        echo $name, ' TypeError';
        if (false !== strpos($e->getMessage(), 'null given')) {
            echo ' null';
        }
        if (false !== strpos($e->getMessage(), '($locale)')) {
            echo ' locale';
        }
        if (false !== strpos($e->getMessage(), '($pattern)')) {
            echo ' pattern';
        }
        echo "\n";
    }
}
echo 'ok=', msgfmt_format_message('en', 'Hi {0}', [1]), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intl_msgfmt_null_strict.php');
        ob_start();
        $runtime->run($block);
        self::assertSame(
            "proc_locale TypeError null locale\n"
            ."proc_pattern TypeError null pattern\n"
            ."static_locale TypeError null locale\n"
            ."static_pattern TypeError null pattern\n"
            ."ok=Hi 1\n",
            ob_get_clean()
        );
    }
}
