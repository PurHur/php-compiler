<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * mb_strimwidth NestedJIT shrink guards (#3495 / #34264).
 *
 * @group llvm
 * @group aot
 */
final class MbStrimwidthRuntimeShrinkTest extends TestCase
{
    public function testHelperUsesStrimwidthArgvWithoutVmMbstring(): void
    {
        $helper = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/MbStrwidthJitHelper.php');
        $this->assertStringContainsString('function strimwidthArgv', $helper);
        $this->assertStringContainsString('EastAsianWidthTable::characterWidth', $helper);
        // strimwidthArgv body must not call VmMbstring (NestedJIT SIGSEGV / wrong); strwidth/strPad may.
        $start = \strpos($helper, 'function strimwidthArgv');
        $this->assertNotFalse($start);
        $chunk = \substr($helper, (int) $start, 3500);
        $this->assertStringNotContainsString('VmMbstring::', $chunk);
        $this->assertStringNotContainsString('private static function', $helper);
    }

    public function testLoweringUsesNestedHelperCoerce(): void
    {
        $jit = (string) \file_get_contents(__DIR__.'/../../ext/mbstring/JitMbStrwidth.php');
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $jit);
        $this->assertStringContainsString('extractStringPtrFromHelperResult', $jit);
        $builtin = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MbStrwidth.php');
        $this->assertStringContainsString('strimwidthArgv', $builtin);
        $this->assertStringContainsString('EastAsianWidthTable.php', $builtin);
        $this->assertStringContainsString('ensureCompiledBundle', $builtin);
    }

    public function testAotRuntimeIntMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(__DIR__.'/../repro/mb_strimwidth_runtime_int_aot.php');
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/mb_siw_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '
            .escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
