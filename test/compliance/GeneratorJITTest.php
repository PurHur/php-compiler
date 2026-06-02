<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compile (lint) for linear generator foreach (#3074).
 *
 * MCJIT execute via bin/jit.php can segfault in LLVM 9 harness (pre-existing, #2114);
 * {@see GeneratorJitCompileTest} proves IR. VM coverage: {@see GeneratorVMTest}.
 *
 * @group llvm
 * @group jit
 */
final class GeneratorJITTest extends BaseTest
{
    public static function providePHPTests(): \Generator
    {
        // VM execute lives in GeneratorVMTest; this suite is compile-lint only (#3074).
        if (false) {
            yield;
        }
    }

    /**
     * @dataProvider provideJitLintGeneratorFixtures
     */
    public function testJitLintGeneratorForeachFixture(string $fixture): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $path = __DIR__.'/cases/language/'.$fixture;
        $this->assertFileExists($path);
        [, $code] = self::parsePHPT($path, $fixture);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $root);
        $cmd = array_merge(LlvmToolchain::envPrefix($root), [PHP_BINARY, $bin, '-l']);
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], $code);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for '.$fixture
        );
    }

    /** @return \Generator<string, array{0: string}> */
    public static function provideJitLintGeneratorFixtures(): \Generator
    {
        yield 'linear yield' => ['generator_jit.phpt'];
        yield 'yield from array' => ['generator_jit_yield_from.phpt'];
        yield 'yield + yield from' => ['generator_jit_yield_mixed.phpt'];
        yield 'yield from generator' => ['generator_jit_yield_from_generator.phpt'];
        yield 'dynamic yield from variable' => ['generator_jit_dyn_yield_from.phpt'];
        yield 'computed yield prefix' => ['generator_jit_computed_yield.phpt'];
        yield 'try/catch resume' => ['generator_try_catch_jit.phpt'];
    }
}

