<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * {main} `$n` used in `$i % $n` after an int-only for-loop must stay native i64
 * alongside `$i`, or `$i < $n` spins forever (#36386 assoc-heavy).
 *
 * @group aot-lint
 */
final class NativeLongModPeerAotTest extends TestCase
{
    public function testModuloKeyIssetAfterStringKeyLoopDoesNotHang(): void
    {
        $src = <<<'PHP'
        <?php
        $n = 10;
        $map = [];
        for ($i = 0; $i < $n; ++$i) {
            $map['k' . $i] = $i;
        }
        $hits = 0;
        for ($i = 0; $i < $n; ++$i) {
            $key = 'k' . ($i % $n);
            if (isset($map[$key])) {
                ++$hits;
            }
        }
        echo $hits, '|', count($map), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_native_long_mod_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_native_long_mod_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path), $zend, $zendRc);
            $this->assertSame(0, $zendRc);
            $aot = [];
            // Hang was infinite `$i < $n` — cap wall time.
            exec('timeout 5 '.escapeshellarg($bin), $aot, $aotRc);
            $this->assertSame(0, $aotRc, 'AOT timed out or crashed; likely mixed i64/box compare');
            $this->assertSame($zend, $aot);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testModuloAfterMapBuildDoesNotHang(): void
    {
        $src = <<<'PHP'
        <?php
        $n = 10;
        $map = [];
        for ($i = 0; $i < $n; ++$i) {
            $map['k' . $i] = $i;
        }
        echo count($map), "\n";
        $i = 0;
        $m = $i % $n;
        echo $m, "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_native_long_mod2_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_native_long_mod2_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            $aot = [];
            exec('timeout 5 '.escapeshellarg($bin), $aot, $aotRc);
            $this->assertSame(0, $aotRc, 'AOT timed out; `$i % $n` after map build hung');
            $this->assertSame(['10', '0'], $aot);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
