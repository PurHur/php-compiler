<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * __DIR__ / __FILE__ script magic constants (#9833, Zend/zend_compile.c).
 */
final class MagicDirFileVMTest extends TestCase
{
    public function test_magic_dir_file_script_constants(): void
    {
        $root = dirname(__DIR__, 2);
        $script = __DIR__.'/cases/language/magic_dir_file/script.php';
        $this->assertFileExists($script);

        $cmd = array_merge(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=0', $root.'/bin/vm.php', $script]
        );
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
        $this->assertSame(
            dirname($script)."\n".realpath($script)."\n4\n",
            $stdout
        );
    }

    public function test_magic_dir_file_script_constants_jit(): void
    {
        $root = dirname(__DIR__, 2);
        $script = __DIR__.'/cases/language/magic_dir_file/script.php';
        $this->assertFileExists($script);

        $cmd = array_merge(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=0', $root.'/bin/jit.php', $script]
        );
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
        $this->assertSame(
            dirname($script)."\n".realpath($script)."\n4\n",
            $stdout
        );
    }
}
