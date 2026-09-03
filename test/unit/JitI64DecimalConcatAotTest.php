<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Int→string concat must use zend_print_long_to_buf-shaped IR, not malloc+snprintf (#36386).
 *
 * php-src: Zend/zend_string.h zend_print_long_to_buf / Zend/zend_operators.c concat.
 *
 * @group aot-lint
 */
final class JitI64DecimalConcatAotTest extends TestCase
{
    public function testStringDotIntIrUsesFromLongNotMallocSnprintf(): void
    {
        $src = <<<'PHP'
        <?php
        $buf = '';
        for ($i = 0; $i < 4; ++$i) {
            $buf .= 'row-'.$i.';';
        }
        echo $buf, "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_i64dec_ir_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_i64dec_ir_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $srcFile = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitNativeString.php');
            $jitFile = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
            $this->assertStringContainsString('__phpc_i64_decimal', $srcFile);
            $this->assertStringContainsString('__string__fromLong', $srcFile);
            $this->assertStringContainsString('compileConcatStringAndI64', $jitFile);
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(['row-0;row-1;row-2;row-3;'], $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testIntConcatEdgesMatchZend(): void
    {
        $src = <<<'PHP'
        <?php
        echo 'x'.(-3).'y', '|', (string) PHP_INT_MIN, '|', 0 . 1, "\n";
        $buf = '';
        for ($i = -2; $i < 3; ++$i) {
            $buf .= '['.$i.']';
        }
        echo $buf, "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_i64dec_edge_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_i64dec_edge_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zendOut, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zendOut));
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($zendOut, $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testStrBuilderScaleMatchesZend(): void
    {
        $src = <<<'PHP'
        <?php
        $buf = '';
        for ($i = 0; $i < 400; ++$i) {
            $buf .= 'row-'.$i.';';
        }
        echo strlen($buf), '|', substr($buf, 0, 12), '|', md5($buf), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_i64dec_scale_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_i64dec_scale_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zendOut, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zendOut));
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($zendOut, $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
