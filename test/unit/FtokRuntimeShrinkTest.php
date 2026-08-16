<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FtokJitHelper;
use PHPCompiler\ext\standard\VmFtok;
use PHPUnit\Framework\TestCase;

/**
 * ftok() AOT via FtokJitHelper PHP + NestedJIT libc ftok(3) leaf (#31478).
 */
final class FtokRuntimeShrinkTest extends TestCase
{
    public function testVmFtokUsesPureBackendWithoutLibcFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFtok.php');
        $this->assertStringContainsString('VmFtokPure::invoke', $source);
        $this->assertStringNotContainsString('\\FFI', $source);
        $this->assertStringNotContainsString('$ffi->ftok', $source);

        $pure = (string) file_get_contents(__DIR__.'/../../ext/standard/VmFtokPure.php');
        $this->assertStringContainsString('VmStatNative::stat', $pure);
        $this->assertStringNotContainsString('\\FFI', $pure);
    }

    public function testFtokJitHelperUsesHostFtokNotVmFtok(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FtokJitHelper.php');
        $this->assertStringContainsString('@\\ftok', $source);
        $this->assertStringNotContainsString('VmFtok::invoke', $source);
        $this->assertStringNotContainsString('VmFtokPure', $source);
    }

    public function testStringFsDirJitDelegatesFtokToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFsDirJit.php');
        $this->assertStringContainsString('FtokRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('emitFtok', $source);
        $this->assertStringNotContainsString("lookupFunction('ftok')", $source);
    }

    public function testJitFtokRoutesThroughFtokRuntimeInvoke(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFtok.php');
        $this->assertStringContainsString('FtokRuntime::invoke', $source);
        $this->assertStringNotContainsString('StringFsDir::ensureLinked', $source);
        $this->assertStringNotContainsString("lookupFunction('__compiler_ftok')", $source);
    }

    public function testFtokRuntimeRoutesThroughJitVmHelperLink(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FtokRuntime.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('FtokJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('JitFtokKernel::invoke', $source);
        $this->assertStringContainsString('__compiler_ftok', $source);
        $this->assertStringContainsString('#31478', $source);
        $this->assertStringNotContainsString('ensureLibcStat', $source);
        $this->assertStringNotContainsString('implementFtokBridge', $source);
        $this->assertStringNotContainsString('0xFFFF', $source);
        $this->assertStringNotContainsString("lookupFunction('stat')", $source);
        $this->assertLessThan(120, \substr_count($source, "\n") + 1);
    }

    public function testNestedLeafUsesLibcFtok(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFtokKernel.php');
        $this->assertStringContainsString("lookupFunction('ftok')", $source);
        $this->assertStringContainsString('ensureLibcFtok', $source);
    }

    public function testFtokJitHelperMatchesVmFtokAndHost(): void
    {
        if (!\function_exists('ftok') || !VmFtok::available()) {
            $this->markTestSkipped('ftok/VmFtok unavailable on this host');
        }
        $path = tempnam(sys_get_temp_dir(), 'phpc_ftok_');
        $this->assertNotFalse($path);
        try {
            $got = FtokJitHelper::ftokArgv($path, \ord('t'));
            $this->assertIsInt($got);
            $this->assertNotSame(-1, $got);
            $this->assertSame(VmFtok::invoke($path, \ord('t')), $got);
            $this->assertSame(\ftok($path, 't'), $got);
        } finally {
            @unlink($path);
        }
    }

    public function testNestedJitAllowlistsFtokBuiltin(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'ftok'", $source);
        $this->assertStringContainsString('#31478', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesFtokArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FtokJitHelper.php', $spine);
        $this->assertStringContainsString('JitFtokKernel.php', $spine);
        $this->assertStringContainsString('FtokRuntime.php', $spine);
    }

    public function testTypeRegistersFtokBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type.php');
        $this->assertStringContainsString('FtokRuntime::ensureLinked', $source);
    }
}
