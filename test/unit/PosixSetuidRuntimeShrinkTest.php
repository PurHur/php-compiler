<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\posix\PosixSetuidJitHelper;
use PHPCompiler\ext\posix\VmPosix;
use PHPUnit\Framework\TestCase;

/**
 * posix_setuid() AOT via PosixSetuidJitHelper PHP + NestedJIT libc setuid(2) leaf (#31038).
 */
final class PosixSetuidRuntimeShrinkTest extends TestCase
{
    public function testJitPosixSetuidRoutesThroughPosixSetuidJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosix.php');
        $this->assertStringContainsString('PosixSetuidJit::invoke', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/function setuid\(Context \$context, (?:JITVariable \$arg|Value \$uidI64)\): Value\s*\{[^}]*setId\(/s',
            $source
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function setuid\(Context \$context, (?:JITVariable \$arg|Value \$uidI64)\): Value\s*\{[^}]*lookupFunction\(\'setuid\'\)/s',
            $source
        );
    }

    public function testPosixSetuidJitRoutesThroughJitVmHelperLink(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PosixSetuidJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('PosixSetuidJitHelper', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('JitPosixSetuidKernel::invoke', $bridge);
        $this->assertStringContainsString('__compiler_posix_setuid', $bridge);
        $this->assertStringNotContainsString("lookupFunction('setuid')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testNestedLeafOwnsLibcSetuid(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/JitPosixSetuidKernel.php');
        $this->assertStringContainsString("lookupFunction('setuid')", $source);
        $this->assertStringContainsString('ensureLibcSetuid', $source);
    }

    public function testPosixSetuidJitHelperUsesHostPosixSetuidNotVmPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/PosixSetuidJitHelper.php');
        $this->assertStringContainsString('@\\posix_setuid', $source);
        $this->assertStringNotContainsString('VmPosix::setuid', $source);
        $this->assertStringNotContainsString('IdentityWritePure', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);

        if (!\function_exists('posix_setuid') || !\function_exists('posix_getuid')) {
            $this->markTestSkipped('host posix_setuid unavailable');
        }
        $uid = (int) \posix_getuid();
        $got = PosixSetuidJitHelper::setuidArgv($uid);
        $this->assertSame(1, $got);
        $this->assertTrue(VmPosix::setuid($uid));
        $this->assertTrue((bool) \posix_setuid($uid));
    }

    public function testNestedJitAllowlistsPosixSetuidBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'posix_setuid'", $source);
        $this->assertStringContainsString('#31038', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesPosixSetuidArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('PosixSetuidJitHelper.php', $spine);
        $this->assertStringContainsString('PosixSetuidJit.php', $spine);
        $this->assertStringContainsString('JitPosixSetuidKernel.php', $spine);
    }

    public function testPosixSetuidBuiltinRoutesThroughJitPosix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/posix/posix_setuid.php');
        $this->assertStringContainsString('JitPosix::setuid', $source);
        $this->assertStringContainsString('JitLongArg::lower', $source);
        $this->assertStringContainsString('#31038', $source);
    }

    public function testTypeRegistersPosixSetuidBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('PosixSetuidJit::ensureLinked', $source);
    }
}
