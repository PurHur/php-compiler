<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\FnmatchJitHelper;
use PHPCompiler\ext\standard\VmFnmatch;
use PHPUnit\Framework\TestCase;

/**
 * fnmatch() AOT via FnmatchJitHelper PHP + NestedJIT libc fnmatch(3) leaf (#30383).
 */
final class FnmatchRuntimeShrinkTest extends TestCase
{
    public function testJitFnmatchRoutesThroughStringFnmatch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFnmatch.php');
        $this->assertStringContainsString('StringFnmatch::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('fnmatch')", $source);
        $this->assertStringNotContainsString('LibcExtern', $source);
    }

    public function testStringFnmatchUsesHelperBridgeAndNestedLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringFnmatch.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('invokeNestedLeaf', $source);
        $this->assertStringContainsString('FnmatchJitHelper', $source);
        $this->assertStringContainsString('__compiler_fnmatch', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString('JitFnmatchKernel', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('LibcExtern::', $source);
        $this->assertLessThan(220, \substr_count($source, "\n") + 1);
    }

    public function testFnmatchJitHelperUsesHostFnmatchNotVmPure(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/FnmatchJitHelper.php');
        $this->assertStringContainsString('public static function invokeArgv(string $pattern, string $filename, int $flags = 0): int', $source);
        $this->assertStringContainsString('@\\fnmatch', $source);
        $this->assertStringNotContainsString('VmFnmatchPure::', $source);
        $this->assertStringNotContainsString('VmFnmatch::match', $source);

        $this->assertSame(1, FnmatchJitHelper::invokeArgv('foo*', 'foobar'));
        $this->assertSame(0, FnmatchJitHelper::invokeArgv('bar*', 'foobar'));
        $this->assertSame(
            1,
            FnmatchJitHelper::invokeArgv('FOO*', 'foobar', VmFnmatch::FNM_CASEFOLD)
        );
        $this->assertSame(
            VmFnmatch::match('a?c', 'abc') ? 1 : 0,
            FnmatchJitHelper::invokeArgv('a?c', 'abc')
        );
    }

    public function testModuleDropsAlwaysOnFnmatchDecl(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringNotContainsString(
            "lookupFunction('fnmatch')",
            $source,
            'Module jitInit must not always-declare libc fnmatch (#30383)'
        );
        $this->assertStringContainsString('#30383', $source);
    }

    public function testContextWhitelistsFnmatch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'fnmatch'", $source);
        $this->assertStringContainsString('#30383', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }

    public function testSpineBundleIncludesFnmatchArtifacts(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('FnmatchJitHelper.php', $spine);
        $this->assertStringContainsString('StringFnmatch.php', $spine);
        $this->assertStringContainsString('JitFnmatch.php', $spine);
    }

    public function testFnmatchPhpRoutesThroughJitFnmatch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/fnmatch.php');
        $this->assertStringContainsString('JitFnmatch::invoke', $source);
    }
}
