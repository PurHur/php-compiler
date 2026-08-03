<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\NextafterJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * nextafter() NestedJIT leaf is PHP-emitted IEEE bitcast — no libc nextafter(3) (#27496).
 */
final class NextafterRuntimeShrinkTest extends TestCase
{
    public function testNextafterUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/nextafter.php');
        $this->assertStringContainsString('MathNextafter::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('nextafter')", $builtin);
        $this->assertStringNotContainsString('invokeLibc', $builtin);
    }

    public function testMathNextafterKeepsNestedJitBitcastLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathNextafter.php');
        $this->assertStringContainsString('JitNextafterKernel', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('NextafterJitHelper', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString("lookupFunction('nextafter')", $source);
    }

    public function testNextafterKernelIsBitcastNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitNextafterKernel.php');
        $this->assertStringContainsString('bitCast', $source);
        $this->assertStringContainsString('VmMath::nextafter', $source);
        $this->assertStringNotContainsString('LibcExtern', $source);
        $this->assertStringNotContainsString("lookupFunction('nextafter')", $source);
        $this->assertStringNotContainsString("lookupFunction(\"nextafter\")", $source);
    }

    public function testLibcExternNoLongerDeclaresNextafter(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString("'nextafter'", $source);
    }

    public function testNextafterJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/NextafterJitHelper.php');
        $this->assertStringContainsString('phpc_nextafter_kernel', $source);
        $this->assertStringNotContainsString('\\nextafter(', $source);
        $this->assertStringNotContainsString('return VmMath::nextafter', $source);

        if (!\function_exists('phpc_nextafter_kernel')) {
            $this->markTestSkipped('phpc_nextafter_kernel requires compiler runtime');
        }
        if (!\function_exists('nextafter')) {
            $this->markTestSkipped('host PHP lacks nextafter() (needs 8.4+)');
        }
        $this->assertSame(
            \nextafter(1.0, 2.0),
            NextafterJitHelper::nextafterArgv(1.0, 2.0)
        );
        $this->assertSame(
            \nextafter(1.0, 0.0),
            NextafterJitHelper::nextafterArgv(1.0, 0.0)
        );
    }

    public function testSpineBundleIncludesNextafterJitHelperAndKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('NextafterJitHelper.php', $spine);
        $this->assertStringContainsString('MathNextafter.php', $spine);
        $this->assertStringContainsString('JitNextafterKernel.php', $spine);
        $this->assertStringContainsString('phpc_nextafter_kernel.php', $spine);
    }
}
