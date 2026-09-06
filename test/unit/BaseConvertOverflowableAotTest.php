<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * base_convert() NestedJIT helper must match Zend for invalid radix chars and
 * scientific / overflow digit strings (#36386).
 *
 * Prior AOT SIGSEGV or "00"/"000": NestedJIT `(int)(PHP_INT_MAX/$base)` was 0
 * (false float path) and `$digit < 0` crashed. Digit encoding is now digit+1 /
 * 0=invalid; overflow uses a float compare; invalid-char flag is a post-pass scan.
 *
 * Untyped float locals go through value-box convert_to_string (not bare
 * {@code __value__readString}, which returned null and SIGSEGV'd).
 *
 * php-src: ext/standard/math.c php_base_convert / _php_math_basetozval.
 *
 * @group aot-lint
 */
final class BaseConvertOverflowableAotTest extends TestCase
{
    public function testBaseConvertInvalidDigitMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $s = '255a';
        echo base_convert($s, 10, 16), "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'bc_inv');
    }

    public function testBaseConvertDotFloatStringMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $s = '255.0';
        echo base_convert($s, 10, 16), "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'bc_dot');
    }

    public function testBaseConvertScientificLiteralMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $s = '9.2233720368548E+18';
        echo base_convert($s, 10, 16), "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'bc_sci');
    }

    public function testBaseConvertFfBase10MatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $s = 'ff';
        echo base_convert($s, 10, 16), "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'bc_ff10');
    }

    public function testBaseConvertOverflowViaNamedStringCastMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $i = PHP_INT_MAX;
        $s = $i + 1;
        $t = (string) $s;
        echo base_convert($t, 10, 16), "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'bc_ov_cast');
    }

    public function testBaseConvertForeachNamedStringCastMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $i = PHP_INT_MAX;
        $s = $i + 1;
        $a = ['k' => $s];
        foreach ($a as $name => $v) {
            $t = (string) $v;
            echo base_convert($t, 10, 16), "\n";
        }
        PHP;
        $this->assertAotMatchesZend($src, 'bc_fe_cast');
    }

    public function testBaseConvertFloatLiteralMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        echo base_convert(255.0, 10, 16), "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'bc_f255');
    }

    public function testBaseConvertUntypedFloatLocalMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $v = 255.0;
        echo base_convert($v, 10, 16), "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'bc_f255v');
    }

    public function testBaseConvertOverflowFloatDirectMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $v = PHP_INT_MAX + 1;
        echo base_convert($v, 10, 16), "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'bc_ov_direct');
    }

    public function testBaseConvertLargeFloatLiteralMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        echo base_convert(1.5e20, 10, 16), "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'bc_f15e20');
    }

    public function testStrlenUntypedFloatLocalMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $v = 255.0;
        echo strlen($v), "\n";
        PHP;
        $this->assertAotMatchesZend($src, 'strlen_f255v');
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
        } finally {
            putenv('PHP_COMPILER_CACHE');
            @unlink($path);
            @unlink($bin);
        }
    }
}
