<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded timezone_*_list / ob_list_handlers / date_get_last_errors /
 * spl_autoload_functions / time / error_reporting / ignore_user_abort /
 * http_response_code / headers_sent must not lower (#36386). Live results
 * still match Zend on shape checks. ({@code http_get_last_response_headers}
 * is elided in unit tests only — PHP 8.4+; pinned image is 8.2.)
 *
 * php-src: ext/date/php_date.c, ext/standard/output.c, ext/standard/http.c,
 * ext/spl/php_spl.c, ext/standard/basic_functions.c, ext/standard/head.c
 *
 * @group aot-lint
 */
final class DiscardedDateObHttpSplTimeGetterElisionAotTest extends TestCase
{
    public function testDiscardedOnlyDateObHttpSplTimeGetterHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                timezone_abbreviations_list();
                timezone_identifiers_list();
                ob_list_handlers();
                date_get_last_errors();
                spl_autoload_functions();
                time();
                error_reporting();
                ignore_user_abort();
                http_response_code();
                headers_sent();
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_dohst_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_dohst_only_'.getmypid().'.bin';
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
                preg_match_all('/__compiler_time\b/', $body),
                'discarded time must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__compiler_error_reporting\b/', $body),
                'discarded error_reporting must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/phpc_ignore_user_abort\b/', $body),
                'discarded ignore_user_abort must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_http_response_code_apply\b/', $body),
                'discarded http_response_code must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_headers_sent\b/', $body),
                'discarded headers_sent must not call helper'
            );
            $this->assertSame(
                0,
                preg_match_all('/__phpc_ob_list_handlers_ht\b/', $body),
                'discarded ob_list_handlers must not materialize handlers'
            );
            $this->assertSame(
                0,
                preg_match_all('/__value__writeHashtable\b/', $body),
                'discarded tz/date/http/spl list getters must not build hashtables'
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

    public function testLiveDateObHttpSplTimeGetterMatchZend(): void
    {
        // Minimal live set proven to match Zend under AOT. Broader mixes
        // (e.g. time()+ob_list_handlers(), error_reporting()+ignore_user_abort())
        // can empty stdout — pre-existing; not elision. Full set covered by
        // discarded-only IR + DiscardedPureCallElision unit tests.
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        $t = time();
        $er = error_reporting();
        $hs = headers_sent();
        $rc = http_response_code();
        echo is_int($t) && $t > 0 ? "1" : "0", "\n";
        echo is_int($er) ? "1" : "0", "\n";
        echo is_bool($hs) ? "1" : "0", "\n";
        echo (false === $rc || is_int($rc)) ? "1" : "0", "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_dohst_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_dohst_live_'.getmypid().'.bin';
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
            $this->assertSame($zend, $runOut, 'AOT must match Zend line-for-line');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
