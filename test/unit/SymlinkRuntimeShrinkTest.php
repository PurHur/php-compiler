<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SymlinkJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/** symlink() JIT routes through SymlinkJitHelper PHP not libc symlinkat LLVM (#15544). */
final class SymlinkRuntimeShrinkTest extends TestCase
{
    public function testJitSymlinkUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSymlink.php');
        $this->assertStringContainsString('StringSymlink::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('symlinkat')", $source);
    }

    public function testStringSymlinkBridgeUsesSymlinkJitHelper(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSymlink.php');
        $this->assertStringContainsString('SymlinkJitHelper', $bridge);
        $this->assertStringNotContainsString("lookupFunction('symlinkat')", $bridge);
    }

    public function testSymlinkJitHelperDelegatesToVmFs(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('symlinks unavailable on this platform');
        }
        $base = sys_get_temp_dir().'/phpc-symlink-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($base, 0700));
        $cwd = getcwd();
        chdir($base);
        $src = 'target.txt';
        $link = 'sym';
        file_put_contents($src, 'data');
        $this->assertTrue(SymlinkJitHelper::invokeArgv($src, $link));
        $this->assertTrue(is_link($link));
        unlink($link);
        unlink($src);
        $missing = 'missing-target.txt';
        $this->assertSame(VmFs::symlink($missing, $link), SymlinkJitHelper::invokeArgv($missing, $link));
        if (is_link($link)) {
            unlink($link);
        }
        chdir($cwd);
        foreach (scandir($base) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $base.'/'.$entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }
        rmdir($base);
    }

    public function testSpineBundleIncludesSymlinkJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SymlinkJitHelper.php', $spine);
        $this->assertStringContainsString('StringSymlink.php', $spine);
    }
}
