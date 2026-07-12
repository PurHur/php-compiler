<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Bin2hexJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** bin2hex() user-script AOT defers nested JIT; full builds use Bin2hexJitHelper (#14603, #16734). */
final class Bin2hexRuntimeShrinkTest extends TestCase
{
    public function testStringBin2hexUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBin2hex.php');
        $this->assertStringContainsString('Bin2hexJitHelper', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitBin2hex.php');
        $this->assertStringContainsString('StringBin2hexLlvm', $source);
        $this->assertStringContainsString('shouldDefer', $source);

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/bin2hex.php');
        $this->assertStringContainsString('StringBin2hex::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_bin2hex', $builtin);
        $this->assertStringContainsString('coerceTypedStringBuiltinArg', $builtin);
        $this->assertStringContainsString('lowerTypedString', $builtin);
    }

    public function testBin2hexJitHelperDelegatesToVmString(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Bin2hexJitHelper.php');
        $this->assertStringContainsString('VmString::bin2hex', $source);

        $this->assertSame('616263', Bin2hexJitHelper::bin2hexArgv('abc'));
        $this->assertSame('616263', VmString::bin2hex('abc'));
    }

    public function testSpineBundleIncludesBin2hexJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitBin2hex.php', $spine);
        $this->assertStringContainsString('Bin2hexJitHelper.php', $spine);
        $this->assertStringContainsString('StringBin2hex.php', $spine);
    }
}
