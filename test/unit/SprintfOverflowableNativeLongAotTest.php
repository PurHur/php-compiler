<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * printf/sprintf %s of overflowable native-long / boxed arith results must match Zend
 * (#36386 leftover of #37051 lazy ±/× materialize; peer of #37064 echo).
 *
 * Prior extractAsCString fell through TYPE_VALUE to a constant empty C string.
 * php-src: ext/standard/formatted_print.c php_formatted_print / convert_to_string.
 *
 * @group aot-lint
 */
final class SprintfOverflowableNativeLongAotTest extends TestCase
{
    public function testPrintfPercentSAfterOverflowPromoteMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $x = PHP_INT_MAX;
        $x = $x + 1;
        printf("%s\n", $x);
        PHP;
        $this->assertAotMatchesZend($src, 'printf_ov_promote');
    }

    public function testSprintfPercentSAfterMulAddChainMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $s = 0;
        $s = $s + 5 * 2 - 1;
        echo sprintf("%s", $s), "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'sprintf_ov_const');
    }

    public function testPrintfPercentSInlineOverflowPromoteMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        printf("%s\n", PHP_INT_MAX + 1);
        PHP;
        $this->assertAotMatchesZend($src, 'printf_ov_inline');
    }

    public function testPrintfPercentSInlineMulAddMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        printf("%s\n", 0 + 5 * 2 - 1);
        PHP;
        $this->assertAotMatchesZend($src, 'printf_mul_inline');
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
            $this->assertNotSame([''], $aot, 'printf/sprintf %s must not print empty for overflowable native-long');
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
