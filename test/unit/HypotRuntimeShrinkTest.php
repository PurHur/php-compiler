<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HypotJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** hypot() JIT: always HypotJitHelper via JitVmHelperLink + phpc_hypot_kernel (#15074, #20664). */
final class HypotRuntimeShrinkTest extends TestCase
{
    public function testHypotUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/hypot.php');
        $this->assertStringContainsString('MathHypot::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('hypot')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathHypot.php');
        $this->assertStringContainsString('HypotJitHelper', $bridge);
        $this->assertStringContainsString('phpc_hypot', $bridge);
        $this->assertStringContainsString('JitHypotKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testHypotJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HypotJitHelper.php');
        $this->assertStringContainsString('phpc_hypot_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function hypotArgv\(.*?\{[^}]*phpc_hypot_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function hypotArgv\(.*?\{[^}]*VmMath::hypot/s',
            $source
        );

        if (!\function_exists('phpc_hypot_kernel')) {
            $this->markTestSkipped('phpc_hypot_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::hypot(3.0, 4.0),
            HypotJitHelper::hypotArgv(3.0, 4.0)
        );
        $this->assertSame(
            VmMath::hypot(0.0, 5.0),
            HypotJitHelper::hypotArgv(0.0, 5.0)
        );
    }

    public function testContextAllowlistsHypotKernelForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_hypot_kernel', $source);
        $this->assertStringContainsString('phpc_sin_kernel', $source);
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_nextafter_kernel', $source);
    }

    public function testSpineBundleIncludesHypotJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HypotJitHelper.php', $spine);
        $this->assertStringContainsString('MathHypot.php', $spine);
        $this->assertStringContainsString('JitHypotKernel.php', $spine);
        $this->assertStringContainsString('phpc_hypot_kernel.php', $spine);
    }
}
