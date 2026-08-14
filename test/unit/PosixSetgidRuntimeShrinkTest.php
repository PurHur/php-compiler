<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixSetgidJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPUnit\Framework\TestCase;

/**
 * posix_setgid() AOT via PosixSetgidJitHelper PHP + NestedJIT libc setgid(2) leaf (#31066).
 */
final class PosixSetgidRuntimeShrinkTest extends TestCase
{
    public function testJitPosixSetgidRoutesThroughPosixSetgidJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixSetgidJit::invoke', $source);
        $this->assertStringNotContainsString('setId(', $source);
        $this->assertStringNotContainsString('ensureLibcSetId', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function setgid\(Context \$context, Value \$gidI64\): Value\s*\{[^}]*lookupFunction\(\'setgid\'\)/s',
            $source
        );
    }

    public function testPosixSetgidJitRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixSetgidJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('PosixSetgidJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitPosixSetgidKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_posix_setgid', $bridge);
        $this->assertStringNotContainsString("lookupFunction('setgid')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafOwnsLibcSetgid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosixSetgidKernel.php');
        $this->assertStringContainsString("lookupFunction('setgid')", $source);
        $this->assertStringContainsString('ensureLibcSetgid', $source);
    }

    public function testPosixSetgidJitHelperUsesHostPosixSetgidNotVmPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixSetgidJitHelper.php');
        $this->assertStringContainsString('@\\posix_setgid', $source);
        $this->assertStringNotContainsString('VmPosix::setgid', $source);
        $this->assertStringNotContainsString('IdentityWritePure', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);

        if (!\function_exists('posix_setgid') || !\function_exists('posix_getgid')) {
            $this->markTestSkipped('host posix_setgid unavailable');
        }
        $gid = (int) \posix_getgid();
        $got = PosixSetgidJitHelper::setgidArgv($gid);
        $this->assertSame(1, $got);
        $this->assertTrue(VmPosix::setgid($gid));
        $this->assertTrue((bool) \posix_setgid($gid));
    }

    public function testNestedJitAllowlistsPosixSetgidBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'posix_setgid'", $source);
        $this->assertStringContainsString('#31066', $source);
    }

    public function testSpineBundleIncludesPosixSetgidArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PosixSetgidJitHelper.php', $spine);
        $this->assertStringContainsString('PosixSetgidJit.php', $spine);
        $this->assertStringContainsString('JitPosixSetgidKernel.php', $spine);
    }

    public function testPosixSetgidBuiltinRoutesThroughJitPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_setgid.php');
        $this->assertStringContainsString('JitPosix::setgid', $source);
        $this->assertStringContainsString('JitLongArg::lower', $source);
        $this->assertStringContainsString('#31066', $source);
    }

    public function testTypeRegistersPosixSetgidBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PosixSetgidJit::ensureLinked', $source);
    }
}
