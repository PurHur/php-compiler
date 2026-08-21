<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\LinkJitHelper;
use PHPUnit\Framework\TestCase;

/** link() JIT: LinkJitHelper → @link leaf — insert restore + NestedJIT libc (#15544 / #33406). */
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
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringNotContainsString("lookupFunction('linkat')", $bridge);
    }

    /** #33406 — peer StringRename #29141 NestedJIT leaf + ensureBridge insert restore. */
    public function testStringLinkBridgeRestoresCallerInsertBlockAndNestedLeaf(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringLink.php');
        $this->assertStringContainsString('invokeNestedLeaf', $bridge);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $bridge);
        $this->assertStringContainsString('__compiler_link', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
    }

    public function testContextWhitelistsLinkNestedLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertMatchesRegularExpression("/'link'\\s*,/", $source);
    }

    public function testLinkJitHelperDelegatesToAtLinkLeaf(): void
    {
        if (!function_exists('link')) {
            $this->markTestSkipped('hard links unavailable on this platform');
        }
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/LinkJitHelper.php');
        $this->assertStringContainsString('public static function invokeArgv(string $target, string $link): int', $source);
        $this->assertStringContainsString('@\\link', $source);
        $this->assertStringContainsString('return $ok ? 1 : 0', $source);
        $this->assertStringNotContainsString('VmFs::hardLink', $source);

        $base = sys_get_temp_dir().'/phpc-link-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($base, 0700));
        $src = $base.'/src.txt';
        $dst = $base.'/hardlink.txt';
        file_put_contents($src, 'x');
        $this->assertSame(1, LinkJitHelper::invokeArgv($src, $dst));
        $this->assertFileExists($dst);
        $this->assertSame('x', file_get_contents($dst));
        unlink($dst);
        unlink($src);
        $this->assertSame(0, LinkJitHelper::invokeArgv($src, $dst));
        rmdir($base);
    }

    public function testSpineBundleIncludesLinkJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('LinkJitHelper.php', $spine);
        $this->assertStringContainsString('StringLink.php', $spine);
    }
}
