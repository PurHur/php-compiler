<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\PathSupport;
use PHPUnit\Framework\TestCase;

/** Empty-path ValueError wording must match Zend (#29268, #30457). */
final class PathEmptyValueErrorMessageTest extends TestCase
{
    public function test_shared_constant_matches_zend(): void
    {
        self::assertSame('Path cannot be empty', PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE);
    }

    public function test_vm_fopen_empty_path_message(): void
    {
        $bin = realpath(__DIR__.'/../../bin/vm.php');
        self::assertNotFalse($bin);
        $repro = realpath(__DIR__.'/../repro/issue29268_path_empty_valueerror_message.php');
        self::assertNotFalse($repro);
        $cmd = [
            PHP_BINARY,
            '-d', 'memory_limit=512M',
            $bin,
            $repro,
        ];
        $env = array_merge($_ENV, $_SERVER, ['PHP_COMPILER_PROFILE' => '8.4']);
        foreach ($env as $k => $v) {
            if (!is_string($v)) {
                unset($env[$k]);
            }
        }
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $env
        );
        self::assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        self::assertSame(0, $code, $stderr.$stdout);
        self::assertStringContainsString('fopen:Path cannot be empty', $stdout);
        self::assertStringContainsString('file_get_contents:Path cannot be empty', $stdout);
        self::assertStringContainsString('hash_file:Path cannot be empty', $stdout);
        self::assertStringContainsString('file_put_contents:Path cannot be empty', $stdout);
        self::assertStringContainsString("ok\n", $stdout);
    }

    public function test_vm_file_put_contents_empty_path_message(): void
    {
        $bin = realpath(__DIR__.'/../../bin/vm.php');
        self::assertNotFalse($bin);
        $repro = realpath(__DIR__.'/../repro/issue29294_file_put_contents_empty_path.php');
        self::assertNotFalse($repro);
        $cmd = [
            PHP_BINARY,
            '-d', 'memory_limit=512M',
            $bin,
            $repro,
        ];
        $env = array_merge($_ENV, $_SERVER, ['PHP_COMPILER_PROFILE' => '8.4']);
        foreach ($env as $k => $v) {
            if (!is_string($v)) {
                unset($env[$k]);
            }
        }
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2),
            $env
        );
        self::assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        self::assertSame(0, $code, $stderr.$stdout);
        self::assertStringContainsString('file_put_contents:Path cannot be empty', $stdout);
        self::assertStringContainsString("ok\n", $stdout);
    }
}
