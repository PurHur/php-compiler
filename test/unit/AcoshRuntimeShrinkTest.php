<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AcoshJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * acosh() NestedJIT via JitVmHelperLink::ensureBridge (#28331 / peer MathAcos #28276).
 */
final class AcoshRuntimeShrinkTest extends TestCase
{
    public function testAcoshUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/acosh.php');
        $this->assertStringContainsString('MathAcosh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('acosh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAcosh.php');
        $this->assertStringContainsString('AcoshJitHelper', $bridge);
        $this->assertStringContainsString('phpc_acosh', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitAcoshKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testAcoshJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AcoshJitHelper.php');
        $this->assertStringContainsString('logPositive', $source);
        $this->assertStringContainsString('sqrtPositive', $source);
        $this->assertStringContainsString('0.693147180559945309417', $source);
        $this->assertStringNotContainsString('phpc_acosh_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!@see )LogJitHelper::/',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!@see )SqrtJitHelper::/',
            $source
        );
        $this->assertStringNotContainsString('\\LogJitHelper', $source);
        $this->assertStringNotContainsString('\\SqrtJitHelper', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function acoshArgv\(.*?\{[^}]*VmMath::acosh/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function acoshArgv\(.*?\{[^}]*\\\\acosh\(/s',
            $source
        );

        $this->assertSame(VmMath::acosh(1.0), AcoshJitHelper::acoshArgv(1.0));
        $this->assertEqualsWithDelta(VmMath::acosh(2.0), AcoshJitHelper::acoshArgv(2.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::acosh(1.1), AcoshJitHelper::acoshArgv(1.1), 1e-15);
        $this->assertEqualsWithDelta(VmMath::acosh(1.5), AcoshJitHelper::acoshArgv(1.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::acosh(2.5), AcoshJitHelper::acoshArgv(2.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::acosh(10.0), AcoshJitHelper::acoshArgv(10.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::acosh(100.0), AcoshJitHelper::acoshArgv(100.0), 1e-14);
        $this->assertEqualsWithDelta(VmMath::acosh(1.0e6), AcoshJitHelper::acoshArgv(1.0e6), 1e-12);
        $this->assertEqualsWithDelta(VmMath::acosh(1.0e20), AcoshJitHelper::acoshArgv(1.0e20), 1e-10);
        $this->assertSame(\INF, AcoshJitHelper::acoshArgv(\INF));
        $this->assertTrue(\is_nan(AcoshJitHelper::acoshArgv(\NAN)));
        $this->assertTrue(\is_nan(AcoshJitHelper::acoshArgv(0.5)));
        $this->assertTrue(\is_nan(AcoshJitHelper::acoshArgv(0.0)));
        $this->assertTrue(\is_nan(AcoshJitHelper::acoshArgv(-1.0)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitAcoshKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_acosh_kernel.php');
    }

    public function testContextNoLongerAllowlistsAcoshKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_acosh_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringNotContainsString('phpc_asinh_kernel', $source);
        $this->assertStringNotContainsString('phpc_atanh_kernel', $source);
        $this->assertStringNotContainsString('phpc_atan2_kernel', $source);
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_log10_kernel', $source);
    }

    public function testSpineBundleIncludesAcoshHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AcoshJitHelper.php', $spine);
        $this->assertStringContainsString('MathAcosh.php', $spine);
        $this->assertStringNotContainsString('JitAcoshKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_acosh_kernel.php', $spine);
    }
}
