<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\LogJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** log() JIT: always LogJitHelper via JitVmHelperLink + phpc_log_kernel (#15117, #27047). */
final class LogRuntimeShrinkTest extends TestCase
{
    public function testLogUsesJitHelperNotLibcLookup(): void
    {
        $builtin = (string) file_get_contents(__DIR__.'/../../ext/standard/log.php');
        $this->assertStringContainsString('MathLog::invoke', $builtin);
        $this->assertStringNotContainsString("lookupFunction('log')", $builtin);

        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathLog.php');
        $this->assertStringContainsString('LogJitHelper', $bridge);
        $this->assertStringContainsString('phpc_log', $bridge);
        $this->assertStringContainsString('JitLogKernel', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
    }

    public function testLogJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/LogJitHelper.php');
        $this->assertStringContainsString('phpc_log_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/function logArgv\(.*?\{[^}]*phpc_log_kernel/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function logArgv\(.*?\{[^}]*VmMath::log/s',
            $source
        );

        if (!\function_exists('phpc_log_kernel')) {
            $this->markTestSkipped('phpc_log_kernel requires compiler runtime');
        }
        $this->assertSame(
            VmMath::log(1.0),
            LogJitHelper::logArgv(1.0)
        );
        $this->assertSame(
            VmMath::log(\M_E),
            LogJitHelper::logArgv(\M_E)
        );
        $this->assertSame(2.0, VmMath::logWithBase(100.0, 10.0));
        $this->assertSame(3.0, VmMath::logWithBase(8.0, 2.0));
        $this->assertTrue(\is_nan(VmMath::logWithBase(10.0, 1.0)));
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

    public function testContextAllowlistsLogKernelForNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_log_kernel', $source);
        $this->assertStringContainsString('phpc_hypot_kernel', $source);
    }

    public function testSpineBundleIncludesLogJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('LogJitHelper.php', $spine);
        $this->assertStringContainsString('MathLog.php', $spine);
        $this->assertStringContainsString('JitLogKernel.php', $spine);
        $this->assertStringContainsString('phpc_log_kernel.php', $spine);
    }
}
