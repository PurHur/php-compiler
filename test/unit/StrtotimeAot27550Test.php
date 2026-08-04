<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Thin AOT execute guard for date_default_timezone_set + strtotime (#27550).
 *
 * Root cause: DefaultTimezoneRuntime cleared the caller insert block after bridge
 * emit (peer #27088 / #27389). Alone strtotime/#27091 stayed green.
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(strtotime) / date_default_timezone_set
 *
 * @group llvm
 * @group aot
 */
final class StrtotimeAot27550Test extends TestCase
{
    public function testDefaultTimezoneRuntimeRestoresInsertBlock(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Builtin/DefaultTimezoneRuntime.php');
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringContainsString('#27550', $source);
    }

    public function testAotStrtotimeAfterTimezoneSetExecute(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_27550_aot_strtotime_after_timezone_set.php';
        $bin = sys_get_temp_dir().'/phpc_strtotime_27550_'.getmypid().'.bin';
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
                $this->assertSame("1579046400\n", implode("\n", $runOut)."\n");
            }
        } finally {
            @unlink($bin);
        }
    }
}
