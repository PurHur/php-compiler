<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\RmdirJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/** rmdir() JIT routes through RmdirJitHelper PHP not libc rmdir LLVM (#15481). */
final class RmdirRuntimeShrinkTest extends TestCase
{
    public function testJitRmdirUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRmdir.php');
        $this->assertStringContainsString('StringRmdir::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('rmdir')", $source);
    }

    public function testStringRmdirBridgeUsesRmdirJitHelper(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringRmdir.php');
        $this->assertStringContainsString('RmdirJitHelper', $bridge);
        $this->assertStringNotContainsString("lookupFunction('rmdir')", $bridge);
    }

    public function testRmdirJitHelperDelegatesToVmFs(): void
    {
        $dir = sys_get_temp_dir().'/phpc-rmdir-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0700));
        $this->assertTrue(RmdirJitHelper::invokeArgv($dir));
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertFalse(RmdirJitHelper::invokeArgv($dir));
        $this->assertSame(VmFs::rmdir($dir), RmdirJitHelper::invokeArgv($dir));
    }

    public function testSpineBundleIncludesRmdirJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('RmdirJitHelper.php', $spine);
        $this->assertStringContainsString('StringRmdir.php', $spine);
    }
}
