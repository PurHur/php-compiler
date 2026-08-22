<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: Generator::throw into try/catch that does not yield again (#33726).
 *
 * @group llvm
 * @group aot
 */
final class GeneratorThrowCatchNoYield33726AotTest extends TestCase
{
    public function testThrowCatchWithoutYieldMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_gen_throw_catch_no_yield.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_gen_throw_noyield_33726_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, "AOT compile failed:\n".implode("\n", $cout));
        $this->assertFileExists($bin);

        try {
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $arc);
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zend, $zrc);
            $this->assertSame(0, $arc, "AOT run rc=$arc out=".implode("\n", $aot));
            $this->assertSame(0, $zrc);
            $this->assertSame(implode("\n", $zend), implode("\n", $aot));
            $this->assertSame("Cx", rtrim(implode("\n", $aot)));
        } finally {
            @unlink($bin);
        }
    }
}
