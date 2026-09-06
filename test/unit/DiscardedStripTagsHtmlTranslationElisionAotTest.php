<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded strip_tags / get_html_translation_table on proven-safe args must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(strip_tags)
 * php-src: ext/standard/html.c — PHP_FUNCTION(get_html_translation_table)
 *
 * @group aot-lint
 */
final class DiscardedStripTagsHtmlTranslationElisionAotTest extends TestCase
{
    public function testDiscardedOnlyStripTagsHtmlTableHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                strip_tags('<b>hello</b>');
                strip_tags('<b>hello</b>', '<b>');
                strip_tags('<b>hello</b>', null);
                get_html_translation_table();
                get_html_translation_table(0, 3);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded(8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_st_ghtt_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_st_ghtt_only_'.getmypid().'.bin';
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
                preg_match_all('/\b__compiler_strip_tags\b/', $body),
                'discarded strip_tags must not call StripTags ABI'
            );
            $this->assertSame(
                0,
                preg_match_all('/\b__hashtable__setStringKeyString\b/', $body),
                'discarded get_html_translation_table must not build HT entries'
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

    public function testLiveStripTagsHtmlTableStillMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function live_st_ghtt(): string {
            $s = strip_tags('<b>x</b><i>y</i>', '<b>');
            $t = get_html_translation_table(HTML_SPECIALCHARS, ENT_QUOTES);
            return $s.'|'.(isset($t['"']) ? $t['"'] : 'missing');
        }
        echo live_st_ghtt(), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_st_ghtt_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_st_ghtt_live_'.getmypid().'.bin';
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

    public function testLiveUnsafeStripTagsStaysLowered(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function live_unsafe(?string $s): string {
            // Soft-null subject must stay live so the soft-null deprecate path remains observable.
            strip_tags($s);
            return (string) $s;
        }
        echo live_unsafe('hello'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_st_unsafe_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_st_unsafe_'.getmypid().'.bin';
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
            $this->assertMatchesRegularExpression(
                '/\b__compiler_strip_tags\b/',
                $ll,
                'soft-null strip_tags must stay lowered'
            );
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
