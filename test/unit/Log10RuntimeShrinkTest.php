<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Log10JitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** log10() JIT: always Log10JitHelper via JitVmHelperLink + phpc_log10_kernel (#15101, #27047). */
final class Log10RuntimeShrinkTest extends TestCase
{
    public function testLog10UsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/log10.php');
        $this->assertStringContainsString('MathLog10::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('log10')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathLog10.php');
        $this->assertStringContainsString('Log10JitHelper', $bridge);
        $this->assertStringContainsString('phpc_log10', $bridge);
        $this->assertStringContainsString('JitLog10Kernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testLog10JitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Log10JitHelper.php');
        $this->assertStringContainsString('phpc_log10_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function log10Argv\(.*?\{[^}]*phpc_log10_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function log10Argv\(.*?\{[^}]*VmMath::log10/s',
            $source
        );

        if (!\function_exists('phpc_log10_kernel')) {
            $this->markTestSkipped('phpc_log10_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::log10(100.0),
            Log10JitHelper::log10Argv(100.0)
        );
        $this->assertSame(
            VmMath::log10(1.0),
            Log10JitHelper::log10Argv(1.0)
        );
    }

    public function testContextAllowlistsLog10KernelForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_log10_kernel', $source);
        $this->assertStringContainsString('phpc_hypot_kernel', $source);
    }

    public function testSpineBundleIncludesLog10JitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Log10JitHelper.php', $spine);
        $this->assertStringContainsString('MathLog10.php', $spine);
        $this->assertStringContainsString('JitLog10Kernel.php', $spine);
        $this->assertStringContainsString('phpc_log10_kernel.php', $spine);
    }
}
