<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** @covers issue #23597 */
final class ShellFstatDiskNamed23597AotCompileTest extends TestCase
{
    public function testShellFstatDiskNamedArgsAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/shell_fstat_disk_named_23597.php';
        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for shell_exec/fpassthru/disk_* named args (#23597)'
        );
    }
}
