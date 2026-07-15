<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\RenameJitHelper;
use PHPUnit\Framework\TestCase;

/** rename() JIT routes through RenameJitHelper PHP not libc rename LLVM (#15533). */
final class RenameRuntimeShrinkTest extends TestCase
{
    public function testJitRenameUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRename.php');
        $this->assertStringContainsString('StringRename::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('rename')", $source);
    }

    public function testStringRenameBridgeUsesRenameJitHelper(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringRename.php');
        $this->assertStringContainsString('RenameJitHelper', $bridge);
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $bridge);
        $this->assertStringNotContainsString("lookupFunction('rename')", $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
    }

    public function testRenameJitHelperDelegatesToVmFs(): void
    {
        $dir = sys_get_temp_dir().'/phpc-rename-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0700));
        $from = $dir.'/from.txt';
        $to = $dir.'/to.txt';
        $this->assertNotFalse(file_put_contents($from, 'x'));
        $this->assertTrue(RenameJitHelper::invokeArgv($from, $to));
        $this->assertFileDoesNotExist($from);
        $this->assertFileExists($to);
        $this->assertFalse(RenameJitHelper::invokeArgv($from, $to));
        $this->assertTrue(RenameJitHelper::invokeArgv($to, $from));
        $this->assertFileExists($from);
        unlink($from);
        rmdir($dir);
    }

    public function testSpineBundleIncludesRenameJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('RenameJitHelper.php', $spine);
        $this->assertStringContainsString('StringRename.php', $spine);
    }
}
