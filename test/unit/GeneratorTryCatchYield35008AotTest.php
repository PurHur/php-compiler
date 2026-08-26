<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * AOT: generator try/catch + yield in catch must compile and match Zend (#35008).
 *
 * php-src: Zend/zend_generators.c — exception in resume / catch re-yield
 *
 * @group llvm
 * @group aot
 */
final class GeneratorTryCatchYield35008AotTest extends TestCase
{
    public function testBeginTryGeneratorResumeLinksReturnPending(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/TryCatchHelper.php');
        $begin = strpos($source, 'function beginTryGeneratorResume');
        $this->assertNotFalse($begin);
        $chunk = substr($source, $begin, 1200);
        $this->assertStringContainsString('JitReturnPending::registerDeclarations', $chunk);
        $this->assertStringContainsString('JitReturnPending::ensureLinked', $chunk);
        $this->assertStringContainsString('#35008', $chunk);
    }

    public function testCatchYieldResumeRunsTryThrowSuffix(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/GeneratorHelper.php');
        $this->assertStringContainsString('#35008', $source);
        $this->assertStringContainsString('compiledTryThrowSuffix', $source);
        $this->assertStringContainsString('TYPE_THROW', $source);
    }

    public function testAotMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/generator_try_catch_yield_35008.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_gen_try_catch_35008_'.getmypid();
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
            $this->assertSame('1e', rtrim(implode("\n", $aot)));
        } finally {
            @unlink($bin);
        }
    }

    public function testAotRunsThrowSideEffectsBeforeCatchYield(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/generator_try_throw_side_effect_35008.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_gen_try_se_35008_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, "AOT compile failed:\n".implode("\n", $cout));

        try {
            $aot = [];
            exec(escapeshellarg($bin).' 2>&1', $aot, $arc);
            $zend = [];
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zend, $zrc);
            $this->assertSame(0, $arc, "AOT run rc=$arc out=".implode("\n", $aot));
            $this->assertSame(0, $zrc);
            $this->assertSame(implode("\n", $zend), implode("\n", $aot));
            $this->assertSame('1Te', rtrim(implode("\n", $aot)));
        } finally {
            @unlink($bin);
        }
    }
}
