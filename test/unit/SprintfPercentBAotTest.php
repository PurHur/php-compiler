<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * printf/sprintf %b of floats and overflow-promoted ints must match Zend
 * (#36386 leftover of #37051 lazy ±/× materialize / #37075 %d path).
 *
 * Prior snprintf path treated %b as a non-integer conversion (else branch),
 * so overflow-arm i64 0 / IEEE doubles reached libc bare %b → printed 0.
 * php-src: formatted_print.c %b (unsigned machine-word bit pattern) /
 * zval_get_long / zend_dval_to_lval.
 *
 * @group aot-lint
 */
final class SprintfPercentBAotTest extends TestCase
{
    public function testPrintfPercentBLargeFloatMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $x = 9.223372036854776e18;
        printf("%b\n", $x);
        PHP;
        $this->assertAotMatchesZend($src, 'pctb_large_float');
    }

    public function testPrintfPercentBAfterOverflowPromoteMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $x = PHP_INT_MAX;
        $x = $x + 1;
        printf("%b\n", $x);
        PHP;
        $this->assertAotMatchesZend($src, 'pctb_ov_promote');
    }

    public function testPrintfPercentBInlineOverflowPromoteMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        printf("%b\n", PHP_INT_MAX + 1);
        PHP;
        $this->assertAotMatchesZend($src, 'pctb_ov_inline');
    }

    public function testPrintfPercentBNonConstFmtMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $fmt = "%b\n";
        $x = PHP_INT_MAX;
        $x = $x + 1;
        printf($fmt, $x);
        PHP;
        $this->assertAotMatchesZend($src, 'pctb_nonconst');
    }

    public function testSprintfPercentBAfterMulAddChainMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $s = PHP_INT_MAX;
        $i = 1;
        echo sprintf("%b\n", $s + $i * 2 - 1);
        PHP;
        $this->assertAotMatchesZend($src, 'pctb_mul_add');
    }

    private function assertAotMatchesZend(string $src, string $tag): void
    {
        $path = sys_get_temp_dir().'/phpc_'.$tag.'_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_'.$tag.'_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            putenv('PHP_COMPILER_CACHE=0');
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zend, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zend));
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $aotRc);
            $this->assertSame(0, $aotRc, implode("\n", $aot));
            $this->assertSame($zend, $aot);
            $this->assertNotSame(['0'], $aot, 'printf %%b of overflow float must not print 0');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
