<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\intl\BuiltinClasses;
use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\ext\intl\VmIntlChar;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/** @group intl_oop */
final class VmIntlCharUConverterTest extends TestCase
{
    public function test_withheld_without_intl(): void
    {
        if (IntlExtensionPolicy::advertisesBuiltins()) {
            self::markTestSkipped('IntlChar/UConverter advertise with host php-intl (#22691)');
        }
        $runtime = new Runtime();
        self::assertFalse(IntlExtensionPolicy::advertisesBuiltins());
        self::assertFalse(VmReflection::classExists($runtime->vmContext, 'IntlChar'));
        self::assertFalse(VmReflection::classExists($runtime->vmContext, 'UConverter'));
        self::assertFalse(VmReflection::classExists($runtime->vmContext, 'Spoofchecker'));
    }

    public function test_intlchar_ord_chr_forced_registration(): void
    {
        $runtime = new Runtime();
        BuiltinClasses::registerIntlChar($runtime->vmContext);

        $code = <<<'PHP'
<?php
echo IntlChar::ord('A'), "\n";
echo IntlChar::chr(65), "\n";
echo IntlChar::ord("\xC3\xA9"), "\n";
echo bin2hex(IntlChar::chr(233)), "\n";
echo var_export(IntlChar::ord('AB'), true), "\n";
echo var_export(IntlChar::chr(0x110000), true), "\n";
echo IntlChar::PROPERTY_WHITE_SPACE, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intlchar_ord_chr.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("65\nA\n233\nc3a9\nNULL\nNULL\n31\n", $out);
    }

    public function test_uconverter_roundtrip_forced_registration(): void
    {
        $runtime = new Runtime();
        BuiltinClasses::registerUConverter($runtime->vmContext);

        $code = <<<'PHP'
<?php
$u = new UConverter('ISO-8859-1', 'UTF-8');
$latin1 = $u->convert("\xC3\xA9");
echo bin2hex($latin1), "\n";
echo $u->getErrorCode(), "\n";
echo bin2hex($u->convert($latin1, true)), "\n";
$bad = new UConverter('not-a-real-encoding', 'UTF-8');
echo $bad->getErrorCode(), "\n";
var_export($bad->convert('abc'));
echo "\n";
echo $bad->getErrorCode(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'uconverter_convert.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("e9\n0\nc3a9\n4\nfalse\n27\n", $out);
    }

    /** @see https://github.com/PurHur/php-compiler/issues/20770 */
    public function test_uconverter_encodings_subst_reason_text(): void
    {
        $runtime = new Runtime();
        BuiltinClasses::registerUConverter($runtime->vmContext);

        $code = <<<'PHP'
<?php
$c = new UConverter('UTF-8', 'ISO-8859-1');
echo $c->getSourceEncoding(), "\n";
echo $c->getDestinationEncoding(), "\n";
echo bin2hex($c->getSubstChars()), "\n";
var_export($c->setSubstChars('?'));
echo "\n";
echo $c->getSubstChars(), "\n";
echo UConverter::reasonText(UConverter::REASON_ILLEGAL), "\n";
echo UConverter::reasonText(UConverter::REASON_CLONE), "\n";
$bad = new UConverter('not-a-real-encoding', 'UTF-8');
var_export($bad->getDestinationEncoding());
echo "\n";
var_export($bad->getSourceEncoding());
echo "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'uconverter_encodings.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        self::assertSame("ISO-8859-1\nUTF-8\n1a\ntrue\n?\nREASON_ILLEGAL\nREASON_CLONE\nNULL\n'UTF-8'\n", $out);
    }

    public function test_vm_helpers_match_zend_scalars(): void
    {
        self::assertSame(65, VmIntlChar::ord('A'));
        self::assertSame('A', VmIntlChar::chr(65));
        self::assertSame(233, VmIntlChar::ord("\xC3\xA9"));
        self::assertSame("\xC3\xA9", VmIntlChar::chr(233));
        self::assertNull(VmIntlChar::ord('AB'));
        self::assertNull(VmIntlChar::chr(0x110000));
    }
}
