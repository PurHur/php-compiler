<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ReadlinkJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/**
 * readlink() AOT success uses libc readlink(2) (#742 / #33289) — NestedJIT host
 * `\readlink` returns false under thin standalone (peer JitStatKernel #27013).
 */
final class ReadlinkRuntimeShrinkTest extends TestCase
{
    public function testJitReadlinkUsesLibcLeafForThinAot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitReadlink.php');
        $this->assertStringContainsString("lookupFunction('readlink')", $source);
        $this->assertStringContainsString('BUF_SIZE', $source);
        $this->assertStringContainsString('#33289', $source);
        $this->assertStringNotContainsString('StringReadlink::invoke', $source);
    }

    public function testStringReadlinkBridgeStillPresentForSpine(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringReadlink.php');
        $this->assertStringContainsString('ReadlinkJitHelper', $bridge);
    }

    public function testReadlinkJitHelperDelegatesToVmFs(): void
    {
        $dir = sys_get_temp_dir().'/phpc-rl-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir));
        $target = $dir.'/target.txt';
        $this->assertNotFalse(file_put_contents($target, 'ok'));
        $link = $dir.'/link.txt';
        $this->assertTrue(symlink($target, $link));
        $this->assertSame(VmFs::readlink($link), ReadlinkJitHelper::resolveArgv($link));
        $this->assertNull(ReadlinkJitHelper::resolveArgv($dir.'/missing-15353'));
        unlink($link);
        unlink($target);
        rmdir($dir);
    }

    public function testSpineBundleIncludesReadlinkJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ReadlinkJitHelper.php', $spine);
        $this->assertStringContainsString('StringReadlink.php', $spine);
    }
}
