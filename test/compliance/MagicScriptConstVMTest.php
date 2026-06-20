<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * __DIR__ / __FILE__ / __LINE__ script magic constants (#9848, Zend/zend_compile.c).
 */
final class MagicScriptConstVMTest extends TestCase
{
    public function test_magic_script_const_eval_repro(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['echo __FILE__;', 'echo __DIR__;'] as $code) {
            $cmd = [
                PHP_BINARY,
                '-d', 'display_errors=0',
                '-d', 'error_reporting=0',
                $root.'/bin/vm.php',
                '-r', $code,
            ];
            $proc = proc_open(
                $cmd,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root
            );
            $this->assertIsResource($proc);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);

            $this->assertSame(0, $exit, trim((string) $stderr));
            $this->assertStringNotContainsString('Expr_MagicScriptConst', (string) $stderr);
            $this->assertNotSame('', trim((string) $stdout));
        }
    }

    public function test_magic_script_const_compile_and_run(): void
    {
        $root = dirname(__DIR__, 2);
        $script = __DIR__.'/cases/language/magic_script_const/run.php';
        $this->assertFileExists($script);

        $cmd = [
            PHP_BINARY,
            '-d', 'display_errors=0',
            '-d', 'error_reporting=0',
            $root.'/bin/vm.php',
            $script,
        ];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname($script)
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, trim((string) $stderr));
        $lines = explode("\n", rtrim((string) $stdout, "\n"));
        $this->assertSame('string', $lines[0] ?? '');
        $this->assertSame('string', $lines[1] ?? '');
        $this->assertSame(dirname($script), $lines[2] ?? '');
        $this->assertSame(realpath($script), $lines[3] ?? '');
        $this->assertGreaterThanOrEqual(1, (int) ($lines[4] ?? 0));
    }

    public function test_magic_script_const_jit(): void
    {
        $root = dirname(__DIR__, 2);
        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM not available');
        }

        $script = __DIR__.'/cases/language/magic_script_const/run.php';
        $this->assertFileExists($script);

        $cmd = [
            PHP_BINARY,
            '-d', 'display_errors=0',
            '-d', 'error_reporting=0',
            $root.'/bin/jit.php',
            $script,
        ];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname($script)
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, trim((string) $stderr));
        $lines = explode("\n", rtrim((string) $stdout, "\n"));
        $this->assertSame('string', $lines[0] ?? '');
        $this->assertSame('string', $lines[1] ?? '');
        $this->assertSame(dirname($script), $lines[2] ?? '');
        $this->assertSame(realpath($script), $lines[3] ?? '');
        $this->assertGreaterThanOrEqual(1, (int) ($lines[4] ?? 0));
    }

    public function test_magic_script_const_in_closure_compile_and_run(): void
    {
        $root = dirname(__DIR__, 2);
        $script = __DIR__.'/cases/language/closure_magic_dir/run.php';
        $this->assertFileExists($script);

        $cmd = [
            PHP_BINARY,
            '-d', 'display_errors=0',
            '-d', 'error_reporting=0',
            $root.'/bin/vm.php',
            $script,
        ];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname($script)
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $this->assertSame(0, $exit, trim((string) $stderr));
        $this->assertStringNotContainsString('Expr_MagicScriptConst', (string) $stderr);
        $lines = explode("\n", rtrim((string) $stdout, "\n"));
        $this->assertSame(dirname($script), $lines[0] ?? '');
        $this->assertSame(realpath($script), $lines[1] ?? '');
        $this->assertGreaterThanOrEqual(1, (int) ($lines[2] ?? 0));
    }
}
