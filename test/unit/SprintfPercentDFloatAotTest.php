<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * printf/sprintf %d/%u/%x of floats and overflow-promoted ints must match Zend
 * (#36386 leftover of #37051 lazy ±/× materialize / #37068 %s path).
 *
 * Prior snprintf path passed IEEE doubles (or overflow-arm i64 0) to libc %d —
 * ABI mismatch → printed 0. php-src: formatted_print.c zval_get_long /
 * Zend/zend_operators.h zend_dval_to_lval.
 *
 * @group aot-lint
 */
final class SprintfPercentDFloatAotTest extends TestCase
{
    public function testPrintfPercentDLargeFloatMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $x = 9.223372036854776e18;
        printf("%d\n", $x);
        PHP;
        $this->assertAotMatchesZend($src, 'pctd_large_float');
    }

    public function testPrintfPercentDAfterOverflowPromoteMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $x = PHP_INT_MAX;
        $x = $x + 1;
        printf("%d\n", $x);
        PHP;
        $this->assertAotMatchesZend($src, 'pctd_ov_promote');
    }

    public function testPrintfPercentDInlineOverflowPromoteMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        printf("%d\n", PHP_INT_MAX + 1);
        PHP;
        $this->assertAotMatchesZend($src, 'pctd_ov_inline');
    }

    public function testPrintfPercentDNonConstFmtMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $fmt = "%d\n";
        $x = PHP_INT_MAX;
        $x = $x + 1;
        printf($fmt, $x);
        PHP;
        $this->assertAotMatchesZend($src, 'pctd_nonconst');
    }

    public function testPrintfPercentUHexAfterOverflowMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $x = PHP_INT_MAX;
        $x = $x + 1;
        printf("%u\n", $x);
        printf("%x\n", $x);
        PHP;
        $this->assertAotMatchesZend($src, 'pctu_hex_ov');
    }

    public function testPrintfPercentCFromFloatMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        printf("%c\n", 65.9);
        PHP;
        $this->assertAotMatchesZend($src, 'pctc_float');
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
            $this->assertNotSame(['0'], $aot, 'printf %%d of overflow float must not print 0');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
