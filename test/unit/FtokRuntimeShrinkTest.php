<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FtokJitHelper;
use PHPCompiler\ext\standard\VmFtok;
use PHPUnit\Framework\TestCase;

/**
 * ftok() AOT emits libc stat + VmFtokPure layout in FtokRuntime (#9585, #27389).
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

    public function testFtokJitHelperDelegatesToVmFtok(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FtokJitHelper.php');
        $this->assertStringContainsString('VmFtok::invoke', $source);
    }

    public function testStringFsDirJitDelegatesFtokToRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFsDirJit.php');
        $this->assertStringContainsString('FtokRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('emitFtok', $source);
        $this->assertStringNotContainsString("lookupFunction('ftok')", $source);
    }

    public function testJitFtokLinksOnlyFtokRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFtok.php');
        $this->assertStringContainsString('FtokRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('StringFsDir::ensureLinked', $source);
    }

    public function testFtokRuntimeEmitsStatLayoutNotNestedJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FtokRuntime.php');
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringContainsString('#27389', $source);
        $this->assertStringContainsString('lookupFunction(\'stat\')', $source);
        $this->assertStringContainsString('0xFFFF', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('FtokJitHelper::ftokArgv', $source);
        $this->assertLessThan(220, \substr_count($source, "\n") + 1);
    }

    public function testFtokJitHelperMatchesVmFtok(): void
    {
        if (!VmFtok::available()) {
            $this->markTestSkipped('VmFtok stat backend unavailable on this host');
        }
        $path = tempnam(sys_get_temp_dir(), 'phpc_ftok_');
        $this->assertNotFalse($path);
        $this->assertSame(VmFtok::invoke($path, \ord('t')), FtokJitHelper::ftokArgv($path, \ord('t')));
        @unlink($path);
    }
}
