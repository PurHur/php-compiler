<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SinJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * sin() NestedJIT via JitVmHelperLink::ensureBridge (#28016 / peer MathHypot #27909).
 */
final class SinRuntimeShrinkTest extends TestCase
{
    public function testSinUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/sin.php');
        $this->assertStringContainsString('MathSin::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('sin')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathSin.php');
        $this->assertStringContainsString('SinJitHelper', $bridge);
        $this->assertStringContainsString('phpc_sin', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitSinKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testSinJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SinJitHelper.php');
        $this->assertStringContainsString('39916800.0', $source);
        $this->assertStringContainsString('$twoPi', $source);
        $this->assertStringNotContainsString('phpc_sin_kernel', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        // Early-return if before Horner miscompiles under NestedJIT — keep straight-line body.
        $this->assertDoesNotMatchRegularExpression(
            '/function sinArgv\(.*?\{[^}]*\bif\s*\(/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function sinArgv\(.*?\{[^}]*VmMath::sin/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function sinArgv\(.*?\{[^}]*\\\\sin\(/s',
            $source
        );

        $this->assertSame(VmMath::sin(0.0), SinJitHelper::sinArgv(0.0));
        $this->assertSame(VmMath::sin(1.0), SinJitHelper::sinArgv(1.0));
        $this->assertSame(VmMath::sin(\M_PI / 2.0), SinJitHelper::sinArgv(\M_PI / 2.0));
        $this->assertEqualsWithDelta(VmMath::sin(0.5), SinJitHelper::sinArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::sin(2.0), SinJitHelper::sinArgv(2.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::sin(100.0), SinJitHelper::sinArgv(100.0), 1e-12);
        $this->assertTrue(\is_nan(SinJitHelper::sinArgv(\INF)));
        $this->assertTrue(\is_nan(SinJitHelper::sinArgv(\NAN)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitSinKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_sin_kernel.php');
    }

    public function testContextNoLongerAllowlistsSinKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_sin_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_expm1_kernel', $source);
        $this->assertStringContainsString('phpc_nextafter_kernel', $source);
    }

    public function testSpineBundleIncludesSinHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SinJitHelper.php', $spine);
        $this->assertStringContainsString('MathSin.php', $spine);
        $this->assertStringNotContainsString('JitSinKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_sin_kernel.php', $spine);
    }
}
