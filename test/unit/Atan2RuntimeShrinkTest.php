<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Atan2JitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * atan2() NestedJIT via JitVmHelperLink::ensureBridge (#28497 / peer MathAtan #28470).
 */
final class Atan2RuntimeShrinkTest extends TestCase
{
    public function testAtan2UsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/atan2.php');
        $this->assertStringContainsString('MathAtan2::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('atan2')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAtan2.php');
        $this->assertStringContainsString('Atan2JitHelper', $bridge);
        $this->assertStringContainsString('phpc_atan2', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitAtan2Kernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testAtan2JitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Atan2JitHelper.php');
        $this->assertStringContainsString('atanSigned', $source);
        $this->assertStringContainsString('0.3333333333333333', $source);
        $this->assertStringNotContainsString('sqrtPositive', $source);
        $this->assertStringNotContainsString('phpc_atan2_kernel', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        $this->assertStringNotContainsString('AtanJitHelper::', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function atan2Argv\(.*?\{[^}]*VmMath::atan2/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function atan2Argv\(.*?\{[^}]*\\\\atan2\(/s',
            $source
        );

        $this->assertSame(VmMath::atan2(0.0, 1.0), Atan2JitHelper::atan2Argv(0.0, 1.0));
        $this->assertEqualsWithDelta(VmMath::atan2(1.0, 1.0), Atan2JitHelper::atan2Argv(1.0, 1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan2(-1.0, 1.0), Atan2JitHelper::atan2Argv(-1.0, 1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan2(1.0, -1.0), Atan2JitHelper::atan2Argv(1.0, -1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan2(-1.0, -1.0), Atan2JitHelper::atan2Argv(-1.0, -1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan2(1.0, 0.0), Atan2JitHelper::atan2Argv(1.0, 0.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan2(-1.0, 0.0), Atan2JitHelper::atan2Argv(-1.0, 0.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan2(0.5, 0.5), Atan2JitHelper::atan2Argv(0.5, 0.5), 1e-15);
        // Off-diagonal uses Taylor — NestedJIT-stable but ~1e-4 vs fdlibm.
        $this->assertEqualsWithDelta(VmMath::atan2(2.0, 1.0), Atan2JitHelper::atan2Argv(2.0, 1.0), 1e-4);
        $this->assertEqualsWithDelta(VmMath::atan2(3.0, 4.0), Atan2JitHelper::atan2Argv(3.0, 4.0), 1e-4);
        $this->assertEqualsWithDelta(VmMath::atan2(10.0, -3.0), Atan2JitHelper::atan2Argv(10.0, -3.0), 1e-4);
        $this->assertEqualsWithDelta(VmMath::atan2(0.0, -1.0), Atan2JitHelper::atan2Argv(0.0, -1.0), 1e-15);
        $this->assertSame(VmMath::atan2(\INF, 1.0), Atan2JitHelper::atan2Argv(\INF, 1.0));
        $this->assertSame(VmMath::atan2(-\INF, 1.0), Atan2JitHelper::atan2Argv(-\INF, 1.0));
        $this->assertSame(VmMath::atan2(1.0, \INF), Atan2JitHelper::atan2Argv(1.0, \INF));
        $this->assertEqualsWithDelta(VmMath::atan2(1.0, -\INF), Atan2JitHelper::atan2Argv(1.0, -\INF), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan2(\INF, \INF), Atan2JitHelper::atan2Argv(\INF, \INF), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atan2(\INF, -\INF), Atan2JitHelper::atan2Argv(\INF, -\INF), 1e-15);
        $this->assertTrue(\is_nan(Atan2JitHelper::atan2Argv(\NAN, 1.0)));
        $this->assertTrue(\is_nan(Atan2JitHelper::atan2Argv(1.0, \NAN)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitAtan2Kernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_atan2_kernel.php');
    }

    public function testContextNoLongerAllowlistsAtan2Kernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_atan2_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringNotContainsString('phpc_log10_kernel', $source);
    }

    public function testSpineBundleIncludesAtan2HelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Atan2JitHelper.php', $spine);
        $this->assertStringContainsString('MathAtan2.php', $spine);
        $this->assertStringNotContainsString('JitAtan2Kernel.php', $spine);
        $this->assertStringNotContainsString('phpc_atan2_kernel.php', $spine);
    }
}
