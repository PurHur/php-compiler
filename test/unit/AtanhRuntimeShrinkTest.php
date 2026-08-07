<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AtanhJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * atanh() NestedJIT via JitVmHelperLink::ensureBridge (#28377 / peer MathAsinh #28355).
 */
final class AtanhRuntimeShrinkTest extends TestCase
{
    public function testAtanhUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/atanh.php');
        $this->assertStringContainsString('MathAtanh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('atanh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAtanh.php');
        $this->assertStringContainsString('AtanhJitHelper', $bridge);
        $this->assertStringContainsString('phpc_atanh', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitAtanhKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testAtanhJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AtanhJitHelper.php');
        $this->assertStringContainsString('logPositive', $source);
        $this->assertStringContainsString('sqrtPositive', $source);
        $this->assertStringContainsString('0.693147180559945309417', $source);
        $this->assertStringNotContainsString('phpc_atanh_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!@see )LogJitHelper::/',
            $source
        );
        $this->assertStringNotContainsString('\\LogJitHelper', $source);
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
            '/function atanhArgv\(.*?\{[^}]*VmMath::atanh/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function atanhArgv\(.*?\{[^}]*\\\\atanh\(/s',
            $source
        );

        $this->assertSame(VmMath::atanh(0.0), AtanhJitHelper::atanhArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::atanh(0.5), AtanhJitHelper::atanhArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atanh(-0.5), AtanhJitHelper::atanhArgv(-0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atanh(0.1), AtanhJitHelper::atanhArgv(0.1), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atanh(-0.1), AtanhJitHelper::atanhArgv(-0.1), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atanh(0.9), AtanhJitHelper::atanhArgv(0.9), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atanh(-0.9), AtanhJitHelper::atanhArgv(-0.9), 1e-15);
        $this->assertEqualsWithDelta(VmMath::atanh(0.99), AtanhJitHelper::atanhArgv(0.99), 1e-14);
        $this->assertSame(\INF, AtanhJitHelper::atanhArgv(1.0));
        $this->assertSame(-\INF, AtanhJitHelper::atanhArgv(-1.0));
        $this->assertTrue(\is_nan(AtanhJitHelper::atanhArgv(2.0)));
        $this->assertTrue(\is_nan(AtanhJitHelper::atanhArgv(-2.0)));
        $this->assertTrue(\is_nan(AtanhJitHelper::atanhArgv(\NAN)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitAtanhKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_atanh_kernel.php');
    }

    public function testContextNoLongerAllowlistsAtanhKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_atanh_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_atan2_kernel', $source);
        $this->assertStringNotContainsString('phpc_sinh_kernel', $source);
        $this->assertStringNotContainsString('phpc_cosh_kernel', $source);
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_log1p_kernel', $source);
    }

    public function testSpineBundleIncludesAtanhHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AtanhJitHelper.php', $spine);
        $this->assertStringContainsString('MathAtanh.php', $spine);
        $this->assertStringNotContainsString('JitAtanhKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_atanh_kernel.php', $spine);
    }
}
