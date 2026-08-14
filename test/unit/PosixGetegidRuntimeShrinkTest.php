<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixGetegidJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPUnit\Framework\TestCase;

/**
 * posix_getegid() AOT via PosixGetegidJitHelper PHP + NestedJIT libc getegid(2) leaf (#30986).
 */
final class PosixGetegidRuntimeShrinkTest extends TestCase
{
    public function testJitPosixGetegidRoutesThroughPosixGetegidJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixGetegidJit::invoke', $source);
        $this->assertStringNotContainsString('ensureLibcEgid', $source);
        // getegid user path must not declare/call libc getegid — NestedJIT leaf owns that.
        $this->assertDoesNotMatchRegularExpression(
            '/function getegid\(Context \$context\): Value\s*\{[^}]*lookupFunction\(\'getegid\'\)/s',
            $source
        );
    }

    public function testPosixGetegidJitRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixGetegidJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('PosixGetegidJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitPosixGetegidKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_posix_getegid', $bridge);
        $this->assertStringNotContainsString("lookupFunction('getegid')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafOwnsLibcGetegid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosixGetegidKernel.php');
        $this->assertStringContainsString("lookupFunction('getegid')", $source);
        $this->assertStringContainsString('ensureLibcGetegid', $source);
    }

    public function testPosixGetegidJitHelperUsesHostPosixGetegidNotVmPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixGetegidJitHelper.php');
        $this->assertStringContainsString('@\\posix_getegid', $source);
        $this->assertStringNotContainsString('VmPosix::getegid', $source);

        $got = PosixGetegidJitHelper::getegidArgv();
        $this->assertIsInt($got);
        $this->assertGreaterThanOrEqual(0, $got);
        $this->assertSame(VmPosix::getegid(), $got);
        $this->assertSame((int) \posix_getegid(), $got);
    }

    public function testNestedJitAllowlistsPosixGetegidBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'posix_getegid'", $source);
        $this->assertStringContainsString('#30986', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesPosixGetegidArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PosixGetegidJitHelper.php', $spine);
        $this->assertStringContainsString('PosixGetegidJit.php', $spine);
        $this->assertStringContainsString('JitPosixGetegidKernel.php', $spine);
    }

    public function testPosixGetegidBuiltinRoutesThroughJitPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_getegid.php');
        $this->assertStringContainsString('JitPosix::getegid', $source);
        $this->assertStringContainsString('#30986', $source);
    }

    public function testTypeRegistersPosixGetegidBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PosixGetegidJit::ensureLinked', $source);
    }
}
