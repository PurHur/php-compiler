<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AsinJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * asin() NestedJIT via JitVmHelperLink::ensureBridge (#28263 / peer MathSin #28016).
 */
final class AsinRuntimeShrinkTest extends TestCase
{
    public function testAsinUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/asin.php');
        $this->assertStringContainsString('MathAsin::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('asin')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAsin.php');
        $this->assertStringContainsString('AsinJitHelper', $bridge);
        $this->assertStringContainsString('phpc_asin', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitAsinKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testAsinJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AsinJitHelper.php');
        $this->assertStringContainsString('asinPoly', $source);
        $this->assertStringContainsString('sqrtPositive', $source);
        $this->assertStringContainsString('1.66666666666666657415e-01', $source);
        $this->assertStringNotContainsString('phpc_asin_kernel', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        // Ternary abs zeros under helper-runtime unit.o NestedJIT (#28263).
        $this->assertDoesNotMatchRegularExpression(
            '/\$ax\s*=\s*\$num\s*<\s*0\.0\s*\?/',
            $source
        );
        $this->assertStringContainsString('sqrtPositive($num * $num)', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function asinArgv\(.*?\{[^}]*VmMath::asin/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function asinArgv\(.*?\{[^}]*\\\\asin\(/s',
            $source
        );

        $this->assertSame(VmMath::asin(0.0), AsinJitHelper::asinArgv(0.0));
        $this->assertSame(VmMath::asin(1.0), AsinJitHelper::asinArgv(1.0));
        $this->assertSame(VmMath::asin(-1.0), AsinJitHelper::asinArgv(-1.0));
        $this->assertEqualsWithDelta(VmMath::asin(0.5), AsinJitHelper::asinArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::asin(-0.5), AsinJitHelper::asinArgv(-0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::asin(0.1), AsinJitHelper::asinArgv(0.1), 1e-15);
        $this->assertEqualsWithDelta(VmMath::asin(0.9), AsinJitHelper::asinArgv(0.9), 1e-15);
        $this->assertEqualsWithDelta(VmMath::asin(0.999), AsinJitHelper::asinArgv(0.999), 1e-14);
        $this->assertTrue(\is_nan(AsinJitHelper::asinArgv(\INF)));
        $this->assertTrue(\is_nan(AsinJitHelper::asinArgv(-\INF)));
        $this->assertTrue(\is_nan(AsinJitHelper::asinArgv(\NAN)));
        $this->assertTrue(\is_nan(AsinJitHelper::asinArgv(1.1)));
        $this->assertTrue(\is_nan(AsinJitHelper::asinArgv(-1.1)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitAsinKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_asin_kernel.php');
    }

    public function testContextNoLongerAllowlistsAsinKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_asin_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringNotContainsString('phpc_atan2_kernel', $source);
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_log_kernel', $source);
    }

    public function testSpineBundleIncludesAsinHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AsinJitHelper.php', $spine);
        $this->assertStringContainsString('MathAsin.php', $spine);
        $this->assertStringNotContainsString('JitAsinKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_asin_kernel.php', $spine);
    }
}
