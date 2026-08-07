<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ExpJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * exp() NestedJIT via JitVmHelperLink::ensureBridge (#28241 / peer MathTan #28226).
 */
final class ExpRuntimeShrinkTest extends TestCase
{
    public function testExpUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/exp.php');
        $this->assertStringContainsString('MathExp::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('exp')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathExp.php');
        $this->assertStringContainsString('ExpJitHelper', $bridge);
        $this->assertStringContainsString('phpc_exp', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitExpKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testExpJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ExpJitHelper.php');
        $this->assertStringContainsString('0.693147180559945309417', $source);
        $this->assertStringContainsString('1.44269504088896340736', $source);
        $this->assertStringContainsString('/ 20.0', $source);
        $this->assertStringNotContainsString('phpc_exp_kernel', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function expArgv\(.*?\{[^}]*VmMath::exp/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function expArgv\(.*?\{[^}]*\\\\exp\(/s',
            $source
        );

        $this->assertSame(VmMath::exp(0.0), ExpJitHelper::expArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::exp(1.0), ExpJitHelper::expArgv(1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::exp(-1.0), ExpJitHelper::expArgv(-1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::exp(0.5), ExpJitHelper::expArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::exp(2.0), ExpJitHelper::expArgv(2.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::exp(10.0), ExpJitHelper::expArgv(10.0), 1e-10);
        $this->assertEqualsWithDelta(VmMath::exp(-10.0), ExpJitHelper::expArgv(-10.0), 1e-15);
        // Host PHP Inf/NaN paths (NestedJIT ±Inf/NaN compares are a known separate defect class).
        $this->assertTrue(\is_infinite(ExpJitHelper::expArgv(\INF)));
        $this->assertSame(0.0, ExpJitHelper::expArgv(-\INF));
        $this->assertTrue(\is_nan(ExpJitHelper::expArgv(\NAN)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitExpKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_exp_kernel.php');
    }

    public function testContextNoLongerAllowlistsExpKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_exp_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_nextafter_kernel', $source);
        $this->assertStringContainsString('phpc_log10_kernel', $source);
    }

    public function testSpineBundleIncludesExpHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ExpJitHelper.php', $spine);
        $this->assertStringContainsString('MathExp.php', $spine);
        $this->assertStringNotContainsString('JitExpKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_exp_kernel.php', $spine);
    }
}
