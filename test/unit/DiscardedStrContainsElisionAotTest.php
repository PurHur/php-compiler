<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded str_contains/str_starts_with/str_ends_with on typed args must not
 * lower (#36386). Live uses still emit memcmp via VmStringCompare.
 *
 * php-src: ext/standard/string.c PHP_FUNCTION(str_contains|str_starts_with|str_ends_with)
 *
 * @group aot-lint
 */
final class DiscardedStrContainsElisionAotTest extends TestCase
{
    public function testDiscardedStrContainsFamilyAbsentFromIr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, string $n, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                str_contains($s, $n);
                str_starts_with($s, $n);
                str_ends_with($s, $n);
                $c += $k;
            }
            // Live uses stay — only discarded-only calls must vanish from IR.
            return $c
                + (int) str_contains($s, $n)
                + (int) str_starts_with($s, $n)
                + (int) str_ends_with($s, $n);
        }
        echo work('Hello', 'e', 5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_str_contains_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_str_contains_'.getmypid().'.bin';
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
            if (preg_match('/define [^\n]*@work\(/', $ll, $m)) {
                $sig = $m[0];
            }
            $this->assertNotNull($sig, 'missing @work');
            $fnStart = strpos($ll, $sig);
            $this->assertNotFalse($fnStart);
            $fnEnd = strpos($ll, "\ndefine ", $fnStart + 1);
            $body = false === $fnEnd ? substr($ll, $fnStart) : substr($ll, $fnStart, $fnEnd - $fnStart);

            // Three live return uses → at most a small constant of memcmp; a
            // 5-iter loop with 3 discarded calls each would add 15+ if not elided.
            $memcmpCount = preg_match_all('/call [^\n]*@memcmp\b/', $body);
            $this->assertLessThanOrEqual(
                6,
                $memcmpCount,
                'discarded str_contains/starts/ends must be elided (memcmp count='.$memcmpCount.')'
            );
            $this->assertGreaterThan(
                0,
                $memcmpCount,
                'live str_contains family must still lower to memcmp'
            );

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
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

    public function testDiscardedOnlyStrContainsHasNoMemcmp(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function only_discarded(string $s, string $n, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                str_contains($s, $n);
                str_starts_with($s, $n);
                str_ends_with($s, $n);
                $c += $k;
            }
            return $c;
        }
        echo only_discarded('Hello', 'e', 8), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_str_contains_only_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_str_contains_only_'.getmypid().'.bin';
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
                preg_match_all('/call [^\n]*@memcmp\b/', $body),
                'discarded-only str_contains family must emit zero memcmp'
            );

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc);
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $this->assertSame($zend[0], $runOut[0]);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
