<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded str_pad/chunk_split/wordwrap/str_split/explode on typed args must
 * not lower (#36386). Live uses still emit helpers and must match Zend.
 *
 * php-src: ext/standard/string.c PHP_FUNCTION(str_pad|chunk_split|wordwrap|str_split|explode)
 *
 * @group aot-lint
 */
final class DiscardedStrPadSplitElisionAotTest extends TestCase
{
    public function testDiscardedOnlyPadSplitHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $s, string $d, int $n, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                str_pad($s, $n, '-');
                chunk_split($s, $n, "\n");
                wordwrap($s, $n, "\n");
                str_split($s, $n);
                explode($d, $s);
                $c += $k;
            }
            return $c;
        }
        echo only_discarded('abcdef', ',', 2, 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_pad_split_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_pad_split_only_'.getmypid().'.bin';
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
                preg_match_all('/padArgv|chunkSplitArgv|wordwrapArgv|StrPadJitHelper|ChunkSplitJitHelper|WordwrapJitHelper|ExplodeJitHelper|__compiler_str_pad|__compiler_chunk_split/', $body),
                'discarded str_pad/chunk_split/wordwrap/str_split/explode must be elided'
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

    public function testPadSplitUsedResultsMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, string $d, int $n): string {
            return str_pad($s, $n, '-')
                . '|' . chunk_split($s, $n, ':')
                . '|' . wordwrap($s, $n, '/')
                . '|' . implode('', str_split($s, $n))
                . '|' . implode('+', explode($d, $s));
        }
        echo work('abcdef', 'c', 2), "\n";
        echo work('hello', 'l', 3), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_pad_split_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pad_split_live_'.getmypid().'.bin';
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
            $this->assertSame($zend, $runOut, 'AOT must match Zend for pad/split builtins');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
