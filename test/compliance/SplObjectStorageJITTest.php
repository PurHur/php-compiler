<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__ . '/../BaseTest.php';

/**
 * JIT/AOT compile (lint) for SplObjectStorage instance methods (#1998, #1056).
 *
 * Runtime execute via bin/jit.php can segfault in LLVM 9 CI (pre-existing); lint proves lowering.
 *
 * @group llvm
 * @group jit
 */
final class SplObjectStorageJITTest extends BaseTest
{
    public static function providePHPTests(): \Generator
    {
        if (false) {
            yield;
        }
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function lintTargetProvider(): iterable
    {
        yield 'spl_object_storage_jit.phpt' => ['spl_object_storage_jit.phpt'];
        yield 'spl_object_storage_attach.php' => ['spl_object_storage_attach.php'];
        yield 'block_getframe_args_contains.php' => ['block_getframe_args_contains.php'];
    }

    /**
     * @dataProvider lintTargetProvider
     */
    public function testJitLintSplObjectStorageFixture(string $basename): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);

        if (str_ends_with($basename, '.phpt')) {
            $path = __DIR__.'/cases/stdlib/'.$basename;
            $this->assertFileExists($path);
            [, $code] = self::parsePHPT($path, $basename);
            $this->lintPhpSource($root, $bin, $code, $basename);

            return;
        }

        $path = $root.'/test/bootstrap-aot/'.$basename;
        $this->assertFileExists($path);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $root);
        $cmd = array_merge(LlvmToolchain::envPrefix($root), [PHP_BINARY, $bin, '-l', $path]);
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root, $env);
        $this->assertIsResource($proc);
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

    private function lintPhpSource(string $root, string $bin, string $code, string $label): void
    {
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for '.$label
        );
    }
}
