<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for date('Y', strtotime(...)) (#27121).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date) / PHP_FUNCTION(strtotime)
 *
 * @group llvm
 * @group aot
 */
final class DateStrtotimeAot27121Test extends TestCase
{
    public function testJitDateCivilLiteralCoversYearToken(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/JitDate.php');
        $this->assertStringContainsString("tryFormatCivilLiteral", $source);
        $this->assertStringContainsString("'Y' =>", $source);
        $this->assertStringContainsString('#27121', $source);
    }

    public function testAotDateStrtotimeYearExecute(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_27121_aot_date_strtotime.php';
        $bin = sys_get_temp_dir().'/phpc_date_strtotime_27121_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame("2020\n", implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
