<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AcosJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * acos() NestedJIT via JitVmHelperLink::ensureBridge (#28276 / peer MathAsin #28263).
 */
final class AcosRuntimeShrinkTest extends TestCase
{
    public function testAcosUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/acos.php');
        $this->assertStringContainsString('MathAcos::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('acos')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAcos.php');
        $this->assertStringContainsString('AcosJitHelper', $bridge);
        $this->assertStringContainsString('phpc_acos', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitAcosKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testAcosJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AcosJitHelper.php');
        $this->assertStringContainsString('asinCore', $source);
        $this->assertStringContainsString('asinPoly', $source);
        $this->assertStringContainsString('sqrtPositive', $source);
        $this->assertStringContainsString('1.66666666666666657415e-01', $source);
        $this->assertStringNotContainsString('phpc_acos_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!@see )AsinJitHelper::/',
            $source
        );
        $this->assertStringNotContainsString('\\AsinJitHelper', $source);
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
            '/function acosArgv\(.*?\{[^}]*VmMath::acos/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function acosArgv\(.*?\{[^}]*\\\\acos\(/s',
            $source
        );

        $this->assertSame(VmMath::acos(0.0), AcosJitHelper::acosArgv(0.0));
        $this->assertSame(VmMath::acos(1.0), AcosJitHelper::acosArgv(1.0));
        $this->assertSame(VmMath::acos(-1.0), AcosJitHelper::acosArgv(-1.0));
        $this->assertEqualsWithDelta(VmMath::acos(0.5), AcosJitHelper::acosArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::acos(-0.5), AcosJitHelper::acosArgv(-0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::acos(0.1), AcosJitHelper::acosArgv(0.1), 1e-15);
        $this->assertEqualsWithDelta(VmMath::acos(0.9), AcosJitHelper::acosArgv(0.9), 1e-15);
        $this->assertEqualsWithDelta(VmMath::acos(0.999), AcosJitHelper::acosArgv(0.999), 1e-14);
        $this->assertTrue(\is_nan(AcosJitHelper::acosArgv(\INF)));
        $this->assertTrue(\is_nan(AcosJitHelper::acosArgv(-\INF)));
        $this->assertTrue(\is_nan(AcosJitHelper::acosArgv(\NAN)));
        $this->assertTrue(\is_nan(AcosJitHelper::acosArgv(1.1)));
        $this->assertTrue(\is_nan(AcosJitHelper::acosArgv(-1.1)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitAcosKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_acos_kernel.php');
    }

    public function testContextNoLongerAllowlistsAcosKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_acos_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_atan2_kernel', $source);
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_expm1_kernel', $source);
    }

    public function testSpineBundleIncludesAcosHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AcosJitHelper.php', $spine);
        $this->assertStringContainsString('MathAcos.php', $spine);
        $this->assertStringNotContainsString('JitAcosKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_acos_kernel.php', $spine);
    }
}
