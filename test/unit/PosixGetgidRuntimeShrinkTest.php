<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixGetgidJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPUnit\Framework\TestCase;

/**
 * posix_getgid() AOT via PosixGetgidJitHelper PHP + NestedJIT libc getgid(2) leaf (#30803).
 */
final class PosixGetgidRuntimeShrinkTest extends TestCase
{
    public function testJitPosixGetgidRoutesThroughPosixGetgidJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixGetgidJit::invoke', $source);
        $this->assertStringNotContainsString('ensureLibcGid', $source);
        // getgid user path must not declare/call libc getgid — NestedJIT leaf owns that.
        $this->assertDoesNotMatchRegularExpression(
            '/function getgid\(Context \$context\): Value\s*\{[^}]*lookupFunction\(\'getgid\'\)/s',
            $source
        );
    }

    public function testPosixGetgidJitRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixGetgidJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('PosixGetgidJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitPosixGetgidKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_posix_getgid', $bridge);
        $this->assertStringNotContainsString("lookupFunction('getgid')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafOwnsLibcGetgid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosixGetgidKernel.php');
        $this->assertStringContainsString("lookupFunction('getgid')", $source);
        $this->assertStringContainsString('ensureLibcGetgid', $source);
    }

    public function testPosixGetgidJitHelperUsesHostPosixGetgidNotVmPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixGetgidJitHelper.php');
        $this->assertStringContainsString('@\\posix_getgid', $source);
        $this->assertStringNotContainsString('VmPosix::getgid', $source);

        $got = PosixGetgidJitHelper::getgidArgv();
        $this->assertIsInt($got);
        $this->assertGreaterThanOrEqual(0, $got);
        $this->assertSame(VmPosix::getgid(), $got);
        $this->assertSame((int) \posix_getgid(), $got);
    }

    public function testNestedJitAllowlistsPosixGetgidBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'posix_getgid'", $source);
        $this->assertStringContainsString('#30803', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesPosixGetgidArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PosixGetgidJitHelper.php', $spine);
        $this->assertStringContainsString('PosixGetgidJit.php', $spine);
        $this->assertStringContainsString('JitPosixGetgidKernel.php', $spine);
    }

    public function testPosixGetgidBuiltinRoutesThroughJitPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_getgid.php');
        $this->assertStringContainsString('JitPosix::getgid', $source);
        $this->assertStringContainsString('#30803', $source);
    }

    public function testTypeRegistersPosixGetgidBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PosixGetgidJit::ensureLinked', $source);
    }
}
