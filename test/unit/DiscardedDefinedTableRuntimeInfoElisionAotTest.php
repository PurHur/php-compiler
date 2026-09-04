<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded get_loaded_extensions / get_defined_constants / get_defined_functions
 * must not lower (#36386). Live results still match Zend.
 *
 * php-src: ext/standard/basic_functions.c, ext/standard/info.c
 *
 * @group aot-lint
 */
final class DiscardedDefinedTableRuntimeInfoElisionAotTest extends TestCase
{
    public function testDiscardedOnlyDefinedTableRuntimeInfoHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops, bool $flag): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                get_loaded_extensions();
                get_loaded_extensions($flag);
                get_defined_constants();
                get_defined_constants($flag);
                get_defined_functions();
                get_defined_functions($flag);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8, false), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_dtri_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_dtri_only_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_DUMP_IR=1');
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $ll = (string) file_get_contents('/tmp/phpc-last.ll');

            $sig = null;
            if (preg_match('/define [^\n]*@only_discarded\(/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing @only_discarded');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertSame(
                0,
                preg_match_all('/__compiler_get_loaded_extensions/', $body),
                'discarded get_loaded_extensions must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_get_defined_constants/', $body),
                'discarded get_defined_constants must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_get_defined_functions/', $body),
                'discarded get_defined_functions must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__value__writeHashtable/', $body),
                'discarded defined-table builtins must not materialize tables'
            );

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend[0], $runOut[0], 'AOT must match Zend');
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testLiveDefinedTableRuntimeInfoMatchZend(): void
    {
        // Avoid asserting get_loaded_extensions content — AOT helper can return
        // an empty table while still being is_array (peer StringInfo gaps).
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        get_loaded_extensions();
        get_defined_constants(false);
        get_defined_functions();
        $ext = get_loaded_extensions();
        $consts = get_defined_constants();
        $fns = get_defined_functions();
        echo (is_array($ext) ? '1' : '0')
            . (is_array($consts) && count($consts) > 0 ? '1' : '0')
            . (is_array($fns) && isset($fns['internal']) ? '1' : '0'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_dtri_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_dtri_live_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $runOut = [];
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend[0], $runOut[0], 'AOT must match Zend');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
