<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded quoted_printable_encode/decode, basename, dirname (+ md5/sha1
 * with binary flag) on typed string args must not lower (#36386). Live
 * results still match Zend.
 *
 * php-src: ext/standard/quot_print.c, basename.c, file.c (dirname), md5.c, sha1.c
 *
 * @group aot-lint
 */
final class DiscardedQuotPrintPathElisionAotTest extends TestCase
{
    public function testDiscardedOnlyQuotPrintPathHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $s, string $path, string $suffix, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                quoted_printable_encode($s);
                quoted_printable_decode($s);
                basename($path);
                basename($path, $suffix);
                dirname($path);
                dirname($path, 2);
                md5($s, true);
                sha1($s, false);
                $c += $k;
            }
            return $c;
        }
        echo only_discarded("a=b", '/a/b.php', '.php', 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_qpp_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_qpp_only_'.getmypid().'.bin';
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
                preg_match_all(
                    '/__compiler_quoted_printable_encode|__compiler_quoted_printable_decode|__compiler_basename|__compiler_dirname|phpc_basename|phpc_dirname|JitPath|quoted_printable/',
                    $body
                ),
                'discarded quoted_printable/basename/dirname must be elided'
            );
            $this->assertSame(
                0,
                preg_match_all('/call [^\n]*@__compiler_hash\b/', $body),
                'discarded md5/sha1 with binary flag must be elided'
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

    public function testLiveQuotPrintPathMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, string $path, string $suffix): string {
            quoted_printable_encode($s);
            basename($path, $suffix);
            dirname($path, 2);
            md5($s, true);
            $qp = quoted_printable_encode($s);
            $base = basename($path, $suffix);
            $dir = dirname($path, 2);
            $digest = md5($s);
            return $qp.'|'.$base.'|'.$dir.'|'.$digest;
        }
        echo work("a=b", '/x/y/z.php', '.php'), "\n";
        echo work('Hello', '/a/b/c', ''), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_qpp_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_qpp_live_'.getmypid().'.bin';
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
            $this->assertSame($zend, $runOut, 'AOT must match Zend for live path/quotprint');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
