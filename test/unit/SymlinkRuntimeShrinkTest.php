<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\SymlinkJitHelper;
use PHPUnit\Framework\TestCase;

/** symlink() JIT: SymlinkJitHelper → @symlink leaf — NestedJIT libc (#15544 / #33417). */
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
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString("lookupFunction('symlinkat')", $bridge);
    }

    /** #33417 — peer StringLink #33406 NestedJIT leaf + ensureBridge. */
    public function testStringSymlinkBridgeRestoresCallerInsertBlockAndNestedLeaf(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringSymlink.php');
        $this->assertStringContainsString('invokeNestedLeaf', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('__compiler_symlink', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
    }

    public function testContextWhitelistsSymlinkNestedLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertMatchesRegularExpression("/'symlink'\\s*,/", $source);
    }

    public function testSymlinkJitHelperDelegatesToAtSymlinkLeaf(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('symlinks unavailable on this platform');
        }
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/SymlinkJitHelper.php');
        $this->assertStringContainsString('public static function invokeArgv(string $target, string $link): int', $source);
        $this->assertStringContainsString('@\\symlink', $source);
        $this->assertStringContainsString('return $ok ? 1 : 0', $source);
        $this->assertStringNotContainsString('VmFs::symlink', $source);

        $base = sys_get_temp_dir().'/phpc-symlink-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($base, 0700));
        $cwd = getcwd();
        chdir($base);
        $src = 'target.txt';
        $link = 'sym';
        file_put_contents($src, 'data');
        $this->assertSame(1, SymlinkJitHelper::invokeArgv($src, $link));
        $this->assertTrue(is_link($link));
        $this->assertSame('data', file_get_contents($link));
        unlink($link);
        unlink($src);
        // Dangling symlink to a missing target succeeds on Linux; fail via bad parent dir.
        $this->assertSame(0, SymlinkJitHelper::invokeArgv($src, 'no/such/dir/sym'));
        // Embedded NUL rejected without touching the filesystem.
        $this->assertSame(0, SymlinkJitHelper::invokeArgv("a\0b", $link));
        chdir($cwd);
        rmdir($base);
    }

    public function testSpineBundleIncludesSymlinkJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('SymlinkJitHelper.php', $spine);
        $this->assertStringContainsString('StringSymlink.php', $spine);
    }
}
