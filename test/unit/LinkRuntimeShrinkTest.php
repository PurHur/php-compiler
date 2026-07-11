<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\LinkJitHelper;
use PHPCompiler\ext\standard\VmFs;
use PHPUnit\Framework\TestCase;

/** link() JIT routes through LinkJitHelper PHP not libc linkat LLVM (#15544). */
final class LinkRuntimeShrinkTest extends TestCase
{
    public function testJitLinkUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitLink.php');
        $this->assertStringContainsString('StringLink::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('linkat')", $source);
    }

    public function testStringLinkBridgeUsesLinkJitHelper(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringLink.php');
        $this->assertStringContainsString('LinkJitHelper', $bridge);
        $this->assertStringNotContainsString("lookupFunction('linkat')", $bridge);
    }

    public function testLinkJitHelperDelegatesToVmFs(): void
    {
        if (!function_exists('link')) {
            $this->markTestSkipped('hard links unavailable on this platform');
        }
        $base = sys_get_temp_dir().'/phpc-link-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($base, 0700));
        $src = $base.'/src.txt';
        $dst = $base.'/hardlink.txt';
        file_put_contents($src, 'x');
        $this->assertTrue(LinkJitHelper::invokeArgv($src, $dst));
        $this->assertFileExists($dst);
        $this->assertSame('x', file_get_contents($dst));
        unlink($dst);
        unlink($src);
        $this->assertFalse(LinkJitHelper::invokeArgv($src, $dst));
        $this->assertSame(VmFs::hardLink($src, $dst), LinkJitHelper::invokeArgv($src, $dst));
        rmdir($base);
    }

    public function testSpineBundleIncludesLinkJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('LinkJitHelper.php', $spine);
        $this->assertStringContainsString('StringLink.php', $spine);
    }
}
