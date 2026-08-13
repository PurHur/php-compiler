<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixGetppidJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPUnit\Framework\TestCase;

/**
 * posix_getppid() AOT via PosixGetppidJitHelper PHP + NestedJIT libc getppid(2) leaf (#30728).
 */
final class PosixGetppidRuntimeShrinkTest extends TestCase
{
    public function testJitPosixGetppidRoutesThroughPosixGetppidJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixGetppidJit::invoke', $source);
        $this->assertStringNotContainsString('ensureLibcGetppid', $source);
        // getppid user path must not declare/call libc getppid — NestedJIT leaf owns that.
        $this->assertDoesNotMatchRegularExpression(
            '/function getppid\(Context \$context\): Value\s*\{[^}]*lookupFunction\(\'getppid\'\)/s',
            $source
        );
    }

    public function testPosixGetppidJitRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixGetppidJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('PosixGetppidJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitPosixGetppidKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_posix_getppid', $bridge);
        $this->assertStringNotContainsString("lookupFunction('getppid')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafOwnsLibcGetppid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosixGetppidKernel.php');
        $this->assertStringContainsString("lookupFunction('getppid')", $source);
        $this->assertStringContainsString('ensureLibcGetppid', $source);
    }

    public function testPosixGetppidJitHelperUsesHostPosixGetppidNotVmPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixGetppidJitHelper.php');
        $this->assertStringContainsString('@\\posix_getppid', $source);
        $this->assertStringNotContainsString('VmPosix::getppid', $source);

        $got = PosixGetppidJitHelper::getppidArgv();
        $this->assertIsInt($got);
        // PPID may be 0 when the test process is PID 1 (common under docker-exec).
        $this->assertGreaterThanOrEqual(0, $got);
        $this->assertSame(VmPosix::getppid(), $got);
        $this->assertSame((int) \posix_getppid(), $got);
    }

    public function testNestedJitAllowlistsPosixGetppidBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'posix_getppid'", $source);
        $this->assertStringContainsString('#30728', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesPosixGetppidArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PosixGetppidJitHelper.php', $spine);
        $this->assertStringContainsString('PosixGetppidJit.php', $spine);
        $this->assertStringContainsString('JitPosixGetppidKernel.php', $spine);
    }

    public function testPosixGetppidBuiltinRoutesThroughJitPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_getppid.php');
        $this->assertStringContainsString('JitPosix::getppid', $source);
        $this->assertStringContainsString('#30728', $source);
    }

    public function testTypeRegistersPosixGetppidBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PosixGetppidJit::ensureLinked', $source);
    }
}
