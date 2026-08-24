<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT DateTime New_ must not clobber a prior empty-array local (#34461).
 *
 * php-src: ext/date/php_date.c — DateTime::__construct; nested DateTimeImmutable in DatePeriod
 *
 * @group llvm
 * @group aot
 */
final class Issue34461DateTimeNewClobbersPriorArrayAotTest extends TestCase
{
    public function testConstructSyncSkipsEmptyArrayLocals(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('#34461', $source);
        $this->assertStringContainsString('prebindDateTimeNewAssignTarget', $source);
        $this->assertStringContainsString('compileTimeEmptyArrayLiteral', $source);
        $this->assertStringContainsString('valueBoxHashtable', $source);
        $this->assertStringContainsString('re-#27309', $source);
    }

    public function testAotPriorArrayThenDateTimeMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_34461_datetime_new_clobbers_prior_array.php';
        $bin = sys_get_temp_dir().'/phpc_dt_34461_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        $compileOut = [];
        $compileRc = 0;
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);

        $zendOut = [];
        $zendRc = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zendOut, $zendRc);
        $this->assertSame(0, $zendRc, implode("\n", $zendOut));
        $expect = implode("\n", $zendOut)."\n";

        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expect, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
