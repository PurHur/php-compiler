<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Script-global bool assignments must stay boolean in AOT __value__ boxes (#1492 bootstrap-aot). */
final class AotBoolScriptGlobalTest extends TestCase
{
    public function testFalseScriptGlobalEchoMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/echo_false_assign.php';
        $vmCmd = [PHP_BINARY, $root.'/bin/vm.php', $source];
        $vmOut = $this->runCommand($vmCmd, $root);
        $this->assertSame("boolean\neq\nX\n", $vmOut);

        $out = $root.'/build/test-aot-bool-script-global';
        @mkdir(dirname($out), 0775, true);
        $compileCmd = [PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source];
        $this->runCommand($compileCmd, $root, expectExit: 0);
        $aotOut = $this->runCommand([$out], $root);
        $this->assertSame($vmOut, $aotOut, 'AOT must match VM for false script-global echo');
    }

    public function testBoxedBoolIntCastMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/bool_int_cast_boxed.php';
        $expected = "1\n1\n1\n";
        $vmOut = $this->runCommand([PHP_BINARY, $root.'/bin/vm.php', $source], $root);
        $this->assertSame($expected, $vmOut);

        $out = $root.'/build/test-aot-bool-int-cast-boxed';
        @mkdir(dirname($out), 0775, true);
        $this->runCommand([PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source], $root, expectExit: 0);
        $aotOut = $this->runCommand([$out], $root);
        $this->assertSame($expected, $aotOut, 'AOT (int) on boxed bool must match VM');
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
