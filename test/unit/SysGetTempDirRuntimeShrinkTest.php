<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SysGetTempDirJitHelper;
use PHPCompiler\ext\standard\VmSysGetTempDirNative;
use PHPUnit\Framework\TestCase;

/**
 * sys_get_temp_dir() AOT via SysGetTempDirJitHelper PHP + NestedJIT getenv/realpath leaf (#29433).
 */
final class SysGetTempDirRuntimeShrinkTest extends TestCase
{
    public function testSysGetTempDirJitHelperUsesHostPeelNotVmNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SysGetTempDirJitHelper.php');
        $this->assertStringContainsString('public static function resolveJit(): string', $source);
        $this->assertStringContainsString('@\\sys_get_temp_dir', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*.*VmSysGetTempDirNative::/m',
            $source
        );
        $this->assertStringNotContainsString('VmSysGetTempDirPure', $source);
    }

    public function testVmSysGetTempDirNativeDelegatesToPureWithoutLibcFfi(): void
    {
        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSysGetTempDirNative.php');
        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmSysGetTempDirPure.php');
        $this->assertStringContainsString('VmSysGetTempDirPure::resolve', $native);
        $this->assertStringNotContainsString('FFI::cdef', $native);
        $this->assertStringNotContainsString('\\FFI', $native);
        $this->assertStringNotContainsString('FFI::cdef', $pure);
        $this->assertStringNotContainsString('\\FFI', $pure);
    }

    public function testSysGetTempDirRuntimeUsesHelperBridgeAndNestedLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/SysGetTempDirRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('SysGetTempDirJitHelper', $source);
        $this->assertStringContainsString('invokeNestedLeaf', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('__compiler_sys_get_temp_dir_leaf', $source);
        $this->assertStringContainsString('ensureNestedLeafBody', $source);
        $this->assertStringContainsString("lookupFunction('getenv')", $source);
        $this->assertStringContainsString("lookupFunction('realpath')", $source);
        $this->assertStringContainsString('#31534', $source);
        $this->assertStringContainsString('ensureLibc', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        // getenv/realpath only inside NestedJIT leaf body emit, not always-on main bridge.
        $this->assertMatchesRegularExpression(
            '/function ensureNestedLeafBody.*?lookupFunction\(\'getenv\'\)/s',
            $source
        );
    }

    public function testJitSysGetTempDirDelegatesToRuntimeInvoke(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSysGetTempDir.php');
        $this->assertStringContainsString('SysGetTempDirRuntime::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('__compiler_sys_get_temp_dir')", $source);
    }

    public function testContextWhitelistsSysGetTempDirNestedLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'sys_get_temp_dir'", $source);
        $this->assertStringContainsString('#29433', $source);
    }

    public function testSpineBundleIncludesSysGetTempDirHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SysGetTempDirJitHelper.php', $spine);
        $this->assertStringContainsString('SysGetTempDirRuntime.php', $spine);
    }

    public function testJitHelperMatchesHostTempDir(): void
    {
        $expected = (string) \sys_get_temp_dir();
        if ('' === $expected) {
            $this->markTestSkipped('host sys_get_temp_dir unavailable');
        }
        $this->assertSame($expected, SysGetTempDirJitHelper::resolveJit());
        $this->assertSame($expected, VmSysGetTempDirNative::resolve());
    }
}
