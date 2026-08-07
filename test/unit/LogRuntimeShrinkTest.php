<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\LogJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/**
 * log() NestedJIT via JitVmHelperLink::ensureBridge (#28574 / peer #28495).
 */
final class LogRuntimeShrinkTest extends TestCase
{
    public function testLogUsesJitHelperNotKernel(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/log.php');
        $this->assertStringContainsString('MathLog::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('log')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathLog.php');
        $this->assertStringContainsString('LogJitHelper', $bridge);
        $this->assertStringContainsString('phpc_log', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString('JitLogKernel', $bridge);
        $this->assertStringNotContainsString('NestedJitCompileScope', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testLogJitHelperInlinesNestedJitSafeAlgorithm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/LogJitHelper.php');
        $this->assertStringContainsString('logPositive', $source);
        $this->assertStringContainsString('0.693147180559945309417', $source);
        $this->assertStringContainsString('2048', $source);
        $this->assertStringNotContainsString('phpc_log_kernel', $source);
        $this->assertStringNotContainsString('while (', $source);
        $this->assertStringNotContainsString('pack(', $source);
        $this->assertStringNotContainsString('unpack(', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function logArgv\(.*?\{[^}]*VmMath::log/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function logArgv\(.*?\{[^}]*\\\\log\(/s',
            $source
        );

        $this->assertSame(VmMath::log(1.0), LogJitHelper::logArgv(1.0));
        $this->assertEqualsWithDelta(VmMath::log(\M_E), LogJitHelper::logArgv(\M_E), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log(0.5), LogJitHelper::logArgv(0.5), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log(2.0), LogJitHelper::logArgv(2.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log(10.0), LogJitHelper::logArgv(10.0), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log(0.1), LogJitHelper::logArgv(0.1), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log(1e-8), LogJitHelper::logArgv(1e-8), 1e-15);
        $this->assertEqualsWithDelta(VmMath::log(1e8), LogJitHelper::logArgv(1e8), 1e-12);
        $this->assertSame(2.0, VmMath::logWithBase(100.0, 10.0));
        $this->assertSame(3.0, VmMath::logWithBase(8.0, 2.0));
        $this->assertTrue(\is_nan(VmMath::logWithBase(10.0, 1.0)));
        $this->assertTrue(\is_infinite(LogJitHelper::logArgv(0.0)));
        $this->assertLessThan(0.0, LogJitHelper::logArgv(0.0));
        $this->assertTrue(\is_infinite(LogJitHelper::logArgv(\INF)));
        $this->assertTrue(\is_nan(LogJitHelper::logArgv(-\INF)));
        $this->assertTrue(\is_nan(LogJitHelper::logArgv(\NAN)));
        $this->assertTrue(\is_nan(LogJitHelper::logArgv(-1.0)));
    }

    public function testMathLogExposesBaseLowering(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathLog.php');
        $this->assertStringContainsString('invokeWithBase', $bridge);
        $this->assertStringContainsString('MathLog10::invoke', $bridge);
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/log.php');
        $this->assertStringContainsString('invokeWithBase', $builtin);
        $this->assertStringContainsString('requireArgCountRange', $builtin);
        $this->assertStringContainsString('logWithBase', $builtin);
    }

    public function testLogKernelFilesRemoved(): void
    {
        $root = __DIR__.'/../..';
        $this->assertFileDoesNotExist($root.'/ext/standard/JitLogKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_log_kernel.php');
    }

    public function testContextNoLongerAllowlistsLogKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringNotContainsString('phpc_log_kernel', $source);
        // Peer math NestedJIT leaf still allowlisted after this shrink.
        $this->assertStringContainsString('phpc_log10_kernel', $source);
        $this->assertStringContainsString('phpc_fpow_kernel', $source);
        $this->assertStringContainsString('phpc_nextafter_kernel', $source);
    }

    public function testSpineBundleIncludesLogHelperWithoutKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('LogJitHelper.php', $spine);
        $this->assertStringContainsString('MathLog.php', $spine);
        $this->assertStringNotContainsString('JitLogKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_log_kernel.php', $spine);
    }
}
