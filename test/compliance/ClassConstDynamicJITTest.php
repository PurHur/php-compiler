<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compile (lint) for dynamic class constant fetch Class::{$name} (#3150).
 *
 * MCJIT execute via bin/jit.php can segfault in the LLVM 9 harness (pre-existing);
 * VM coverage: {@see ClassConstDynamicVMTest}.
 *
 * @group llvm
 * @group jit
 */
final class ClassConstDynamicJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
        // VM execute lives in ClassConstDynamicVMTest; this suite is compile-lint only (#3150).
        if (false) {
            yield;
        }
    }

    public function setUp(): void
    {
        $this->BIN = realpath(__DIR__.'/../../bin/jit.php');
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                'LLVM 9 toolchain not available. Run script/install-llvm9.sh or use the 22.04-dev Docker image.'
            );
        }
    }

    /**
     * @dataProvider dynamicClassConstFixtureProvider
     */
    public function testJitLintDynamicClassConstFixture(string $basename): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $path = $root.'/test/compliance/cases/language/'.$basename;
        $this->assertFileExists($path);
        [, $code] = self::parsePHPT($path, $basename);

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $root);

        $cmd = array_merge(
            LlvmToolchain::envPrefix($root),
            [PHP_BINARY, $bin, '-l']
        );
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for '.$basename
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function dynamicClassConstFixtureProvider(): iterable
    {
        yield 'class_const_dynamic_jit' => ['class_const_dynamic_jit.phpt'];
        yield 'class_const_variable_class_jit' => ['class_const_variable_class_jit.phpt'];
        yield 'dynamic_class_constant' => ['dynamic_class_constant.phpt'];
        yield 'enum_dynamic_case_fetch' => ['enum_dynamic_case_fetch.phpt'];
    }
}
