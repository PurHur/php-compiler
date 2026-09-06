<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * strval() of overflow-promoted floats via foreach / assign-from-fetch must match Zend
 * (#36386 leftover of #37051 lazy ±/× materialize).
 *
 * Prior TYPE_VALUE arm passed raw `$args[0]->value`; floatval uses
 * `JitValueBox::valuePtrFromVariable` — foreach temps missed the live box and
 * type-switched to empty. (string)/echo/sprintf/%s already matched.
 *
 * php-src: ext/standard/type.c php_strval / convert_to_string.
 *
 * @group aot-lint
 */
final class StrvalOverflowableForeachAotTest extends TestCase
{
    public function testStrvalForeachOverflowPromoteMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $i = PHP_INT_MAX;
        $s = $i + 1;
        $a = ['k' => $s];
        foreach ($a as $name => $v) {
            echo strval($v), "\n";
        }
        PHP;
        $this->assertAotMatchesZend($src, 'strval_fe_ov');
    }

    public function testStrvalCopyFromForeachMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $i = PHP_INT_MAX;
        $s = $i + 1;
        $a = ['k' => $s];
        foreach ($a as $name => $v) {
            $copy = $v;
            echo strval($copy), "\n";
        }
        PHP;
        $this->assertAotMatchesZend($src, 'strval_fe_copy');
    }

    public function testStrvalOtherVarOverflowAssignMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $i = PHP_INT_MAX;
        $s = $i + 1;
        echo strval($s), "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'strval_other_ov');
    }

    public function testStrvalMulAddChainInArrayMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $s = PHP_INT_MAX;
        $i = 1;
        $exprs = ['chain' => $s + $i * 2 - 1];
        foreach ($exprs as $name => $v) {
            echo strval($v), "\n";
        }
        PHP;
        $this->assertAotMatchesZend($src, 'strval_chain_fe');
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
            $this->assertNotSame([''], $aot, 'strval of overflow float must not print empty');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
