<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SinhJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * sinh() NestedJIT via JitVmHelperLink::ensureBridge (#28418 / peer MathAtanh #28377).
 */
final class SinhRuntimeShrinkTest extends TestCase
{
    public function testSinhUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/sinh.php');
        $this->assertStringContainsString('MathSinh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('sinh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathSinh.php');
        $this->assertStringContainsString('SinhJitHelper', $bridge);
        $this->assertStringContainsString('phpc_sinh', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitSinhKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testSinhJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SinhJitHelper.php');
        $this->assertStringContainsString('expPositive', $source);
        $this->assertStringContainsString('sqrtPositive', $source);
        $this->assertStringContainsString('0.693147180559945309417', $source);
        $this->assertStringNotContainsString('phpc_sinh_kernel', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/(?<!@see )ExpJitHelper::/',
            $source
        );
        $this->assertStringNotContainsString('\\ExpJitHelper', $source);
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
            '/function sinhArgv\(.*?\{[^}]*VmMath::sinh/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function sinhArgv\(.*?\{[^}]*\\\\sinh\(/s',
            $source
        );

        $this->assertSame(VmMath::sinh(0.0), SinhJitHelper::sinhArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::sinh(0.1), SinhJitHelper::sinhArgv(0.1), 1e-14);
        $this->assertEqualsWithDelta(VmMath::sinh(-0.1), SinhJitHelper::sinhArgv(-0.1), 1e-14);
        $this->assertEqualsWithDelta(VmMath::sinh(0.5), SinhJitHelper::sinhArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::sinh(-0.5), SinhJitHelper::sinhArgv(-0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::sinh(1.0), SinhJitHelper::sinhArgv(1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::sinh(-1.0), SinhJitHelper::sinhArgv(-1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::sinh(2.0), SinhJitHelper::sinhArgv(2.0), 1e-15);
        foreach ([10.0, 100.0, 700.0, 710.0, -10.0, -700.0] as $sample) {
            $expected = VmMath::sinh($sample);
            $actual = SinhJitHelper::sinhArgv($sample);
            $this->assertEqualsWithDelta(
                $expected,
                $actual,
                1e-12 * max(1.0, abs($expected)),
                'sinh('.$sample.')'
            );
        }
        $this->assertSame(\INF, SinhJitHelper::sinhArgv(\INF));
        $this->assertSame(-\INF, SinhJitHelper::sinhArgv(-\INF));
        $this->assertTrue(\is_nan(SinhJitHelper::sinhArgv(\NAN)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitSinhKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_sinh_kernel.php');
    }

    public function testContextNoLongerAllowlistsSinhKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_sinh_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_atan2_kernel', $source);
        $this->assertStringNotContainsString('phpc_cosh_kernel', $source);
        $this->assertStringNotContainsString('phpc_tanh_kernel', $source);
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_log_kernel', $source);
    }

    public function testSpineBundleIncludesSinhHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SinhJitHelper.php', $spine);
        $this->assertStringContainsString('MathSinh.php', $spine);
        $this->assertStringNotContainsString('JitSinhKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_sinh_kernel.php', $spine);
    }
}
