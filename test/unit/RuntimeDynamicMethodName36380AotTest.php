<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Runtime `$obj->$name()` AOT must match Zend/VM (#36380 / #34084).
 *
 * @group llvm
 */
final class RuntimeDynamicMethodName36380AotTest extends TestCase
{
    public function testConcatMethodNameMatchesVmAndZend(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/runtime_dynamic_method_name_36380.php';
        $expected = "blockHeader:x\nblockList:y\nok";

        $zend = $this->runCommand([PHP_BINARY, $source], $root);
        $this->assertSame($expected, $zend);

        $vm = $this->runCommand([PHP_BINARY, $root.'/bin/vm.php', $source], $root);
        $this->assertSame($expected, $vm);

        $out = $root.'/build/test-aot-runtime-dynamic-method-36380';
        @mkdir(dirname($out), 0775, true);
        @unlink($out);
        $this->runCommand(
            [PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source],
            $root,
            expectExit: 0
        );
        $aot = $this->runCommand([$out], $root);
        $this->assertSame($expected, $aot);
    }

    /**
     * @param list<string> $cmd
     */
    private function runCommand(array $cmd, string $cwd, int $expectExit = 0): string
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame($expectExit, $exit, trim($stderr !== false ? $stderr : ''));

        return $stdout !== false ? rtrim($stdout, "\n") : '';
    }
}
