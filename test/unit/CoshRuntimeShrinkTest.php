<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\CoshJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * cosh() NestedJIT via JitVmHelperLink::ensureBridge (#28446 / peer MathSinh #28418).
 */
final class CoshRuntimeShrinkTest extends TestCase
{
    public function testCoshUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/cosh.php');
        $this->assertStringContainsString('MathCosh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('cosh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathCosh.php');
        $this->assertStringContainsString('CoshJitHelper', $bridge);
        $this->assertStringContainsString('phpc_cosh', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitCoshKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testCoshJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/CoshJitHelper.php');
        $this->assertStringContainsString('expPositive', $source);
        $this->assertStringContainsString('sqrtPositive', $source);
        $this->assertStringContainsString('0.693147180559945309417', $source);
        $this->assertStringNotContainsString('phpc_cosh_kernel', $source);
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
        // NestedJIT treats float self-inequality NaN probes as always-true — overflow
        // must gate on Inf identity only (see helper comment).
        $this->assertDoesNotMatchRegularExpression('/\$ex\s*!==\s*\$ex/', $source);
        $this->assertStringContainsString('$ex === $inf', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function coshArgv\(.*?\{[^}]*VmMath::cosh/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function coshArgv\(.*?\{[^}]*\\\\cosh\(/s',
            $source
        );

        $this->assertSame(VmMath::cosh(0.0), CoshJitHelper::coshArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::cosh(0.1), CoshJitHelper::coshArgv(0.1), 1e-14);
        $this->assertEqualsWithDelta(VmMath::cosh(-0.1), CoshJitHelper::coshArgv(-0.1), 1e-14);
        $this->assertEqualsWithDelta(VmMath::cosh(0.5), CoshJitHelper::coshArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::cosh(-0.5), CoshJitHelper::coshArgv(-0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::cosh(1.0), CoshJitHelper::coshArgv(1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::cosh(-1.0), CoshJitHelper::coshArgv(-1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::cosh(2.0), CoshJitHelper::coshArgv(2.0), 1e-15);
        foreach ([10.0, 100.0, 700.0, 710.0, -10.0, -700.0] as $sample) {
            $expected = VmMath::cosh($sample);
            $actual = CoshJitHelper::coshArgv($sample);
            $this->assertEqualsWithDelta(
                $expected,
                $actual,
                1e-12 * max(1.0, abs($expected)),
                'cosh('.$sample.')'
            );
        }
        $this->assertSame(\INF, CoshJitHelper::coshArgv(\INF));
        $this->assertSame(\INF, CoshJitHelper::coshArgv(-\INF));
        $this->assertTrue(\is_nan(CoshJitHelper::coshArgv(\NAN)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitCoshKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_cosh_kernel.php');
    }

    public function testContextNoLongerAllowlistsCoshKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_cosh_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_atan_kernel', $source);
        $this->assertStringContainsString('phpc_tanh_kernel', $source);
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_expm1_kernel', $source);
    }

    public function testSpineBundleIncludesCoshHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('CoshJitHelper.php', $spine);
        $this->assertStringContainsString('MathCosh.php', $spine);
        $this->assertStringNotContainsString('JitCoshKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_cosh_kernel.php', $spine);
    }
}
