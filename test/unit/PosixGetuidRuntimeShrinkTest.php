<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixGetuidJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPUnit\Framework\TestCase;

/**
 * posix_getuid() AOT via PosixGetuidJitHelper PHP + NestedJIT libc getuid(2) leaf (#30744).
 */
final class PosixGetuidRuntimeShrinkTest extends TestCase
{
    public function testJitPosixGetuidRoutesThroughPosixGetuidJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixGetuidJit::invoke', $source);
        $this->assertStringNotContainsString('ensureLibcUid', $source);
        // getuid user path must not declare/call libc getuid — NestedJIT leaf owns that.
        $this->assertDoesNotMatchRegularExpression(
            '/function getuid\(Context \$context\): Value\s*\{[^}]*lookupFunction\(\'getuid\'\)/s',
            $source
        );
    }

    public function testPosixGetuidJitRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixGetuidJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('PosixGetuidJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitPosixGetuidKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_posix_getuid', $bridge);
        $this->assertStringNotContainsString("lookupFunction('getuid')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafOwnsLibcGetuid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosixGetuidKernel.php');
        $this->assertStringContainsString("lookupFunction('getuid')", $source);
        $this->assertStringContainsString('ensureLibcGetuid', $source);
    }

    public function testPosixGetuidJitHelperUsesHostPosixGetuidNotVmPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixGetuidJitHelper.php');
        $this->assertStringContainsString('@\\posix_getuid', $source);
        $this->assertStringNotContainsString('VmPosix::getuid', $source);

        $got = PosixGetuidJitHelper::getuidArgv();
        $this->assertIsInt($got);
        $this->assertGreaterThanOrEqual(0, $got);
        $this->assertSame(VmPosix::getuid(), $got);
        $this->assertSame((int) \posix_getuid(), $got);
    }

    public function testNestedJitAllowlistsPosixGetuidBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'posix_getuid'", $source);
        $this->assertStringContainsString('#30744', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesPosixGetuidArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PosixGetuidJitHelper.php', $spine);
        $this->assertStringContainsString('PosixGetuidJit.php', $spine);
        $this->assertStringContainsString('JitPosixGetuidKernel.php', $spine);
    }

    public function testPosixGetuidBuiltinRoutesThroughJitPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_getuid.php');
        $this->assertStringContainsString('JitPosix::getuid', $source);
        $this->assertStringContainsString('#30744', $source);
    }

    public function testTypeRegistersPosixGetuidBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PosixGetuidJit::ensureLinked', $source);
    }
}
