<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\ChdirJitHelper;
use PHPUnit\Framework\TestCase;

/** chdir() JIT: libc JitChdirKernel directly (#21147, #26928). */
final class ChdirRuntimeShrinkTest extends TestCase
{
    public function testJitChdirUsesPhpBridgeNotLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitChdir.php');
        $this->assertStringContainsString('StringChdir::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('chdir')", $source);
        $this->assertStringNotContainsString('LibcExtern', $source);
    }

    public function testStringChdirUsesLibcKernelNotNestedJit(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringChdir.php');
        $this->assertStringContainsString('JitChdirKernel::invoke', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString("lookupFunction('chdir')", $source);
        $this->assertStringNotContainsString('LibcExtern', $source);
    }

    public function testChdirJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/ChdirJitHelper.php');
        $this->assertStringContainsString('public static function invokeArgv(string $path): int', $source);
        $this->assertStringContainsString('phpc_chdir_kernel', $source);
        $this->assertMatchesRegularExpression(
            '/return\s+\\\\phpc_chdir_kernel\s*\(\s*\$path\s*\)\s*\?\s*1\s*:\s*0/',
            $source
        );
        $this->assertStringNotContainsString('TriggerErrorJitHelper', $source);

        if (!\function_exists('phpc_chdir_kernel')) {
            $this->markTestSkipped('phpc_chdir_kernel requires compiler runtime');
        }
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

    public function testSpineBundleIncludesChdirJitHelperAndKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ChdirJitHelper.php', $spine);
        $this->assertStringContainsString('StringChdir.php', $spine);
        $this->assertStringContainsString('JitChdirKernel.php', $spine);
        $this->assertStringContainsString('phpc_chdir_kernel.php', $spine);
    }
}
