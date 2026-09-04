<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Discarded math.c builtins on typed numeric args must not lower (#36386).
 *
 * php-src: ext/standard/math.c — abs/sqrt/floor/… on int|float have no
 * user-handler side effects; null soft-coercion deprecates and stays live.
 *
 * @group aot-lint
 */
final class DiscardedPureMathElisionAotTest extends TestCase
{
    public function testDiscardedMathOnTypedNumericsAbsentFromIr(): void
    {
        $src = <<<'PHP'
        <?php
        declare(strict_types=1);
        function work(int $n, float $x, int $loops): int {
            $s = 0;
            for ($i = 0; $i < $loops; ++$i) {
                abs($n);
                sqrt($x);
                floor($x);
                ceil($x);
                sin($x);
                cos($x);
                deg2rad($x);
                $s += $i;
            }
            // Live uses stay — only discarded-only math must vanish from IR.
            return $s + $n + (int) $x;
        }
        echo work(-3, 2.5, 5), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_disc_math_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_disc_math_'.getmypid().'.bin';
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

            // Every math call below is discarded-only in this function.
            $this->assertStringNotContainsString('call double @llvm.sin.f64', $body);
            $this->assertStringNotContainsString('call double @llvm.cos.f64', $body);
            $this->assertStringNotContainsString('call double @llvm.sqrt.f64', $body);
            $this->assertStringNotContainsString('call double @llvm.floor.f64', $body);
            $this->assertStringNotContainsString('call double @llvm.ceil.f64', $body);
            $this->assertStringNotContainsString('call double @llvm.fabs.f64', $body);
            $this->assertDoesNotMatchRegularExpression('/call .*@phpc_sin\b/', $body);
            $this->assertDoesNotMatchRegularExpression('/call .*@phpc_cos\b/', $body);
            $this->assertDoesNotMatchRegularExpression('/call .*@phpc_deg2rad\b/', $body);
            $this->assertDoesNotMatchRegularExpression('/call .*@phpc_abs_/', $body);

            exec(escapeshellarg($bin), $runOut, $runRc);
            $this->assertSame(0, $runRc, 'AOT binary must not segfault');
            $this->assertCount(1, $runOut);
            // sum(0..4)=10 + (-3) + (int)2.5=2 → 9
            $this->assertSame('9', $runOut[0]);
        } finally {
            putenv('PHP_COMPILER_DUMP_IR');
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
