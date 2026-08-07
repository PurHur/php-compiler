<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CosJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * cos() NestedJIT via JitVmHelperLink::ensureBridge (#28042 / peer MathSin #28016).
 */
final class CosRuntimeShrinkTest extends TestCase
{
    public function testCosUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/cos.php');
        $this->assertStringContainsString('MathCos::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('cos')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathCos.php');
        $this->assertStringContainsString('CosJitHelper', $bridge);
        $this->assertStringContainsString('phpc_cos', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitCosKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testCosJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CosJitHelper.php');
        $this->assertStringContainsString('3628800.0', $source);
        $this->assertStringContainsString('$twoPi', $source);
        $this->assertStringNotContainsString('phpc_cos_kernel', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        // Early-return if before Horner miscompiles under NestedJIT — keep straight-line body.
        $this->assertDoesNotMatchRegularExpression(
            '/function cosArgv\(.*?\{[^}]*\bif\s*\(/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function cosArgv\(.*?\{[^}]*VmMath::cos/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function cosArgv\(.*?\{[^}]*\\\\cos\(/s',
            $source
        );

        $this->assertSame(VmMath::cos(0.0), CosJitHelper::cosArgv(0.0));
        $this->assertSame(VmMath::cos(1.0), CosJitHelper::cosArgv(1.0));
        $this->assertEqualsWithDelta(VmMath::cos(\M_PI / 3.0), CosJitHelper::cosArgv(\M_PI / 3.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::cos(0.5), CosJitHelper::cosArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::cos(2.0), CosJitHelper::cosArgv(2.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::cos(100.0), CosJitHelper::cosArgv(100.0), 1e-12);
        $this->assertTrue(\is_nan(CosJitHelper::cosArgv(\INF)));
        $this->assertTrue(\is_nan(CosJitHelper::cosArgv(\NAN)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitCosKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_cos_kernel.php');
    }

    public function testContextNoLongerAllowlistsCosKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_cos_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_log10_kernel', $source);
        $this->assertStringContainsString('phpc_nextafter_kernel', $source);
    }

    public function testSpineBundleIncludesCosHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CosJitHelper.php', $spine);
        $this->assertStringContainsString('MathCos.php', $spine);
        $this->assertStringNotContainsString('JitCosKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_cos_kernel.php', $spine);
    }
}
