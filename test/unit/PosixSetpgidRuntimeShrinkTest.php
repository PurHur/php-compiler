<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixSetpgidJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPUnit\Framework\TestCase;

/**
 * posix_setpgid() AOT via PosixSetpgidJitHelper PHP + NestedJIT libc setpgid(2) leaf (#31235).
 */
final class PosixSetpgidRuntimeShrinkTest extends TestCase
{
    public function testJitPosixSetpgidRoutesThroughPosixSetpgidJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixSetpgidJit::invoke', $source);
        $this->assertStringNotContainsString('ensureLibcSetpgid', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function setpgid\(Context \$context, JITVariable \$pidArg, JITVariable \$pgidArg\): Value\s*\{[^}]*lookupFunction\(\'setpgid\'\)/s',
            $source
        );
    }

    public function testPosixSetpgidJitRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixSetpgidJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('PosixSetpgidJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitPosixSetpgidKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_posix_setpgid', $bridge);
        $this->assertStringNotContainsString("lookupFunction('setpgid')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafOwnsLibcSetpgid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosixSetpgidKernel.php');
        $this->assertStringContainsString("lookupFunction('setpgid')", $source);
        $this->assertStringContainsString('ensureLibcSetpgid', $source);
    }

    public function testPosixSetpgidJitHelperUsesHostPosixSetpgidNotVmPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixSetpgidJitHelper.php');
        $this->assertStringContainsString('@\\posix_setpgid', $source);
        $this->assertStringNotContainsString('VmPosix::setpgid', $source);
        $this->assertStringNotContainsString('IdentityWritePure', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);

        if (!\function_exists('posix_setpgid') || !\function_exists('posix_getpid')) {
            $this->markTestSkipped('host posix_setpgid unavailable');
        }
        $pid = (int) \posix_getpid();
        $pgid = \function_exists('posix_getpgid') ? (int) \posix_getpgid($pid) : $pid;
        // PID 1 / container session leaders often get EPERM from setpgid(2); match host.
        $hostOk = (bool) @\posix_setpgid($pid, $pgid);
        $got = PosixSetpgidJitHelper::setpgidArgv($pid, $pgid);
        $this->assertSame($hostOk ? 1 : 0, $got);
        $this->assertSame($hostOk, VmPosix::setpgid($pid, $pgid));
    }

    public function testNestedJitAllowlistsPosixSetpgidBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'posix_setpgid'", $source);
        $this->assertStringContainsString('#31235', $source);
    }

    public function testSpineBundleIncludesPosixSetpgidArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PosixSetpgidJitHelper.php', $spine);
        $this->assertStringContainsString('PosixSetpgidJit.php', $spine);
        $this->assertStringContainsString('JitPosixSetpgidKernel.php', $spine);
    }

    public function testPosixSetpgidBuiltinRoutesThroughJitPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_setpgid.php');
        $this->assertStringContainsString('JitPosix::setpgid', $source);
        $this->assertStringContainsString('#31235', $source);
    }

    public function testTypeRegistersPosixSetpgidBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PosixSetpgidJit::ensureLinked', $source);
    }
}
