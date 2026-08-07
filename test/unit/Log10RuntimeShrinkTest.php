<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\Log10JitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * log10() NestedJIT via JitVmHelperLink::ensureBridge (#28642 / peer #28574).
 */
final class Log10RuntimeShrinkTest extends TestCase
{
    public function testLog10UsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/log10.php');
        $this->assertStringContainsString('MathLog10::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('log10')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathLog10.php');
        $this->assertStringContainsString('Log10JitHelper', $bridge);
        $this->assertStringContainsString('phpc_log10', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitLog10Kernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testLog10JitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Log10JitHelper.php');
        $this->assertStringContainsString('logPositive', $source);
        $this->assertStringContainsString('2.30258509299404568402', $source);
        $this->assertStringContainsString('2048', $source);
        $this->assertStringNotContainsString('phpc_log10_kernel', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function log10Argv\(.*?\{[^}]*VmMath::log10/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function log10Argv\(.*?\{[^}]*\\\\log10\(/s',
            $source
        );

        $this->assertSame(VmMath::log10(1.0), Log10JitHelper::log10Argv(1.0));
        $this->assertEqualsWithDelta(VmMath::log10(10.0), Log10JitHelper::log10Argv(10.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log10(100.0), Log10JitHelper::log10Argv(100.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log10(0.1), Log10JitHelper::log10Argv(0.1), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log10(2.0), Log10JitHelper::log10Argv(2.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log10(1e-8), Log10JitHelper::log10Argv(1e-8), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log10(1e8), Log10JitHelper::log10Argv(1e8), 1e-12);
        $this->assertTrue(\is_infinite(Log10JitHelper::log10Argv(0.0)));
        $this->assertLessThan(0.0, Log10JitHelper::log10Argv(0.0));
        $this->assertTrue(\is_infinite(Log10JitHelper::log10Argv(\INF)));
        $this->assertTrue(\is_nan(Log10JitHelper::log10Argv(-\INF)));
        $this->assertTrue(\is_nan(Log10JitHelper::log10Argv(\NAN)));
        $this->assertTrue(\is_nan(Log10JitHelper::log10Argv(-1.0)));
    }

    public function testLog10KernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitLog10Kernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_log10_kernel.php');
    }

    public function testContextNoLongerAllowlistsLog10Kernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_log10_kernel', $source);
        $this->assertStringNotContainsString('phpc_fpow_kernel', $source);
        $this->assertStringNotContainsString('phpc_nextafter_kernel', $source);
    }

    public function testSpineBundleIncludesLog10HelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('Log10JitHelper.php', $spine);
        $this->assertStringContainsString('MathLog10.php', $spine);
        $this->assertStringNotContainsString('JitLog10Kernel.php', $spine);
        $this->assertStringNotContainsString('phpc_log10_kernel.php', $spine);
    }
}
