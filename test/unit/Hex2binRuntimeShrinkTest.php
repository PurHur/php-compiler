<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Hex2binJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/**
 * hex2bin() JIT: Hex2binJitHelper via JitVmHelperLink::ensureCompiled (#22746 / #27008).
 * NestedJIT-self-contained string|false (no VmString / no static lastString).
 */
final class Hex2binRuntimeShrinkTest extends TestCase
{
    public function testStringHex2binUsesJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHex2bin.php');
        $this->assertStringContainsString('Hex2binJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringContainsString('isHelperResultNull', $source);
        $this->assertStringContainsString('extractStringPtrFromHelperResult', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitHex2bin.php');
        // __value__writeBool ABI is (__value__*, i32); i8 const fails module verify (#27008).
        $this->assertMatchesRegularExpression(
            '/__value__writeBool[\s\S]{0,120}\$i32->constInt\(0,\s*false\)/',
            $source
        );

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/hex2bin.php');
        $this->assertStringContainsString('StringHex2bin::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_hex2bin', $builtin);
        $this->assertStringNotContainsString('JitHex2bin', $builtin);

        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/Hex2binJitHelper.php');
        $this->assertStringNotContainsString('VmString::', $helper);
        $this->assertStringNotContainsString('$lastString', $helper);
        $this->assertStringContainsString('string|false', $helper);
    }

    public function testHex2binJitHelperMatchesVmString(): void
    {
        $this->assertSame('abc', Hex2binJitHelper::hex2binArgv('616263', false));
        $this->assertSame('abc', VmString::hex2bin('616263', false));
        $this->assertSame('', Hex2binJitHelper::hex2binArgv('', false));
        $this->assertFalse(Hex2binJitHelper::hex2binArgv('ghij', false));
        $this->assertFalse(Hex2binJitHelper::hex2binArgv('abc', false));
        $this->assertSame("\x0f\x0f", Hex2binJitHelper::hex2binArgv('0f0f', false));
    }

    public function testSpineBundleIncludesHex2binJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitHex2bin.php', $spine);
        $this->assertStringContainsString('Hex2binJitHelper.php', $spine);
        $this->assertStringContainsString('StringHex2bin.php', $spine);
    }
}
