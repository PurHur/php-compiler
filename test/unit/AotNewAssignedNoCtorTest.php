<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Assigned `new` without user __construct must run in AOT (#8308). */
final class AotNewAssignedNoCtorTest extends TestCase
{
    public function testAssignedNewWithoutConstructorMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/aot_new_assigned_no_ctor.php';
        $vmOut = $this->runCommand([PHP_BINARY, $root.'/bin/vm.php', $source], $root);
        $this->assertSame("ok\n", $vmOut);

        $out = $root.'/build/test-aot-new-assigned-no-ctor';
        @mkdir(dirname($out), 0775, true);
        $this->runCommand([PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source], $root, expectExit: 0);
        $aotOut = $this->runCommand([$out], $root);
        $this->assertSame($vmOut, $aotOut);
    }

    public function testBootstrapBitwiseNotFixtureMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/bootstrap-aot/bitwise_not.php';
        $vmOut = $this->runCommand([PHP_BINARY, $root.'/bin/vm.php', $source], $root);

        $out = $root.'/build/test-aot-bitwise-not';
        @mkdir(dirname($out), 0775, true);
        $this->runCommand([PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source], $root, expectExit: 0);
        $aotOut = $this->runCommand([$out], $root);
        $this->assertSame($vmOut, $aotOut);
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
