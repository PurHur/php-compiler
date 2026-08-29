<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: stat()/lstat() LLVM module verify — single-slot fail path (#35656).
 *
 * @see php-src ext/standard/filestat.c php_stat / php_lstat
 * @see ext/standard/JitStatArray.php
 *
 * @group llvm
 * @group aot
 */
final class StatLstatModuleVerify35656AotTest extends TestCase
{
    private const EXPECT = "true\ntrue";

    public function testVmStatLstatMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/aot_stat_lstat_module_verify.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'aot_stat_lstat_module_verify.php'));
        $out = rtrim((string) ob_get_clean(), "\n");
        $this->assertSame(self::EXPECT, $out);
    }

    public function testAotStatLstatCompilesAndMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_stat_lstat_module_verify.php';
        $bin = sys_get_temp_dir().'/phpc_issue_35656_stat_'.getmypid().'.bin';
        $compile = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(self::EXPECT, implode("\n", $runOut));
        } finally {
            @unlink($bin);
        }
    }

    public function testJitStatArrayUsesSingleResultSlot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitStatArray.php');
        $this->assertStringContainsString('Single slot for both arms', $source);
        $this->assertStringContainsString('invokeNestedJitLibcLeaf', $source);
        $this->assertStringNotContainsString('->phi(', $source);
    }

    public function testStatArrayJitHelperReturnsArrayNotHashTable(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/StatArrayJitHelper.php');
        $this->assertStringContainsString('Return type is `?array`', $source);
        $this->assertStringNotContainsString('new HashTable()', $source);
    }
}
