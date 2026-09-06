<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * is_numeric() of overflow-promoted floats must match Zend (#36386 leftover of
 * #37051 lazy ±/× materialize).
 *
 * Prior TYPE_VALUE arm eagerly called __value__readString / readLong before the
 * type select; double boxes then fed garbage into strtod → SIGSEGV. Unnamed
 * overflow SSA temps also need a numeric short-circuit (both ±/× arms are
 * IS_LONG / IS_DOUBLE).
 *
 * php-src: Zend/zend_builtin_functions.c zif_is_numeric / ZEND_IS_NUMERIC.
 *
 * @group aot-lint
 */
final class IsNumericOverflowableAotTest extends TestCase
{
    public function testIsNumericNamedOverflowPromoteMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $i = PHP_INT_MAX;
        $s = $i + 1;
        echo is_numeric($s) ? '1' : '0', "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'isnum_named_ov');
    }

    public function testIsNumericForeachOverflowPromoteMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $i = PHP_INT_MAX;
        $s = $i + 1;
        $a = ['k' => $s];
        foreach ($a as $name => $v) {
            echo is_numeric($v) ? '1' : '0', "\n";
        }
        PHP;
        $this->assertAotMatchesZend($src, 'isnum_fe_ov');
    }

    public function testIsNumericExprOverflowPromoteMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $i = PHP_INT_MAX;
        echo is_numeric($i + 1) ? '1' : '0', "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'isnum_expr_ov');
    }

    public function testIsNumericMulAddChainMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $s = PHP_INT_MAX;
        $i = 1;
        $exprs = ['chain' => $s + $i * 2 - 1];
        foreach ($exprs as $name => $v) {
            echo is_numeric($v) ? '1' : '0', "\n";
        }
        PHP;
        $this->assertAotMatchesZend($src, 'isnum_chain_fe');
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
            $this->assertSame(['1'], $aot, 'is_numeric of overflow float must be true');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
