<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ChdirJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * chdir() JIT: ChdirJitHelper + StringChdir NestedJIT leaf (#21147, #29219).
 */
final class ChdirRuntimeShrinkTest extends TestCase
{
    public function testJitChdirUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitChdir.php');
        $this->assertStringContainsString('StringChdir::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('chdir')", $source);
        $this->assertStringNotContainsString('LibcExtern', $source);
    }

    public function testStringChdirUsesHelperBridgeAndNestedLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringChdir.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('invokeNestedLeaf', $source);
        $this->assertStringContainsString('ChdirJitHelper', $source);
        $this->assertStringContainsString('__compiler_chdir', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('JitChdirKernel', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('LibcExtern::', $source);
    }

    public function testChdirJitHelperUsesHostChdirNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ChdirJitHelper.php');
        $this->assertStringContainsString('public static function invokeArgv(string $path): int', $source);
        $this->assertStringContainsString('@\\chdir', $source);
        $this->assertStringNotContainsString('phpc_chdir_kernel', $source);
        $this->assertStringNotContainsString('TriggerErrorJitHelper', $source);
        $this->assertStringNotContainsString('VmFs::', $source);

        if (!\function_exists('chdir') || !\function_exists('getcwd')) {
            $this->markTestSkipped('host chdir/getcwd unavailable');
        }
        $orig = getcwd();
        $this->assertNotFalse($orig);
        $target = sys_get_temp_dir().'/phpc_chdir_helper_'.getmypid().'_'.bin2hex(random_bytes(3));
        $this->assertTrue(mkdir($target, 0700));
        try {
            $this->assertSame(1, ChdirJitHelper::invokeArgv($target));
            $here = getcwd();
            $this->assertNotFalse($here);
            $this->assertSame(realpath($target), realpath($here));
            $this->assertSame(1, ChdirJitHelper::invokeArgv($orig));
            $this->assertSame(0, ChdirJitHelper::invokeArgv($target.'/missing-21147'));
        } finally {
            @chdir($orig);
            @rmdir($target);
        }
    }

    public function testLibcExternDropsChdirDecl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/LibcExtern.php');
        $this->assertStringNotContainsString(
            "'chdir' =>",
            $source,
            'LibcExtern must not declare libc chdir (#29219)'
        );
        $this->assertStringContainsString('#29219', $source);
    }

    public function testKernelFilesDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/phpc_chdir_kernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitChdirKernel.php');
    }

    public function testSpineBundleIncludesChdirHelperNotKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ChdirJitHelper.php', $spine);
        $this->assertStringContainsString('StringChdir.php', $spine);
        $this->assertStringNotContainsString('phpc_chdir_kernel.php', $spine);
        $this->assertStringNotContainsString('JitChdirKernel.php', $spine);
    }

    public function testContextWhitelistsChdirNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'chdir'", $source);
        $this->assertStringNotContainsString("'phpc_chdir_kernel'", $source);
        $this->assertStringContainsString('#29219', $source);
    }
}
