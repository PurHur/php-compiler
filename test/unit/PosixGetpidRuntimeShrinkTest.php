<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixGetpidJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPUnit\Framework\TestCase;

/**
 * posix_getpid() AOT via PosixGetpidJitHelper PHP + NestedJIT libc getpid(2) leaf (#30696).
 */
final class PosixGetpidRuntimeShrinkTest extends TestCase
{
    public function testJitPosixGetpidRoutesThroughPosixGetpidJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixGetpidJit::invoke', $source);
        $this->assertStringNotContainsString('ensureLibcPid', $source);
        // getpid user path must not declare/call libc getpid — NestedJIT leaf owns that.
        $this->assertDoesNotMatchRegularExpression(
            '/function getpid\(Context \$context\): Value\s*\{[^}]*lookupFunction\(\'getpid\'\)/s',
            $source
        );
    }

    public function testPosixGetpidJitRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixGetpidJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('PosixGetpidJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitGetmypidKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_posix_getpid', $bridge);
        $this->assertStringNotContainsString("lookupFunction('getpid')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafReusesGetmypidKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetmypidKernel.php');
        $this->assertStringContainsString("lookupFunction('getpid')", $source);
        $this->assertStringContainsString('ensureLibcGetpid', $source);
    }

    public function testPosixGetpidJitHelperUsesHostPosixGetpidNotVmPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixGetpidJitHelper.php');
        $this->assertStringContainsString('@\\posix_getpid', $source);
        $this->assertStringNotContainsString('VmPosix::getpid', $source);
        $this->assertStringNotContainsString('VmDate::getmypid', $source);

        $got = PosixGetpidJitHelper::getpidArgv();
        $this->assertIsInt($got);
        $this->assertGreaterThan(0, $got);
        $this->assertSame(VmPosix::getpid(), $got);
        $this->assertSame(\posix_getpid(), $got);
        $this->assertSame(\getmypid(), $got);
    }

    public function testNestedJitAllowlistsPosixGetpidBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'posix_getpid'", $source);
        $this->assertStringContainsString('#30696', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesPosixGetpidArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PosixGetpidJitHelper.php', $spine);
        $this->assertStringContainsString('PosixGetpidJit.php', $spine);
        $this->assertStringContainsString('JitGetmypidKernel.php', $spine);
    }

    public function testPosixGetpidBuiltinRoutesThroughJitPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_getpid.php');
        $this->assertStringContainsString('JitPosix::getpid', $source);
        $this->assertStringContainsString('#30696', $source);
    }

    public function testTypeRegistersPosixGetpidBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PosixGetpidJit::ensureLinked', $source);
    }
}
