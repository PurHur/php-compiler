<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded json_last_error* / preg_last_error* / date_default_timezone_get /
 * timezone_version_get / stream_get_{wrappers,transports,filters} /
 * cli_get_process_title must not lower (#36386). Live results still match Zend
 * on shape checks.
 *
 * php-src: ext/json/json.c, ext/pcre/php_pcre.c, ext/date/php_date.c,
 * ext/standard/streamsfuncs.c, ext/standard/cli_ops.c
 *
 * @group aot-lint
 */
final class DiscardedJsonPregTzStreamCliElisionAotTest extends TestCase
{
    public function testDiscardedOnlyJsonPregTzStreamCliHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                json_last_error();
                json_last_error_msg();
                preg_last_error();
                preg_last_error_msg();
                date_default_timezone_get();
                timezone_version_get();
                stream_get_wrappers();
                stream_get_transports();
                stream_get_filters();
                cli_get_process_title();
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_jptsc_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_jptsc_only_'.getmypid().'.bin';
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
                preg_match_all('/__compiler_json_last_error\b/', $body),
                'discarded json_last_error must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_json_last_error_msg\b/', $body),
                'discarded json_last_error_msg must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_preg_last_error\b/', $body),
                'discarded preg_last_error must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_preg_last_error_msg\b/', $body),
                'discarded preg_last_error_msg must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_default_timezone_get\b/', $body),
                'discarded date_default_timezone_get must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/phpc_cli_process_title_ptr\b/', $body),
                'discarded cli_get_process_title must not touch title global'
            );
            $this->assertSame(
                0,
                preg_match_all('/__value__writeHashtable\b/', $body),
                'discarded stream_get_* must not build hashtables'
            );
            $this->assertSame(
                0,
                preg_match_all('/__value__writeString\b/', $body),
                'discarded timezone_version_get / cli title must not writeString'
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

    public function testLiveJsonPregTzStreamCliMatchZend(): void
    {
        // Prefer type/shape assertions — tables and titles vary by build.
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        json_last_error();
        json_last_error_msg();
        preg_last_error();
        preg_last_error_msg();
        date_default_timezone_get();
        timezone_version_get();
        stream_get_wrappers();
        stream_get_transports();
        stream_get_filters();
        cli_get_process_title();
        $j = json_last_error();
        $jm = json_last_error_msg();
        $p = preg_last_error();
        $pm = preg_last_error_msg();
        $tz = date_default_timezone_get();
        $tv = timezone_version_get();
        $w = stream_get_wrappers();
        $t = stream_get_transports();
        $f = stream_get_filters();
        $cli = cli_get_process_title();
        echo (is_int($j) ? '1' : '0')
            . (is_string($jm) ? '1' : '0')
            . (is_int($p) ? '1' : '0')
            . (is_string($pm) ? '1' : '0')
            . (is_string($tz) && $tz !== '' ? '1' : '0')
            . (is_string($tv) && $tv !== '' ? '1' : '0')
            . (is_array($w) && count($w) > 0 ? '1' : '0')
            . (is_array($t) && count($t) > 0 ? '1' : '0')
            . (is_array($f) ? '1' : '0')
            . (is_string($cli) ? '1' : '0'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_jptsc_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_jptsc_live_'.getmypid().'.bin';
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
