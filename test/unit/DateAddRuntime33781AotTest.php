<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: procedural date_add() with runtime DateInterval (P1M) matches Zend (#33781 leftover).
 *
 * @see ext/date/php_date.c php_date_add
 *
 * @group llvm
 * @group aot
 */
final class DateAddRuntime33781AotTest extends TestCase
{
    public function testVmMatchesZend(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(dirname(__DIR__).'/repro/issue_33781_date_add_runtime.php');
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'issue_33781_date_add_runtime.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("2020-02-15\n", $out);
    }

    public function testAotMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33781_date_add_runtime.php';
        $bin = sys_get_temp_dir().'/phpc_issue_33781_date_add_'.getmypid().'.bin';
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
            $this->assertSame("2020-02-15\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($bin);
        }
    }
}
