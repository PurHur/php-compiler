<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixGeteuidJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPUnit\Framework\TestCase;

/**
 * posix_geteuid() AOT via PosixGeteuidJitHelper PHP + NestedJIT libc geteuid(2) leaf (#30767).
 */
final class PosixGeteuidRuntimeShrinkTest extends TestCase
{
    public function testJitPosixGeteuidRoutesThroughPosixGeteuidJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixGeteuidJit::invoke', $source);
        $this->assertStringNotContainsString('ensureLibcEuid', $source);
        // geteuid user path must not declare/call libc geteuid — NestedJIT leaf owns that.
        $this->assertDoesNotMatchRegularExpression(
            '/function geteuid\(Context \$context\): Value\s*\{[^}]*lookupFunction\(\'geteuid\'\)/s',
            $source
        );
    }

    public function testPosixGeteuidJitRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixGeteuidJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('PosixGeteuidJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitPosixGeteuidKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_posix_geteuid', $bridge);
        $this->assertStringNotContainsString("lookupFunction('geteuid')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafOwnsLibcGeteuid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosixGeteuidKernel.php');
        $this->assertStringContainsString("lookupFunction('geteuid')", $source);
        $this->assertStringContainsString('ensureLibcGeteuid', $source);
    }

    public function testPosixGeteuidJitHelperUsesHostPosixGeteuidNotVmPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixGeteuidJitHelper.php');
        $this->assertStringContainsString('@\\posix_geteuid', $source);
        $this->assertStringNotContainsString('VmPosix::geteuid', $source);

        $got = PosixGeteuidJitHelper::geteuidArgv();
        $this->assertIsInt($got);
        $this->assertGreaterThanOrEqual(0, $got);
        $this->assertSame(VmPosix::geteuid(), $got);
        $this->assertSame((int) \posix_geteuid(), $got);
    }

    public function testNestedJitAllowlistsPosixGeteuidBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'posix_geteuid'", $source);
        $this->assertStringContainsString('#30767', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesPosixGeteuidArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PosixGeteuidJitHelper.php', $spine);
        $this->assertStringContainsString('PosixGeteuidJit.php', $spine);
        $this->assertStringContainsString('JitPosixGeteuidKernel.php', $spine);
    }

    public function testPosixGeteuidBuiltinRoutesThroughJitPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_geteuid.php');
        $this->assertStringContainsString('JitPosix::geteuid', $source);
        $this->assertStringContainsString('#30767', $source);
    }

    public function testTypeRegistersPosixGeteuidBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PosixGeteuidJit::ensureLinked', $source);
    }
}
