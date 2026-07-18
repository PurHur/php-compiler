<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Bin2hexJitHelper;
use PHPCompiler\ext\standard\VmString;
use PHPUnit\Framework\TestCase;

/** bin2hex() JIT routes through Bin2hexJitHelper PHP for embed + user-script AOT (#14603, #20452). */
final class Bin2hexRuntimeShrinkTest extends TestCase
{
    public function testStringBin2hexUsesJitHelperNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringBin2hex.php');
        $this->assertStringContainsString('Bin2hexJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('JitBin2hexKernel', $source);
        $this->assertStringNotContainsString('bin2hex_kernel_entry', $source);
        $this->assertStringNotContainsString('implementInlineLlvm', $source);
        $this->assertStringNotContainsString('StringBin2hexLlvm', $source);
        $this->assertStringNotContainsString("constantFromString('0123456789abcdef')", $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringBin2hexLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitBin2hex.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitBin2hexKernel.php');

        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/bin2hex.php');
        $this->assertStringContainsString('StringBin2hex::ensureLinked', $builtin);
        $this->assertStringContainsString('__compiler_bin2hex', $builtin);
        $this->assertStringContainsString('lowerZparamStr', $builtin);
        $this->assertStringContainsString('lowerStrictOrCoercible', $builtin);
        $this->assertStringNotContainsString('JitBin2hex', $builtin);
    }

    public function testBin2hexJitHelperIsSelfContained(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Bin2hexJitHelper.php');
        $this->assertStringContainsString('byteOrd', $source);
        $this->assertStringNotContainsString('VmString::bin2hex(', $source);
        $this->assertSame('616263', Bin2hexJitHelper::bin2hexArgv('abc'));
        $this->assertSame('000fff', Bin2hexJitHelper::bin2hexArgv("\x00\x0f\xff"));
        $this->assertSame('', Bin2hexJitHelper::bin2hexArgv(''));
        // Host Zend path still matches SSOT.
        $this->assertSame('616263', VmString::bin2hex('abc'));
    }

    public function testSpineBundleIncludesBin2hexPhpPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringNotContainsString('JitBin2hex.php', $spine);
        $this->assertStringNotContainsString('JitBin2hexKernel.php', $spine);
        $this->assertStringNotContainsString('StringBin2hexLlvm.php', $spine);
        $this->assertStringContainsString('Bin2hexJitHelper.php', $spine);
        $this->assertStringContainsString('StringBin2hex.php', $spine);
    }
}
