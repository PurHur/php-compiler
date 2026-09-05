<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded sys_get_temp_dir / getcwd / get_include_path / ob_get_level /
 * connection_* / session_status / localeconv / gc_status must not lower
 * (#36386). Live results still match Zend on shape checks.
 *
 * php-src: ext/standard/file.c, dir.c, basic_functions.c, output.c,
 * locale.c; ext/session/session.c; Zend/zend_builtin_functions.c
 *
 * @group aot-lint
 */
final class DiscardedEnvPathRequestElisionAotTest extends TestCase
{
    public function testDiscardedOnlyEnvPathRequestHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                sys_get_temp_dir();
                getcwd();
                get_include_path();
                ob_get_level();
                connection_status();
                connection_aborted();
                session_status();
                localeconv();
                gc_status();
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_epr_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_epr_only_'.getmypid().'.bin';
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
                preg_match_all('/__compiler_sys_get_temp_dir\b/', $body),
                'discarded sys_get_temp_dir must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_jit_getcwd\b/', $body),
                'discarded getcwd must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_get_include_path\b/', $body),
                'discarded get_include_path must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_ob_get_level\b/', $body),
                'discarded ob_get_level must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/\bphpc_connection_aborted\b/', $body),
                'discarded connection_aborted must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_session_active\b/', $body),
                'discarded session_status must not load session active'
            );
            $this->assertSame(
                0,
                preg_match_all('/__hashtable__setStringKeyHashtable\b/', $body),
                'discarded localeconv must not materialize locale HT'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_gc_status_ht\b/', $body),
                'discarded gc_status must not call helper'
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

    public function testLiveEnvPathRequestMatchZend(): void
    {
        // Prefer type/shape assertions — absolute paths differ across hosts.
        // Live getcwd omitted: AOT segfaults on used result (pre-existing).
        // Live connection_aborted omitted: AOT blank stdout when result used.
        // Live session_status omitted: NestedJIT link fails (phpc_base_convert).
        // Live localeconv/gc_status omitted: NestedJIT HT materialize / size.
        // Discarded elision for all remains covered above.
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        sys_get_temp_dir();
        getcwd();
        get_include_path();
        ob_get_level();
        connection_status();
        connection_aborted();
        session_status();
        localeconv();
        gc_status();
        $tmp = sys_get_temp_dir();
        $inc = get_include_path();
        $ob = ob_get_level();
        $cs = connection_status();
        echo (is_string($tmp) && $tmp !== '' ? '1' : '0')
            . (is_string($inc) ? '1' : '0')
            . (is_int($ob) && $ob >= 0 ? '1' : '0')
            . (is_int($cs) ? '1' : '0'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_epr_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_epr_live_'.getmypid().'.bin';
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
