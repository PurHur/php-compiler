<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\AsinhJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * asinh() AOT uses libm asinh(3) (#36386); AsinhJitHelper remains NestedJIT-safe
 * reference (peer MathSinh / SinhJitHelper). LLVM 9 has no llvm.asinh.f64.
 *
 * php-src: ext/standard/math.c PHP_FUNCTION(asinh).
 */
final class AsinhRuntimeShrinkTest extends TestCase
{
    public function testAsinhUsesLibmNotHelperBridge(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/asinh.php');
        $this->assertStringContainsString('MathAsinh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('asinh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathAsinh.php');
        $this->assertStringContainsString("LIBC_ASINH = 'asinh'", $bridge);
        $this->assertStringContainsString('phpc_asinh', $bridge);
        $this->assertStringContainsString('asinh_libm_f64_entry', $bridge);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('AsinhJitHelper', $bridge);
        $this->assertStringNotContainsString('JitAsinhKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('llvm.asinh', $bridge);
    }

    public function testAsinhJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/AsinhJitHelper.php');
        $this->assertStringContainsString('logPositive', $source);
        $this->assertStringContainsString('sqrtPositive', $source);
        $this->assertStringContainsString('0.693147180559945309417', $source);
        $this->assertStringNotContainsString('phpc_asinh_kernel', $source);
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
        // Ternary abs zeros under helper-runtime unit.o NestedJIT (#28263).
        $this->assertDoesNotMatchRegularExpression(
            '/\$ax\s*=\s*\$num\s*<\s*0\.0\s*\?/',
            $source
        );
        $this->assertStringContainsString('sqrtPositive($num * $num)', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function asinhArgv\(.*?\{[^}]*VmMath::asinh/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function asinhArgv\(.*?\{[^}]*\\\\asinh\(/s',
            $source
        );

        $this->assertSame(VmMath::asinh(0.0), AsinhJitHelper::asinhArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::asinh(1.0), AsinhJitHelper::asinhArgv(1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::asinh(-1.0), AsinhJitHelper::asinhArgv(-1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::asinh(0.5), AsinhJitHelper::asinhArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::asinh(-0.5), AsinhJitHelper::asinhArgv(-0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::asinh(2.0), AsinhJitHelper::asinhArgv(2.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::asinh(10.0), AsinhJitHelper::asinhArgv(10.0), 1e-14);
        $this->assertEqualsWithDelta(VmMath::asinh(100.0), AsinhJitHelper::asinhArgv(100.0), 1e-14);
        $this->assertEqualsWithDelta(VmMath::asinh(1.0e6), AsinhJitHelper::asinhArgv(1.0e6), 1e-12);
        $this->assertEqualsWithDelta(VmMath::asinh(1.0e20), AsinhJitHelper::asinhArgv(1.0e20), 1e-10);
        $this->assertSame(\INF, AsinhJitHelper::asinhArgv(\INF));
        $this->assertSame(-\INF, AsinhJitHelper::asinhArgv(-\INF));
        $this->assertTrue(\is_nan(AsinhJitHelper::asinhArgv(\NAN)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitAsinhKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_asinh_kernel.php');
    }

    public function testContextNoLongerAllowlistsAsinhKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_asinh_kernel', $source);
        $this->assertStringNotContainsString('phpc_atanh_kernel', $source);
        $this->assertStringNotContainsString('phpc_atan2_kernel', $source);
        $this->assertStringNotContainsString('phpc_fpow_kernel', $source);
        $this->assertStringNotContainsString('phpc_nextafter_kernel', $source);
        $this->assertStringNotContainsString('phpc_log10_kernel', $source);
    }

    public function testSpineBundleIncludesAsinhHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('AsinhJitHelper.php', $spine);
        $this->assertStringContainsString('MathAsinh.php', $spine);
        $this->assertStringNotContainsString('JitAsinhKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_asinh_kernel.php', $spine);
    }
}
