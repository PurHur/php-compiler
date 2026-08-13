<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: timezone_abbreviations_list excess argc → ArgumentCountError (#30681).
 *
 * php-src: ext/date/php_date.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30681TimezoneAbbreviationsListExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30681_ex_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30681_ex_'.getmypid().'.bin';
        file_put_contents($src, "<?php\ntimezone_abbreviations_list(1);\n");
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertNotSame(0, $runRc);
            $joined = implode("\n", $runOut);
            $this->assertStringContainsString(
                'timezone_abbreviations_list() expects exactly 0 arguments, 1 given',
                $joined
            );
            $this->assertStringContainsString('ArgumentCountError', $joined);
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30681_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30681_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    timezone_abbreviations_list(1);
    echo "excess NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
$r = timezone_abbreviations_list();
echo is_array($r) && count($r) > 0 ? "ok\n" : "bad\n";
PHP);
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
                $this->assertSame(0, $runRc, 'run '.$i.': '.implode("\n", $runOut));
                $this->assertSame(
                    "timezone_abbreviations_list() expects exactly 0 arguments, 1 given\nok\n",
                    implode("\n", $runOut)."\n",
                    'run '.$i
                );
            }
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
