<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SqrtJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * sqrt() NestedJIT via JitVmHelperLink::ensureBridge (#27888 / peer fmod #27838).
 */
final class SqrtRuntimeShrinkTest extends TestCase
{
    public function testSqrtUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/sqrt.php');
        $this->assertStringContainsString('MathSqrt::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('sqrt')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathSqrt.php');
        $this->assertStringContainsString('SqrtJitHelper', $bridge);
        $this->assertStringContainsString('phpc_sqrt', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitSqrtKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testSqrtJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SqrtJitHelper.php');
        $this->assertStringContainsString('0.5 * ($y + $x / $y)', $source);
        $this->assertStringNotContainsString('phpc_sqrt_kernel', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function sqrtArgv\(.*?\{[^}]*VmMath::sqrt/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function sqrtArgv\(.*?\{[^}]*\\\\sqrt\(/s',
            $source
        );

        $this->assertSame(VmMath::sqrt(9.0), SqrtJitHelper::sqrtArgv(9.0));
        $this->assertSame(VmMath::sqrt(4.0), SqrtJitHelper::sqrtArgv(4.0));
        $this->assertSame(VmMath::sqrt(0.25), SqrtJitHelper::sqrtArgv(0.25));
        $this->assertEqualsWithDelta(VmMath::sqrt(2.0), SqrtJitHelper::sqrtArgv(2.0), 1e-15);
        $this->assertSame(
            \unpack('P', \pack('d', VmMath::sqrt(-0.0)))[1],
            \unpack('P', \pack('d', SqrtJitHelper::sqrtArgv(-0.0)))[1]
        );
        $this->assertTrue(\is_nan(SqrtJitHelper::sqrtArgv(-1.0)));
        $this->assertSame(\INF, SqrtJitHelper::sqrtArgv(\INF));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitSqrtKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_sqrt_kernel.php');
    }

    public function testContextNoLongerAllowlistsSqrtKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_sqrt_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_exp_kernel', $source);
    }

    public function testSpineBundleIncludesSqrtHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SqrtJitHelper.php', $spine);
        $this->assertStringContainsString('MathSqrt.php', $spine);
        $this->assertStringNotContainsString('JitSqrtKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_sqrt_kernel.php', $spine);
    }
}
