<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\intl\VmSpoofchecker;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/** @group intl_oop */
final class VmSpoofcheckerTest extends TestCase
{
    public function test_withheld_without_intl(): void
    {
        if (IntlExtensionPolicy::advertisesBuiltins()) {
            self::markTestSkipped('Spoofchecker advertises with host php-intl (#22691)');
        }
        $runtime = new Runtime();
        self::assertFalse(IntlExtensionPolicy::advertisesBuiltins());
        self::assertFalse(VmReflection::classExists($runtime->vmContext, 'Spoofchecker'));
    }

    public function test_set_allowed_chars_withheld_on_default_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            self::assertFalse(CompilerVersion::supportsSpoofcheckerSetAllowedChars());
            $runtime = new Runtime();
            BuiltinClasses::registerSpoofchecker($runtime->vmContext);

            $code = <<<'PHP'
<?php
$s = new Spoofchecker();
echo 'set=', (int) method_exists($s, 'setAllowedChars'), "\n";
echo 'get=', (int) method_exists($s, 'getAllowedChars'), "\n";
echo 'ignore=', defined('Spoofchecker::IGNORE_SPACE') ? '1' : '0', "\n";
try {
    $s->setAllowedChars('[a-z]');
    echo "call=ok\n";
} catch (Error $e) {
    echo "call=undefined\n";
}
PHP;
            $block = $runtime->parseAndCompile($code, 'spoofchecker_profile_default.php');
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();
            self::assertSame("set=0\nget=0\nignore=0\ncall=undefined\n", $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function test_issuspicious_and_confusable_forced_registration(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            BuiltinClasses::registerSpoofchecker($runtime->vmContext);

            $code = <<<'PHP'
<?php
echo 'class=', (int) class_exists('Spoofchecker'), "\n";
$s = new Spoofchecker();
echo 'clean=', (int) $s->isSuspicious('paypal.com'), "\n";
// U+0430 CYRILLIC SMALL LETTER A in "pаypal.com"
$mixed = "p\xD0\xB0ypal.com";
echo 'mixed=', (int) $s->isSuspicious($mixed), "\n";
$bits = 0;
$s->isSuspicious($mixed, $bits);
echo 'bits=', $bits, "\n";
echo 'const_ss=', Spoofchecker::SINGLE_SCRIPT, "\n";
// Greek rho U+03C1 vs Latin p in lookalike pair
$c1 = 'paypal';
$c2 = "\xCF\x81aypal"; // ρaypal
echo 'conf=', (int) $s->areConfusable($c1, $c2), "\n";
        $s->setChecks(Spoofchecker::SINGLE_SCRIPT | Spoofchecker::INVISIBLE);
$s->setRestrictionLevel(Spoofchecker::MODERATELY_RESTRICTIVE);
echo 'ignore=', Spoofchecker::IGNORE_SPACE, "\n";
$s->setAllowedChars('[a-z0-9]');
echo 'allowed_clean=', (int) $s->isSuspicious('hello'), "\n";
echo 'allowed_accent=', (int) $s->isSuspicious("h\xC3\xA9llo"), "\n";
echo 'ok', "\n";
PHP;
            $block = $runtime->parseAndCompile($code, 'spoofchecker_basic.php');
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();
            self::assertMatchesRegularExpression(
                '/^class=1\nclean=0\nmixed=1\nbits=\d+\nconst_ss=16\nconf=1\nignore=1\nallowed_clean=0\nallowed_accent=1\nok\n$/',
                $out
            );
            self::assertSame(16, VmSpoofchecker::SINGLE_SCRIPT);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function test_set_restriction_level_rejects_invalid(): void
    {
        $runtime = new Runtime();
        BuiltinClasses::registerSpoofchecker($runtime->vmContext);

        $code = <<<'PHP'
<?php
$s = new Spoofchecker();
try {
    $s->setRestrictionLevel(123);
    echo "no_throw\n";
} catch (ValueError $e) {
    echo "value_error\n";
}
PHP;
        $block = $runtime->parseAndCompile($code, 'spoofchecker_level.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("value_error\n", $out);
    }

    public function test_set_allowed_chars_rejects_invalid_pattern(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            BuiltinClasses::registerSpoofchecker($runtime->vmContext);

            $code = <<<'PHP'
<?php
$s = new Spoofchecker();
try {
    $s->setAllowedChars('not-a-set');
    echo "no_throw\n";
} catch (ValueError $e) {
    echo "value_error\n";
}
try {
    $s->setAllowedChars('[a-z]', 99);
    echo "opts_no_throw\n";
} catch (ValueError $e) {
    echo "opts_value_error\n";
}
PHP;
            $block = $runtime->parseAndCompile($code, 'spoofchecker_allowed.php');
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();
            self::assertSame("value_error\nopts_value_error\n", $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
