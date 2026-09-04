<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded getmyinode / getlastmod / get_current_user / memory_get_* /
 * php_ini_* / gc_enabled must not lower (#36386). Live results still match Zend.
 *
 * php-src: ext/standard/basic_functions.c, Zend/zend_alloc.c, ext/standard/php_gc.c
 *
 * @group aot-lint
 */
final class DiscardedScriptMemoryIniElisionAotTest extends TestCase
{
    public function testDiscardedOnlyScriptMemoryIniHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops, bool $real): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                getmyinode();
                getlastmod();
                get_current_user();
                memory_get_usage();
                memory_get_usage($real);
                memory_get_peak_usage();
                memory_get_peak_usage($real);
                php_ini_loaded_file();
                php_ini_scanned_files();
                gc_enabled();
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8, false), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_smi_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_smi_only_'.getmypid().'.bin';
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
                preg_match_all('/__phpc_jit_stat_long_field/', $body),
                'discarded getmyinode/getlastmod must not call stat helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/\bgeteuid\b/', $body),
                'discarded get_current_user must not call geteuid'
            );
            $this->assertSame(
                0,
                preg_match_all('/\bgetpwuid\b/', $body),
                'discarded get_current_user must not call getpwuid'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_memory_get_usage/', $body),
                'discarded memory_get_usage must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_memory_get_peak_usage/', $body),
                'discarded memory_get_peak_usage must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_ini_introspection_loaded_file/', $body),
                'discarded php_ini_loaded_file must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_ini_introspection_scanned_files/', $body),
                'discarded php_ini_scanned_files must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/phpc_gc_is_enabled/', $body),
                'discarded gc_enabled must not call helper'
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

    public function testLiveScriptMemoryIniMatchZend(): void
    {
        // Prefer type/shape assertions — absolute inode/mtime/paths differ.
        // Skip live php_ini_* (NestedJIT IniIntrospection segfaults compile host)
        // and live gc_enabled paired with script-identity (blank stdout AOT bug).
        // Discarded elision for both remains covered above.
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        getmyinode();
        getlastmod();
        get_current_user();
        memory_get_usage();
        memory_get_peak_usage(false);
        $inode = getmyinode();
        $mtime = getlastmod();
        $user = get_current_user();
        $mem = memory_get_usage(false);
        $peak = memory_get_peak_usage();
        echo (is_int($inode) || false === $inode ? '1' : '0')
            . (is_int($mtime) || false === $mtime ? '1' : '0')
            . (is_string($user) ? '1' : '0')
            . (is_int($mem) && $mem >= 0 ? '1' : '0')
            . (is_int($peak) && $peak >= 0 ? '1' : '0'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_smi_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_smi_live_'.getmypid().'.bin';
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
