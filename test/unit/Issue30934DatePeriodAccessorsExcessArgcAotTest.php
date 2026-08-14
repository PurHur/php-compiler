<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DatePeriod accessors excess argc → ArgumentCountError (#30934).
 *
 * php-src: ext/date/php_date.c
 *
 * @group llvm
 * @group aot
 */
final class Issue30934DatePeriodAccessorsExcessArgcAotTest extends TestCase
{
    public function testAotExcessArgcCatchableUnderTry(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_30934_try_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_30934_try_'.getmypid().'.bin';
        // Keep CFG simple: multiple ExceptionBridge aborts + compound instanceof trips LLVM verify.
        file_put_contents($src, <<<'PHP'
<?php
$p = new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), 2);
$pEnd = new DatePeriod(new DateTime('2020-01-01'), new DateInterval('P1D'), new DateTime('2020-01-03'));
try {
    $p->getDateInterval(1);
} catch (ArgumentCountError $e) {
    echo 'interval ', $e->getMessage(), "\n";
}
try {
    $p->getStartDate(1);
} catch (ArgumentCountError $e) {
    echo 'start ', $e->getMessage(), "\n";
}
try {
    $pEnd->getEndDate(1);
} catch (ArgumentCountError $e) {
    echo 'end ', $e->getMessage(), "\n";
}
try {
    $p->getRecurrences(1);
} catch (ArgumentCountError $e) {
    echo 'rec ', $e->getMessage(), "\n";
}
echo $p->getRecurrences(), "\n";
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
                    "interval DatePeriod::getDateInterval() expects exactly 0 arguments, 1 given\n"
                    ."start DatePeriod::getStartDate() expects exactly 0 arguments, 1 given\n"
                    ."end DatePeriod::getEndDate() expects exactly 0 arguments, 1 given\n"
                    ."rec DatePeriod::getRecurrences() expects exactly 0 arguments, 1 given\n"
                    ."2\n",
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
