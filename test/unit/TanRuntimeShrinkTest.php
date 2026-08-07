<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\TanJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * tan() NestedJIT via JitVmHelperLink::ensureBridge (#28226 / peer MathCos #28042).
 */
final class TanRuntimeShrinkTest extends TestCase
{
    public function testTanUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/tan.php');
        $this->assertStringContainsString('MathTan::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('tan')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathTan.php');
        $this->assertStringContainsString('TanJitHelper', $bridge);
        $this->assertStringContainsString('phpc_tan', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitTanKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testTanJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/TanJitHelper.php');
        $this->assertStringContainsString('39916800.0', $source);
        $this->assertStringContainsString('3628800.0', $source);
        $this->assertStringContainsString('$twoPi', $source);
        $this->assertStringNotContainsString('phpc_tan_kernel', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        // Early-return if before Horner miscompiles under NestedJIT — keep straight-line body.
        $this->assertDoesNotMatchRegularExpression(
            '/function tanArgv\(.*?\{[^}]*\bif\s*\(/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function tanArgv\(.*?\{[^}]*VmMath::tan/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function tanArgv\(.*?\{[^}]*\\\\tan\(/s',
            $source
        );

        $this->assertSame(VmMath::tan(0.0), TanJitHelper::tanArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::tan(1.0), TanJitHelper::tanArgv(1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::tan(\M_PI / 4.0), TanJitHelper::tanArgv(\M_PI / 4.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::tan(0.5), TanJitHelper::tanArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::tan(2.0), TanJitHelper::tanArgv(2.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::tan(100.0), TanJitHelper::tanArgv(100.0), 1e-12);
        $this->assertTrue(\is_nan(TanJitHelper::tanArgv(\INF)));
        $this->assertTrue(\is_nan(TanJitHelper::tanArgv(\NAN)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitTanKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_tan_kernel.php');
    }

    public function testContextNoLongerAllowlistsTanKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_tan_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringNotContainsString('phpc_log10_kernel', $source);
        $this->assertStringContainsString('phpc_nextafter_kernel', $source);
    }

    public function testSpineBundleIncludesTanHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('TanJitHelper.php', $spine);
        $this->assertStringContainsString('MathTan.php', $spine);
        $this->assertStringNotContainsString('JitTanKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_tan_kernel.php', $spine);
    }
}
