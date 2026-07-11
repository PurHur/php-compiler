<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FtokJitHelper;
use PHPCompiler\ext\standard\VmFtok;
use PHPUnit\Framework\TestCase;

/** ftok() JIT routes through FtokJitHelper PHP not StringFsDirJit LLVM (#9585). */
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

    public function testFtokRuntimeUsesJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/FtokRuntime.php');
        $this->assertStringContainsString('FtokJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertLessThan(200, \substr_count($source, "\n") + 1);
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
