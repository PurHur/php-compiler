<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: getdate/strtotime ArgumentCountError wording (#30714).
 *
 * php-src: ext/date/php_date.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30714GetdateStrtotimeArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30714_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30714_try_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
try {
    getdate(1, 2);
    echo "getdate NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'getdate ', $e->getMessage(), "\n";
}
try {
    strtotime('now', null, 1);
    echo "strtotime_hi NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'strtotime_hi ', $e->getMessage(), "\n";
}
try {
    strtotime();
    echo "strtotime_lo NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo 'strtotime_lo ', $e->getMessage(), "\n";
}
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
                    "getdate getdate() expects at most 1 argument, 2 given\n"
                    ."strtotime_hi strtotime() expects at most 2 arguments, 3 given\n"
                    ."strtotime_lo strtotime() expects at least 1 argument, 0 given\n",
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
