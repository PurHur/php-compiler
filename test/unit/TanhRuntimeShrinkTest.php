<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\TanhJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * tanh() NestedJIT via JitVmHelperLink::ensureBridge (#28459 / peer MathCosh #28446).
 */
final class TanhRuntimeShrinkTest extends TestCase
{
    public function testTanhUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/tanh.php');
        $this->assertStringContainsString('MathTanh::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('tanh')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathTanh.php');
        $this->assertStringContainsString('TanhJitHelper', $bridge);
        $this->assertStringContainsString('phpc_tanh', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitTanhKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testTanhJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/TanhJitHelper.php');
        $this->assertStringContainsString('expPositive', $source);
        $this->assertStringContainsString('sqrtPositive', $source);
        $this->assertStringContainsString('0.693147180559945309417', $source);
        $this->assertStringNotContainsString('phpc_tanh_kernel', $source);
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
            '/function tanhArgv\(.*?\{[^}]*VmMath::tanh/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function tanhArgv\(.*?\{[^}]*\\\\tanh\(/s',
            $source
        );

        $this->assertSame(VmMath::tanh(0.0), TanhJitHelper::tanhArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::tanh(0.1), TanhJitHelper::tanhArgv(0.1), 1e-14);
        $this->assertEqualsWithDelta(VmMath::tanh(-0.1), TanhJitHelper::tanhArgv(-0.1), 1e-14);
        $this->assertEqualsWithDelta(VmMath::tanh(0.5), TanhJitHelper::tanhArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::tanh(-0.5), TanhJitHelper::tanhArgv(-0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::tanh(1.0), TanhJitHelper::tanhArgv(1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::tanh(-1.0), TanhJitHelper::tanhArgv(-1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::tanh(2.0), TanhJitHelper::tanhArgv(2.0), 1e-15);
        foreach ([10.0, 20.0, 40.0, 100.0, 700.0, -10.0, -40.0, -700.0] as $sample) {
            $expected = VmMath::tanh($sample);
            $actual = TanhJitHelper::tanhArgv($sample);
            $this->assertEqualsWithDelta(
                $expected,
                $actual,
                1e-12 * max(1.0, abs($expected)),
                'tanh('.$sample.')'
            );
        }
        $this->assertSame(1.0, TanhJitHelper::tanhArgv(\INF));
        $this->assertSame(-1.0, TanhJitHelper::tanhArgv(-\INF));
        $this->assertTrue(\is_nan(TanhJitHelper::tanhArgv(\NAN)));
    }

    public function testKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitTanhKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_tanh_kernel.php');
    }

    public function testContextNoLongerAllowlistsTanhKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_tanh_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_atan2_kernel', $source);
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_log1p_kernel', $source);
    }

    public function testSpineBundleIncludesTanhHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('TanhJitHelper.php', $spine);
        $this->assertStringContainsString('MathTanh.php', $spine);
        $this->assertStringNotContainsString('JitTanhKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_tanh_kernel.php', $spine);
    }
}
