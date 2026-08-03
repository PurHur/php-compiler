<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for strtotime() absolute date (#27091).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(strtotime)
 * ABI: __compiler_strtotime(str*, i64 hasBase, i64 base, value*) — call site must not pass i1.
 *
 * @group llvm
 * @group aot
 */
final class StrtotimeAot27091Test extends TestCase
{
    public function testJitStrtotimeHasBaseIsI64NotI1(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/JitStrtotime.php');
        $this->assertStringContainsString('i64->constInt($hasBaseFlag ? 1 : 0, false)', $source);
        $this->assertStringNotContainsString('constantFromBool(2 === $argc', $source);
        $this->assertStringContainsString('tryFoldCompileTime', $source);
        $this->assertStringContainsString('VmDateTimeNative::strtotime', $source);
    }

    public function testAotStrtotimeAbsoluteDateExecute(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/aot_strtotime_absolute.php';
        $bin = sys_get_temp_dir().'/phpc_strtotime_27091_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 3; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame("2024-08-02\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($bin);
        }
    }
}
