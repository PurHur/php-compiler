<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixSetsidJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPUnit\Framework\TestCase;

/**
 * posix_setsid() AOT via PosixSetsidJitHelper PHP + NestedJIT libc setsid(2) leaf (#31235).
 */
final class PosixSetsidRuntimeShrinkTest extends TestCase
{
    public function testJitPosixSetsidRoutesThroughPosixSetsidJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixSetsidJit::invoke', $source);
        $this->assertStringNotContainsString('ensureLibcSetsid', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function setsid\(Context \$context\): Value\s*\{[^}]*lookupFunction\(\'setsid\'\)/s',
            $source
        );
    }

    public function testPosixSetsidJitRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixSetsidJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('PosixSetsidJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitPosixSetsidKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_posix_setsid', $bridge);
        $this->assertStringNotContainsString("lookupFunction('setsid')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafOwnsLibcSetsid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosixSetsidKernel.php');
        $this->assertStringContainsString("lookupFunction('setsid')", $source);
        $this->assertStringContainsString('ensureLibcSetsid', $source);
    }

    public function testPosixSetsidJitHelperUsesHostPosixSetsidNotVmPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixSetsidJitHelper.php');
        $this->assertStringContainsString('@\\posix_setsid', $source);
        $this->assertStringNotContainsString('VmPosix::setsid', $source);
        $this->assertStringNotContainsString('IdentityWritePure', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);

        if (!\function_exists('posix_setsid')) {
            $this->markTestSkipped('host posix_setsid unavailable');
        }
        // Calling setsid in a process that is already a session leader fails; helper
        // still returns int (false → -1). Do not require success here.
        $got = PosixSetsidJitHelper::setsidArgv();
        $this->assertIsInt($got);
        $this->assertIsInt(VmPosix::setsid());
    }

    public function testNestedJitAllowlistsPosixSetsidBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'posix_setsid'", $source);
        $this->assertStringContainsString('#31235', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesPosixSetsidArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PosixSetsidJitHelper.php', $spine);
        $this->assertStringContainsString('PosixSetsidJit.php', $spine);
        $this->assertStringContainsString('JitPosixSetsidKernel.php', $spine);
    }

    public function testPosixSetsidBuiltinRoutesThroughJitPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_setsid.php');
        $this->assertStringContainsString('JitPosix::setsid', $source);
        $this->assertStringContainsString('#31235', $source);
    }

    public function testTypeRegistersPosixSetsidBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PosixSetsidJit::ensureLinked', $source);
    }
}
