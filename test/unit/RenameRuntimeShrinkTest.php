<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\RenameJitHelper;
use PHPUnit\Framework\TestCase;

/** rename() JIT: RenameJitHelper → @rename leaf — no rename kernel (#29141). */
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
        $this->assertStringContainsString('invokeNestedLeaf', $source);
        $this->assertStringContainsString('__compiler_rename', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('JitRenameKernel', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementLibcBody', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\LibcExtern', $source);
        $this->assertStringNotContainsString('LibcExtern::', $source);
    }

    public function testRenameJitHelperUsesAtRenameLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/RenameJitHelper.php');
        $this->assertStringContainsString('public static function invokeArgv(string $from, string $to): int', $source);
        $this->assertStringContainsString('@\\rename', $source);
        $this->assertStringContainsString('VmUserStream::tryRename', $source);
        $this->assertStringContainsString('VmStatCache::invalidatePath', $source);
        $this->assertStringContainsString('return $ok ? 1 : 0', $source);
        $this->assertStringNotContainsString('VmFs::rename', $source);
        $this->assertStringNotContainsString('VmFsPathPure::rename', $source);

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
        @unlink($from);
        rmdir($dir);
    }

    public function testContextWhitelistsRenameNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertMatchesRegularExpression("/'rename'\\s*,/", $source);
        $this->assertStringNotContainsString("'phpc_rename_kernel'", $source);
    }

    public function testSpineBundleIncludesRenameHelperNotKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('RenameJitHelper.php', $spine);
        $this->assertStringContainsString('StringRename.php', $spine);
        $this->assertStringNotContainsString('phpc_rename_kernel.php', $spine);
        $this->assertStringNotContainsString('JitRenameKernel.php', $spine);
    }

    public function testPhpcRenameKernelFileDeleted(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist($root.'/ext/standard/JitRenameKernel.php');
        $this->assertFileDoesNotExist($root.'/ext/standard/phpc_rename_kernel.php');
    }
}
