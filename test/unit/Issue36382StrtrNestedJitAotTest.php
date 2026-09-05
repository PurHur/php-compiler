<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * #36382 — AOT NestedJIT strtr two-string helper (Nyholm getHeadersFromServer).
 */
final class Issue36382StrtrNestedJitAotTest extends TestCase
{
    public function testStrtrRuntimeSubjectMatchesZend(): void
    {
        $src = realpath(__DIR__.'/../repro/issue_36382_strtr_nestedjit.php');
        $this->assertNotFalse($src);
        $bin = sys_get_temp_dir().'/issue_36382_strtr_nestedjit_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $out, $ec);
        $this->assertSame(0, $ec, implode("\n", $out));
        $this->assertFileExists($bin);

        $zend = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zend, $zec);
        $this->assertSame(0, $zec, implode("\n", $zend));

        for ($i = 0; $i < 3; ++$i) {
            $got = [];
            exec(escapeshellarg($bin).' 2>&1', $got, $aec);
            $this->assertSame(0, $aec, 'AOT run '.($i + 1).': '.implode("\n", $got));
            $this->assertSame($zend, $got, 'AOT run '.($i + 1).' must match Zend');
        }
        @unlink($bin);
    }
}
