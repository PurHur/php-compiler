<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * __compiler_readfile shrink guards (#9188, #19966, #20266, #20290).
 * Always-helper; libc leaf only from phpc_readfile_kernel.
 */
final class ReadfileRuntimeShrinkTest extends TestCase
{
    public function testStringReadfileAlwaysHelperNoThinFork(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringReadfile.php');
        $this->assertStringContainsString('ReadfileJitHelper', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $bridge);
        $this->assertStringContainsString('extractLongFromHelperResult', $bridge);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $bridge);
        $this->assertStringNotContainsString('implementLibcBody', $bridge);
        $this->assertStringNotContainsString('JitReadfileLibc', $bridge);
        $this->assertStringNotContainsString('JitReadfileKernel', $bridge);
        $this->assertStringNotContainsString('StringReadfileLibc', $bridge);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringReadfileLibc.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitReadfileKernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitReadfileLibc.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/phpc_readfile_kernel.php');
    }

    public function testReadfileJitHelperUsesPhpcReadfileKernel(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/ReadfileJitHelper.php');
        $this->assertMatchesRegularExpression('/return\s+\\\\phpc_readfile_kernel\s*\(/', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/phpc_readfile_kernel.php');
    }

    public function testReadfileJitHelperReturnsMinusOneWhenOpenFails(): void
    {
        if (!\function_exists('phpc_readfile_kernel')) {
            $this->markTestSkipped('phpc_readfile_kernel requires compiler runtime');
        }
        $this->assertSame(
            -1,
            \PHPCompiler\ext\standard\ReadfileJitHelper::readfile(
                sys_get_temp_dir().'/phpc-no-such-readfile-'.bin2hex(random_bytes(4))
            )
        );
    }

    public function testSpineBundleIncludesReadfilePhpPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ReadfileJitHelper.php', $spine);
        $this->assertStringContainsString('StringReadfile.php', $spine);
        $this->assertStringContainsString('phpc_readfile_kernel.php', $spine);
        $this->assertStringContainsString('JitReadfileLibc.php', $spine);
        $this->assertStringNotContainsString('JitReadfileKernel.php', $spine);
    }
}
