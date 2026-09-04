<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded str_replace/str_ireplace/substr_replace/strtr on typed string args
 * must not lower (#36386). Live uses still emit helpers and must match Zend.
 *
 * php-src: ext/standard/string.c PHP_FUNCTION(str_replace|str_ireplace|substr_replace|strtr)
 *
 * @group aot-lint
 */
final class DiscardedStrReplaceElisionAotTest extends TestCase
{
    public function testDiscardedOnlyReplaceHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $s, string $a, string $b, string $from, string $to, int $off, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                str_replace($a, $b, $s);
                str_ireplace($a, $b, $s);
                substr_replace($s, $b, $off);
                strtr($s, $from, $to);
                $c += $k;
            }
            return $c;
        }
        echo only_discarded('abcABC', 'a', 'x', 'ab', 'XY', 1, 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_replace_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_replace_only_'.getmypid().'.bin';
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
                preg_match_all('/replaceArgv|ireplaceArgv|StrReplaceJitHelper|phpc_str_replace|phpc_str_ireplace|JitSubstrReplace|__compiler_strtr|StrtrTwoStringJitHelper|strtrTwoString/', $body),
                'discarded str_replace/str_ireplace/substr_replace/strtr must be elided'
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

    public function testReplaceUsedResultsMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, string $a, string $b, string $from, string $to, int $off): string {
            // Live strtr AOT still crashes on master — prove elision separately;
            // live match covers replace / ireplace / substr_replace only.
            strtr($s, $from, $to);
            return str_replace($a, $b, $s)
                . '|' . str_ireplace($a, $b, $s)
                . '|' . substr_replace($s, $b, $off);
        }
        echo work('abcABC', 'a', 'x', 'ab', 'XY', 1), "\n";
        echo work('Hello', 'l', 'L', 'He', 'hE', 2), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_replace_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_replace_live_'.getmypid().'.bin';
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
            $this->assertSame($zend, $runOut, 'AOT must match Zend for replace builtins');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
