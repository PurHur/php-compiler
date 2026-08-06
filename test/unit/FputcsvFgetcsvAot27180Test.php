<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #27180 — AOT fputcsv/fgetcsv must build and match Zend under thin standalone.
 *
 * @group aot
 */
final class FputcsvFgetcsvAot27180Test extends TestCase
{
    public function testIssueReproAotMatchesZend(): void
    {
        $repro = dirname(__DIR__, 2).'/test/repro/issue_27180_fputcsv_fgetcsv_aot.php';
        $this->assertFileExists($repro);

        $php = escapeshellarg(PHP_BINARY);
        $reproQ = escapeshellarg($repro);
        $zend = [];
        $zendRc = 0;
        exec($php.' '.$reproQ.' 2>/dev/null', $zend, $zendRc);
        $this->assertSame(0, $zendRc, 'Zend repro failed');
        $want = implode("\n", $zend)."\n";

        $outBin = sys_get_temp_dir().'/issue_27180_aot_'.getmypid();
        @unlink($outBin);
        $compile = 'php '.escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php')
            .' -o '.escapeshellarg($outBin).' '.$reproQ.' 2>&1';
        $buildOut = [];
        $buildRc = 0;
        exec($compile, $buildOut, $buildRc);
        $this->assertSame(0, $buildRc, "AOT build failed:\n".implode("\n", $buildOut));
        $this->assertFileExists($outBin);

        $runs = [];
        for ($i = 0; $i < 5; ++$i) {
            $got = [];
            $runRc = 0;
            exec(escapeshellarg($outBin).' 2>&1', $got, $runRc);
            $this->assertSame(0, $runRc, "AOT run $i failed:\n".implode("\n", $got));
            $runs[] = implode("\n", $got)."\n";
        }
        @unlink($outBin);
        foreach ($runs as $i => $got) {
            $this->assertSame($want, $got, "AOT run $i stdout mismatch");
        }
    }
}
