<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\NextafterJitHelper;
use PHPUnit\Framework\TestCase;

/** nextafter() JIT: PHP helper for embed; thin libc via isThinStandaloneAotMain (#15062, #20034). */
final class NextafterRuntimeShrinkTest extends TestCase
{
    public function testNextafterUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/nextafter.php');
        $this->assertStringContainsString('MathNextafter::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('nextafter')", $builtin);
        $this->assertStringNotContainsString('invokeLibc', $builtin);
    }

    public function testMathNextafterThinKernelAndEmbedHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathNextafter.php');
        $this->assertStringContainsString('JitNextafterKernel', $source);
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('NextafterJitHelper', $source);
        $this->assertStringContainsString('nextafter_kernel_entry', $source);
        $this->assertStringNotContainsString('invokeLibcNextafter', $source);
        $this->assertStringNotContainsString("lookupFunction('nextafter')", $source);
        $this->assertStringNotContainsString("addFunction('nextafter'", $source);
        $this->assertStringNotContainsString('addFunction($abiName', $source);
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
