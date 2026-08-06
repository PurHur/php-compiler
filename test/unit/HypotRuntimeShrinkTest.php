<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\HypotJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * hypot() NestedJIT via JitVmHelperLink::ensureBridge (#27909 / peer MathSqrt #27888).
 */
final class HypotRuntimeShrinkTest extends TestCase
{
    public function testHypotUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/hypot.php');
        $this->assertStringContainsString('MathHypot::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('hypot')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathHypot.php');
        $this->assertStringContainsString('HypotJitHelper', $bridge);
        $this->assertStringContainsString('phpc_hypot', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitHypotKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testHypotJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/HypotJitHelper.php');
        $this->assertStringContainsString('0.5 * ($y + $x / $y)', $source);
        $this->assertStringContainsString('sqrtPositive', $source);
        $this->assertStringNotContainsString('SqrtJitHelper::', $source);
        $this->assertStringNotContainsString('phpc_hypot_kernel', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function hypotArgv\(.*?\{[^}]*VmMath::hypot/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function hypotArgv\(.*?\{[^}]*\\\\hypot\(/s',
            $source
        );

        $this->assertSame(VmMath::hypot(3.0, 4.0), HypotJitHelper::hypotArgv(3.0, 4.0));
        $this->assertSame(VmMath::hypot(0.0, 5.0), HypotJitHelper::hypotArgv(0.0, 5.0));
        $this->assertSame(VmMath::hypot(0.0, 0.0), HypotJitHelper::hypotArgv(0.0, 0.0));
        $large = VmMath::hypot(1.0e200, 1.0e200);
        $this->assertEqualsWithDelta(
            $large,
            HypotJitHelper::hypotArgv(1.0e200, 1.0e200),
            1.0e-12 * $large
        );
        $this->assertSame(\INF, HypotJitHelper::hypotArgv(\INF, \NAN));
        $this->assertTrue(\is_nan(HypotJitHelper::hypotArgv(\NAN, 1.0)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitHypotKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_hypot_kernel.php');
    }

    public function testContextNoLongerAllowlistsHypotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_hypot_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_exp_kernel', $source);
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_nextafter_kernel', $source);
    }

    public function testSpineBundleIncludesHypotHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('HypotJitHelper.php', $spine);
        $this->assertStringContainsString('MathHypot.php', $spine);
        $this->assertStringNotContainsString('JitHypotKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_hypot_kernel.php', $spine);
    }
}
