<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AtanJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * atan() NestedJIT via JitVmHelperLink::ensureBridge (#28470 / peer MathAsin #28263).
 */
final class AtanRuntimeShrinkTest extends TestCase
{
    public function testAtanUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/atan.php');
        $this->assertStringContainsString('MathAtan::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('atan')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAtan.php');
        $this->assertStringContainsString('AtanJitHelper', $bridge);
        $this->assertStringContainsString('phpc_atan', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitAtanKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testAtanJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AtanJitHelper.php');
        $this->assertStringContainsString('sqrtPositive', $source);
        $this->assertStringContainsString('3.33333333333329318027e-01', $source);
        $this->assertStringNotContainsString('phpc_atan_kernel', $source);
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
            '/function atanArgv\(.*?\{[^}]*VmMath::atan/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function atanArgv\(.*?\{[^}]*\\\\atan\(/s',
            $source
        );

        $this->assertSame(VmMath::atan(0.0), AtanJitHelper::atanArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::atan(1.0), AtanJitHelper::atanArgv(1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan(-1.0), AtanJitHelper::atanArgv(-1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan(0.5), AtanJitHelper::atanArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan(-0.5), AtanJitHelper::atanArgv(-0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan(0.1), AtanJitHelper::atanArgv(0.1), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan(0.9), AtanJitHelper::atanArgv(0.9), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan(2.0), AtanJitHelper::atanArgv(2.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan(-2.0), AtanJitHelper::atanArgv(-2.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan(10.0), AtanJitHelper::atanArgv(10.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan(-10.0), AtanJitHelper::atanArgv(-10.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan(1.5), AtanJitHelper::atanArgv(1.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan(0.6), AtanJitHelper::atanArgv(0.6), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan(100.0), AtanJitHelper::atanArgv(100.0), 1e-14);
        $this->assertEqualsWithDelta(
            VmMath::atan(\M_PI_4),
            AtanJitHelper::atanArgv(\M_PI_4),
            1e-15
        );
        $this->assertSame(VmMath::atan(\INF), AtanJitHelper::atanArgv(\INF));
        $this->assertSame(VmMath::atan(-\INF), AtanJitHelper::atanArgv(-\INF));
        $this->assertTrue(\is_nan(AtanJitHelper::atanArgv(\NAN)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitAtanKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_atan_kernel.php');
    }

    public function testContextNoLongerAllowlistsAtanKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_atan_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringNotContainsString('phpc_atan2_kernel', $source);
        $this->assertStringNotContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_nextafter_kernel', $source);
        $this->assertStringNotContainsString('phpc_log10_kernel', $source);
    }

    public function testSpineBundleIncludesAtanHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AtanJitHelper.php', $spine);
        $this->assertStringContainsString('MathAtan.php', $spine);
        $this->assertStringNotContainsString('JitAtanKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_atan_kernel.php', $spine);
    }
}
