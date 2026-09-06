<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded count_chars / str_word_count on proven-safe args must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(count_chars) / PHP_FUNCTION(str_word_count)
 *
 * @group aot-lint
 */
final class DiscardedCountCharsStrWordCountElisionAotTest extends TestCase
{
    public function testDiscardedOnlyCountCharsStrWordCountHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                count_chars('hello');
                count_chars('hello', 3);
                str_word_count('hello world');
                str_word_count('hello_world', 1, '_');
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_cc_swc_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_cc_swc_only_'.getmypid().'.bin';
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
                preg_match_all('/\bphpc_count_chars_(?:array|string)\b/', $body),
                'discarded count_chars must not call CountChars ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/\bphpc_str_word_count_(?:count|words)\b/', $body),
                'discarded str_word_count must not call StrWordCount ABI'
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

    public function testLiveCountCharsStrWordCountStillMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function live_cc_swc(): string {
            $s = count_chars('ab', 3);
            $n = str_word_count('hello world');
            $w = str_word_count('a_b', 1, '_');
            return $s.'|'.$n.'|'.implode(',', $w);
        }
        echo live_cc_swc(), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_cc_swc_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_cc_swc_live_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
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

    public function testLiveUnsafeCountCharsStrWordCountStaysLowered(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function live_unsafe(?string $s, int $fmt): int {
            // count_chars mode is compile-time-only in this build; soft-null string
            // must stay live so the soft-null deprecate path remains observable.
            count_chars($s);
            count_chars($s, 1);
            // str_word_count accepts runtime format via zParamLong.
            str_word_count((string) $s, $fmt);
            return $fmt;
        }
        echo live_unsafe('hello', 0), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_cc_swc_unsafe_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_cc_swc_unsafe_'.getmypid().'.bin';
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
            if (preg_match('/define [^\n]*@live_unsafe\(/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing @live_unsafe');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            $this->assertGreaterThan(
                0,
                preg_match_all('/\bphpc_count_chars_(?:array|string)\b/', $body),
                'soft-null count_chars must stay lowered'
            );
            $this->assertGreaterThan(
                0,
                preg_match_all('/\bphpc_str_word_count_(?:count|words)\b/', $body),
                'runtime-format str_word_count must stay lowered'
            );
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
