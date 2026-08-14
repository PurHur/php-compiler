<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixSetegidJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPUnit\Framework\TestCase;

/**
 * posix_setegid() AOT via PosixSetegidJitHelper PHP + NestedJIT libc setegid(2) leaf (#31066).
 */
final class PosixSetegidRuntimeShrinkTest extends TestCase
{
    public function testJitPosixSetegidRoutesThroughPosixSetegidJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixSetegidJit::invoke', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function setegid\(Context \$context, Value \$gidI64\): Value\s*\{[^}]*lookupFunction\(\'setegid\'\)/s',
            $source
        );
    }

    public function testPosixSetegidJitRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixSetegidJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('PosixSetegidJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitPosixSetegidKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_posix_setegid', $bridge);
        $this->assertStringNotContainsString("lookupFunction('setegid')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafOwnsLibcSetegid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosixSetegidKernel.php');
        $this->assertStringContainsString("lookupFunction('setegid')", $source);
        $this->assertStringContainsString('ensureLibcSetegid', $source);
    }

    public function testPosixSetegidJitHelperUsesHostPosixSetegidNotVmPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixSetegidJitHelper.php');
        $this->assertStringContainsString('@\\posix_setegid', $source);
        $this->assertStringNotContainsString('VmPosix::setegid', $source);

        if (!\function_exists('posix_setegid') || !\function_exists('posix_getegid')) {
            $this->markTestSkipped('host posix_setegid unavailable');
        }
        $gid = (int) \posix_getegid();
        $got = PosixSetegidJitHelper::setegidArgv($gid);
        $this->assertSame(1, $got);
        $this->assertTrue(VmPosix::setegid($gid));
        $this->assertTrue((bool) \posix_setegid($gid));
    }

    public function testNestedJitAllowlistsPosixSetegidBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'posix_setegid'", $source);
        $this->assertStringContainsString('#31066', $source);
    }

    public function testSpineBundleIncludesPosixSetegidArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PosixSetegidJitHelper.php', $spine);
        $this->assertStringContainsString('PosixSetegidJit.php', $spine);
        $this->assertStringContainsString('JitPosixSetegidKernel.php', $spine);
    }

    public function testPosixSetegidBuiltinRoutesThroughJitPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_setegid.php');
        $this->assertStringContainsString('JitPosix::setegid', $source);
        $this->assertStringContainsString('JitLongArg::lower', $source);
        $this->assertStringContainsString('#31066', $source);
    }

    public function testTypeRegistersPosixSetegidBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PosixSetegidJit::ensureLinked', $source);
    }
}
