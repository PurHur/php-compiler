<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded substr/str_repeat/strcmp/strpos on typed args must not lower (#36386).
 *
 * php-src:
 * - ext/standard/string.c PHP_FUNCTION(substr) / str_repeat / strcmp / strcasecmp
 * - ext/standard/string.c PHP_FUNCTION(strpos) / stripos / strstr
 *
 * @group aot-lint
 */
final class DiscardedSubstrCompareElisionAotTest extends TestCase
{
    public function testDiscardedSubstrCompareAbsentFromIr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, string $n, int $i, int $loops): int {
            $c = 0;
            for ($k = 0; $k < $loops; ++$k) {
                substr($s, $i);
                substr($s, $i, 1);
                str_repeat($s, $i);
                strcmp($s, $n);
                strcasecmp($s, $n);
                strpos($s, $n);
                stripos($s, $n);
                strstr($s, $n);
                $c += $k;
            }
            // Live uses stay — only discarded-only calls must vanish from IR.
            return $c
                + strlen($s)
                + strlen((string) substr($s, 0, 1))
                + strcmp($s, $n)
                + (int) strpos($s, $n);
        }
        echo work('Hello', 'e', 1, 5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_substr_cmp_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_substr_cmp_'.getmypid().'.bin';
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

            // Discarded str_repeat / strcasecmp / strstr must not call per iteration.
            // Live return strcmp / strpos / substr may remain outside the loop.
            $this->assertSame(
                0,
                preg_match_all('/call [^\n]*@__compiler_str_repeat\b/', $body),
                'discarded str_repeat must be elided'
            );
            $this->assertSame(
                0,
                preg_match_all('/call [^\n]*@__compiler_strcasecmp\b/', $body),
                'discarded strcasecmp must be elided'
            );
            $this->assertSame(
                0,
                preg_match_all('/call [^\n]*@phpc_strstr_scan\b/', $body),
                'discarded strstr must be elided'
            );

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            // sum(0..4)=10 + strlen('Hello')=5 + strlen(substr)=1 + strcmp('Hello','e')!=0 + strpos=1
            // strcmp('Hello','e') is positive (H>e) — use Zend to pin expected below.
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
}
