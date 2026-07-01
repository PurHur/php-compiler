<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Hex2binJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** hex2bin() JIT routes through Hex2binJitHelper PHP not inline LLVM (#14627). */
final class Hex2binRuntimeShrinkTest extends TestCase
{
    public function testStringHex2binUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringHex2bin.php');
        $this->assertStringContainsString('Hex2binJitHelper', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitHex2bin.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/hex2bin.php');
        $this->assertStringContainsString('StringHex2bin::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_hex2bin', $builtin);
        $this->assertStringNotContainsString('JitHex2bin', $builtin);
    }

    public function testHex2binJitHelperDelegatesToVmString(): void
    {
        $this->assertSame(Hex2binJitHelper::TAG_STRING, Hex2binJitHelper::hex2binArgv('616263', false));
        $this->assertSame('abc', Hex2binJitHelper::lastString());
        $this->assertSame('abc', VmString::hex2bin('616263', false));
    }

    public function testHex2binJitHelperFalseOnInvalidHex(): void
    {
        $this->assertSame(Hex2binJitHelper::TAG_FALSE, Hex2binJitHelper::hex2binArgv('ghij', false));
    }

    public function testSpineBundleIncludesHex2binJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitHex2bin.php', $spine);
        $this->assertStringContainsString('Hex2binJitHelper.php', $spine);
        $this->assertStringContainsString('StringHex2bin.php', $spine);
    }
}
