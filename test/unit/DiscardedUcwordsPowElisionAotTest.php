<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded ucwords/bin2hex/addslashes + pow/fdiv on typed args must not lower (#36386).
 *
 * php-src:
 * - ext/standard/string.c PHP_FUNCTION(ucwords) / addslashes / bin2hex — Z_PARAM_STR
 * - ext/standard/math.c PHP_FUNCTION(pow) / fdiv — no user handlers; fdiv÷0 → INF
 *
 * @group aot-lint
 */
final class DiscardedUcwordsPowElisionAotTest extends TestCase
{
    public function testDiscardedUcwordsPowAbsentFromIr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(string $s, float $x, float $y, int $loops): int {
            $c = 0;
            for ($i = 0; $i < $loops; ++$i) {
                ucwords($s);
                addslashes($s);
                stripslashes($s);
                bin2hex($s);
                pow($x, $y);
                fdiv($x, $y);
                $c += $i;
            }
            // Live uses stay — only discarded-only calls must vanish from IR.
            return $c + strlen($s) + (int) pow($x, 0.0);
        }
        echo work("o'brien", 2.0, 3.0, 5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_ucw_pow_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_ucw_pow_'.getmypid().'.bin';
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

            // Discarded string transforms must not allocate per iteration.
            $this->assertSame(
                0,
                substr_count($body, 'call %__string__* @__string__ucwords'),
                'discarded ucwords must not call __string__ucwords'
            );
            $this->assertDoesNotMatchRegularExpression('/call .*@__string__ucwords\b/', $body);
            $this->assertDoesNotMatchRegularExpression('/call .*@__string__addslashes\b/', $body);
            $this->assertDoesNotMatchRegularExpression('/call .*@__string__stripslashes\b/', $body);
            $this->assertDoesNotMatchRegularExpression('/call .*@__string__bin2hex\b/', $body);
            // Live return pow($x, 0.0) may remain as llvm.pow; discarded loop pows must not
            // multiply — reject NestedJIT / phpc_pow helper symbols for the loop body shape.
            $this->assertDoesNotMatchRegularExpression('/call .*@phpc_pow\b/', $body);
            $this->assertDoesNotMatchRegularExpression('/call .*@phpc_fdiv\b/', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            // sum(0..4)=10 + strlen("o'brien")=7 + (int)pow(2.0, 0.0)=1 → 18
            $this->assertSame('18', $runOut[0]);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
