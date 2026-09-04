<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded pathinfo / parse_url on typed args must not lower (#36386).
 * Live results still match Zend.
 *
 * php-src: ext/standard/basic_functions.c / file.c (pathinfo),
 * ext/standard/url.c (parse_url)
 *
 * @group aot-lint
 */
final class DiscardedPathinfoParseUrlElisionAotTest extends TestCase
{
    public function testDiscardedOnlyPathinfoParseUrlHasNoHelpers(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $p, string $u, int $flags, int $comp, int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                pathinfo($p);
                pathinfo($p, $flags);
                parse_url($u);
                parse_url($u, $comp);
                $c += $i;
            }
            return $c;
        }
        echo only_discarded('/a/b.txt', 'http://example.com/x', PATHINFO_EXTENSION, PHP_URL_HOST, 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_pipu_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_pipu_only_'.getmypid().'.bin';
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
                    '/call [^\n]*@(phpc_pathinfo_|__phpc_parse_url_)/',
                    $body
                ),
                'discarded pathinfo/parse_url must be elided (no helper calls)'
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

    public function testLivePathinfoParseUrlMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $p, string $u): string {
            pathinfo($p);
            parse_url($u);
            $ext = (string) pathinfo('/foo/bar.txt', PATHINFO_EXTENSION);
            $host = (string) parse_url('http://example.com/x', PHP_URL_HOST);
            $base = (string) pathinfo('/a/b.c', PATHINFO_BASENAME);
            $scheme = (string) parse_url('https://x.test/y', PHP_URL_SCHEME);
            return $ext.'|'.$host.'|'.$base.'|'.$scheme;
        }
        echo work('/a/b.txt', 'http://example.com/x'), "\n";
        echo work('/z/w.php', 'https://x.test/y'), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_pipu_live_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_pipu_live_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend, $runOut, 'live results must match Zend');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
