<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Expm1JitHelper;
use PHPCompiler\ext\standard\Log1pJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * log1p()/expm1() NestedJIT via JitVmHelperLink::ensureBridge (#28495 / #28487).
 */
final class Log1pExpm1RuntimeShrinkTest extends TestCase
{
    public function testLog1pUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/log1p.php');
        $this->assertStringContainsString('MathLog1p::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('log1p')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathLog1p.php');
        $this->assertStringContainsString('Log1pJitHelper', $bridge);
        $this->assertStringContainsString('phpc_log1p', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitLog1pKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testExpm1UsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/expm1.php');
        $this->assertStringContainsString('MathExpm1::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('expm1')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathExpm1.php');
        $this->assertStringContainsString('Expm1JitHelper', $bridge);
        $this->assertStringContainsString('phpc_expm1', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitExpm1Kernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testLog1pJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Log1pJitHelper.php');
        $this->assertStringContainsString('logPositive', $source);
        $this->assertStringContainsString('-0.292893', $source);
        $this->assertStringContainsString('2.0 + $num', $source);
        $this->assertStringNotContainsString('phpc_log1p_kernel', $source);
        $this->assertStringNotContainsString('LogJitHelper::', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function log1pArgv\(.*?\{[^}]*LogJitHelper/s',
            $source
        );
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function log1pArgv\(.*?\{[^}]*VmMath::log1p/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function log1pArgv\(.*?\{[^}]*\\\\log1p\(/s',
            $source
        );

        $this->assertSame(VmMath::log1p(0.0), Log1pJitHelper::log1pArgv(0.0));
        $this->assertEqualsWithDelta(VmMath::log1p(1.0), Log1pJitHelper::log1pArgv(1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log1p(-0.5), Log1pJitHelper::log1pArgv(-0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log1p(0.5), Log1pJitHelper::log1pArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log1p(0.1), Log1pJitHelper::log1pArgv(0.1), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log1p(-0.1), Log1pJitHelper::log1pArgv(-0.1), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log1p(0.01), Log1pJitHelper::log1pArgv(0.01), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log1p(-0.01), Log1pJitHelper::log1pArgv(-0.01), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log1p(2.0), Log1pJitHelper::log1pArgv(2.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log1p(10.0), Log1pJitHelper::log1pArgv(10.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log1p(-0.9), Log1pJitHelper::log1pArgv(-0.9), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log1p(-0.999), Log1pJitHelper::log1pArgv(-0.999), 1e-14);
        $this->assertEqualsWithDelta(VmMath::log1p(1e-8), Log1pJitHelper::log1pArgv(1e-8), 1e-20);
        $this->assertSame(VmMath::log1p(-1.0), Log1pJitHelper::log1pArgv(-1.0));
        $this->assertTrue(\is_infinite(Log1pJitHelper::log1pArgv(\INF)));
        $this->assertTrue(\is_nan(Log1pJitHelper::log1pArgv(-\INF)));
        $this->assertTrue(\is_nan(Log1pJitHelper::log1pArgv(\NAN)));
        $this->assertTrue(\is_nan(Log1pJitHelper::log1pArgv(-1.5)));
    }

    public function testExpm1JitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Expm1JitHelper.php');
        $this->assertStringContainsString('0.693147180559945309417', $source);
        $this->assertStringContainsString('1.44269504088896340736', $source);
        $this->assertStringContainsString('/ 20.0', $source);
        $this->assertStringNotContainsString('phpc_expm1_kernel', $source);
        $this->assertStringNotContainsString('ExpJitHelper::', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function expm1Argv\(.*?\{[^}]*ExpJitHelper/s',
            $source
        );
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function expm1Argv\(.*?\{[^}]*VmMath::expm1/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function expm1Argv\(.*?\{[^}]*\\\\expm1\(/s',
            $source
        );

        $this->assertSame(VmMath::expm1(0.0), Expm1JitHelper::expm1Argv(0.0));
        $this->assertEqualsWithDelta(VmMath::expm1(1.0), Expm1JitHelper::expm1Argv(1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::expm1(-1.0), Expm1JitHelper::expm1Argv(-1.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::expm1(0.5), Expm1JitHelper::expm1Argv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::expm1(-0.5), Expm1JitHelper::expm1Argv(-0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::expm1(0.1), Expm1JitHelper::expm1Argv(0.1), 1e-15);
        $this->assertEqualsWithDelta(VmMath::expm1(2.0), Expm1JitHelper::expm1Argv(2.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::expm1(-2.0), Expm1JitHelper::expm1Argv(-2.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::expm1(10.0), Expm1JitHelper::expm1Argv(10.0), 1e-10);
        $this->assertEqualsWithDelta(VmMath::expm1(-10.0), Expm1JitHelper::expm1Argv(-10.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::expm1(\M_LN2), Expm1JitHelper::expm1Argv(\M_LN2), 1e-15);
        $this->assertTrue(\is_infinite(Expm1JitHelper::expm1Argv(\INF)));
        $this->assertSame(-1.0, Expm1JitHelper::expm1Argv(-\INF));
        $this->assertTrue(\is_nan(Expm1JitHelper::expm1Argv(\NAN)));
    }

    public function testLog1pKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitLog1pKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_log1p_kernel.php');
    }

    public function testExpm1KernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitExpm1Kernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_expm1_kernel.php');
    }

    public function testContextNoLongerAllowlistsLog1pOrExpm1Kernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_log1p_kernel', $source);
        $this->assertStringNotContainsString('phpc_expm1_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_log10_kernel', $source);
        $this->assertStringNotContainsString('phpc_atan2_kernel', $source);
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
    }

    public function testSpineBundleIncludesHelpersWithoutKernels(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Log1pJitHelper.php', $spine);
        $this->assertStringContainsString('MathLog1p.php', $spine);
        $this->assertStringContainsString('Expm1JitHelper.php', $spine);
        $this->assertStringContainsString('MathExpm1.php', $spine);
        $this->assertStringNotContainsString('JitLog1pKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_log1p_kernel.php', $spine);
        $this->assertStringNotContainsString('JitExpm1Kernel.php', $spine);
        $this->assertStringNotContainsString('phpc_expm1_kernel.php', $spine);
    }
}
