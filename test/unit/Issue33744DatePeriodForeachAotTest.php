<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT DatePeriod foreach snapshot uses DateTime timestamp field (#33744, re-#26937).
 *
 * php-src: ext/date/php_date.c — date_period_construct
 *
 * @group llvm
 * @group aot
 */
final class Issue33744DatePeriodForeachAotTest extends TestCase
{
    public function testSnapshotReadsDateTimeTimestampNotCompileTimeLong(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/JitDatePeriodConstruct.php'
        );
        $this->assertStringContainsString('compileTimeDateTimeTimestamp', $source);
        $this->assertStringContainsString('compileTimeTimezoneName', $source);
        $this->assertStringNotContainsString(
            '$startVar->compileTimeLong',
            $source
        );
        $this->assertStringNotContainsString(
            '$endVar->compileTimeLong',
            $source
        );
    }

    public function testDateTimeConstructSyncDoesNotClobberOtherDateTimeLocals(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('#33744', $source);
        $this->assertStringNotContainsString(
            "\$bound === \$first || \$className === (\$bound->classUserType ?? '')",
            $source
        );
    }

    public function testDateTimeConstructSyncEmptyHintSkipsNonObjectBindings(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('#34461', $source);
        $sync = strpos($source, 'private function syncDateTimeConstructMetaToAliases');
        $this->assertNotFalse($sync);
        $next = strpos($source, 'private function syncDateIntervalConstructMetaToAliases', $sync + 1);
        $chunk = false === $next
            ? substr($source, $sync, 8000)
            : substr($source, $sync, $next - $sync);
        $this->assertStringContainsString(
            'Variable::TYPE_OBJECT !== $bound->type',
            $chunk,
            'empty-hint New_ publish must skip hashtable locals like $out = [] (#34461)'
        );
    }

    public function testForeachResetPrefersDatePeriodSnapshotOverObjectProperties(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/VmIteratorForeach.php'
        );
        $dp = strpos($source, 'DatePeriodForeachSnapshot::canLower');
        $obj = strpos($source, 'ObjectPropertyForeachHelper::canLower');
        $this->assertNotFalse($dp);
        $this->assertNotFalse($obj);
        $this->assertLessThan($obj, $dp, 'DatePeriod snapshot must compileReset before object-property foreach (#33744)');
    }

    public function testObjectPropertyForeachSkipsDatePeriod(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/VM/VmObjectPropertyForeach.php'
        );
        $this->assertStringContainsString("'dateperiod' === \$classLc", $source);
    }

    public function testAotForeachMatchesZend(): void
    {
        if (!\PHPCompiler\LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_33744_dateperiod_foreach_aot.php';
        $bin = sys_get_temp_dir().'/phpc_dp_33744_'.getmypid().'.bin';
        $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $expect = "2020-01-01,2020-01-02,2020-01-03,2020-01-04\n"
            ."2020-01-01 2020-01-02 2020-01-03\n"
            ."2020-01-01,2020-01-02,2020-01-03,2020-01-04\n";
        try {
            for ($i = 0; $i < 10; ++$i) {
                $runOut = [];
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $this->assertSame($expect, implode("\n", $runOut)."\n", 'run '.($i + 1));
            }
        } finally {
            @unlink($bin);
        }
    }
}
