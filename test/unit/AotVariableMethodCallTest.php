<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Assigned object variable instance method calls must compile in AOT (#8407). */
final class AotVariableMethodCallTest extends TestCase
{
    public function testAssignedNewVariableMethodCallMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/aot_variable_method_call.php';
        $vmOut = $this->runCommand([PHP_BINARY, $root.'/bin/vm.php', $source], $root);
        $this->assertSame('2', $vmOut);

        $out = $root.'/build/test-aot-variable-method-call';
        @mkdir(dirname($out), 0775, true);
        $this->runCommand([PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source], $root, expectExit: 0);
        $aotOut = $this->runCommand([$out], $root);
        $this->assertSame($vmOut, $aotOut);
    }

    public function testBootstrapBlockGetframeArgsContainsAotLinkSmoke(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/bootstrap-aot/block_getframe_args_contains.php';

        $out = $root.'/build/test-aot-block-getframe-args-contains';
        @mkdir(dirname($out), 0775, true);
        $this->runCommand([PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source], $root, expectExit: 0);
        $aotOut = $this->runCommand([$out], $root);
        $this->assertSame("1\n", $aotOut);
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

        return $stdout !== false ? $stdout : '';
    }
}
