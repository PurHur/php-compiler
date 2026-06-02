<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/**
 * JIT compile (lint) for dynamic class operand `::class` (#4179).
 *
 * MCJIT execute via bin/jit.php segfaults in the LLVM 9 harness (pre-existing);
 * VM coverage: compliance class_name_dynamic_jit.phpt; AOT: fixtures/aot/cases/class_name_dynamic.phpt.
 *
 * @group llvm
 * @group jit
 */
final class ClassNameDynamicJITTest extends BaseTest
{
    protected static string $DIR = __DIR__;

    public static function providePHPTests(): \Generator
    {
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
     * @dataProvider classNameDynamicFixtureProvider
     */
    public function testJitLintClassNameDynamicFixture(string $basename): void
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
    public static function classNameDynamicFixtureProvider(): iterable
    {
        yield 'class_name_dynamic_jit' => ['class_name_dynamic_jit.phpt'];
    }
}
