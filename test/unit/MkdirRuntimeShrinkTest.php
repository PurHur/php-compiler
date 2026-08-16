<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MkdirJitHelper;
use PHPCompiler\ext\standard\VmFsDirNative;
use PHPUnit\Framework\TestCase;

/** mkdir() JIT routes through MkdirJitHelper PHP not JitMkdir warning LLVM (#15586). */
final class MkdirRuntimeShrinkTest extends TestCase
{
    public function testJitMkdirUsesPhpBridgeNotWarningLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitMkdir.php');
        $this->assertStringContainsString('StringMkdir::invoke', $source);
        $this->assertStringNotContainsString('BasicBlockHelper', $source);
        $this->assertStringNotContainsString('__compiler_mkdir', $source);
        $this->assertStringNotContainsString('StatPathRuntime', $source);
        $this->assertLessThan(25, \substr_count($source, "\n") + 1);
    }

    public function testStringMkdirBridgeUsesMkdirJitHelper(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMkdir.php');
        $this->assertStringContainsString('MkdirJitHelper', $bridge);
        $this->assertStringNotContainsString('__compiler_mkdir', $bridge);
    }

    public function testMkdirJitHelperDelegatesToVmFsDirNative(): void
    {
        $dir = sys_get_temp_dir().'/phpc-mkdir-'.bin2hex(random_bytes(4));
        @rmdir($dir);
        $this->assertTrue(MkdirJitHelper::invokeArgv($dir, 0700, false));
        $this->assertTrue(is_dir($dir));
        $this->assertSame(
            VmFsDirNative::mkdir($dir, 0700, false),
            MkdirJitHelper::invokeArgv($dir, 0700, false)
        );
        @rmdir($dir);
    }

    public function testSpineBundleIncludesMkdirJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('MkdirJitHelper.php', $spine);
        $this->assertStringContainsString('StringMkdir.php', $spine);
    }

    public function testSessionStorageDeclaresMkdirModuleLocally(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSessionStorageKernel.php');
        $this->assertStringContainsString('ensureLibcMkdir', $source);
        $this->assertStringContainsString('#31374', $source);
    }
}
