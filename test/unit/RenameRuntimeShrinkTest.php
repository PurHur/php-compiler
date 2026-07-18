<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\RenameJitHelper;
use PHPUnit\Framework\TestCase;

/** rename() JIT: always RenameJitHelper via JitVmHelperLink — no thin libc fork (#15533, #20603). */
final class RenameRuntimeShrinkTest extends TestCase
{
    public function testJitRenameUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitRename.php');
        $this->assertStringContainsString('StringRename::invoke', $source);
        $this->assertStringNotContainsString('invokeLibc', $source);
        $this->assertStringNotContainsString("lookupFunction('rename')", $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
    }

    public function testStringRenameAlwaysUsesHelperBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringRename.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('RenameJitHelper', $source);
        $this->assertStringContainsString('JitRenameKernel::invoke', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementLibcBody', $source);
        $this->assertStringNotContainsString('VmActiveContextInitLlvm', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('implementUserScriptKernel', $source);
        $this->assertStringNotContainsString("lookupFunction('rename')", $source);
        $this->assertStringNotContainsString('StatCacheRuntime', $source);
        $this->assertStringNotContainsString('LibcExtern', $source);
    }

    public function testStringRenameBridgeUsesRenameJitHelper(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringRename.php');
        $this->assertStringContainsString('RenameJitHelper', $bridge);
        $this->assertStringContainsString('INVOKE_HELPER', $bridge);
    }

    public function testRenameJitHelperDelegatesToKernel(): void
    {
        if (!\function_exists('phpc_rename_kernel')) {
            $this->markTestSkipped('phpc_rename_kernel requires compiler runtime');
        }
        $dir = sys_get_temp_dir().'/phpc-rename-'.bin2hex(random_bytes(4));
        $this->assertTrue(mkdir($dir, 0700));
        $from = $dir.'/from.txt';
        $to = $dir.'/to.txt';
        $this->assertNotFalse(file_put_contents($from, 'x'));
        $this->assertSame(1, RenameJitHelper::invokeArgv($from, $to));
        $this->assertFileDoesNotExist($from);
        $this->assertFileExists($to);
        $this->assertSame(0, RenameJitHelper::invokeArgv($from, $to));
        $this->assertSame(1, RenameJitHelper::invokeArgv($to, $from));
        $this->assertFileExists($from);
        unlink($from);
        rmdir($dir);
    }

    public function testRenameJitHelperReturnsIntNotBool(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/RenameJitHelper.php');
        $this->assertStringContainsString('public static function invokeArgv(string $from, string $to): int', $source);
        $this->assertStringContainsString('return $ok ? 1 : 0', $source);
        $this->assertStringContainsString('phpc_rename_kernel', $source);
        $this->assertStringNotContainsString('str_contains', $source);
    }

    public function testSpineBundleIncludesRenameJitHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('RenameJitHelper.php', $spine);
        $this->assertStringContainsString('StringRename.php', $spine);
        $this->assertStringContainsString('JitRenameKernel.php', $spine);
    }
}
