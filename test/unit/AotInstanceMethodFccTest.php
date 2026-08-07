<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: instance-method FCC `$obj->m(...)` must print like Zend/VM/JIT (#28613).
 *
 * @group llvm
 * @group aot
 */
final class AotInstanceMethodFccTest extends TestCase
{
    public function testAotInstanceMethodFccMatchesVm(): void
    {
        $root = dirname(__DIR__, 2);
        $source = $root.'/test/repro/issue_28613_aot_instance_fcc.php';
        $vmOut = $this->runCommand([PHP_BINARY, $root.'/bin/vm.php', $source], $root);
        $this->assertSame("4\n", $vmOut);

        $out = $root.'/build/test-aot-instance-method-fcc';
        @mkdir(dirname($out), 0775, true);
        $env = ['PHP_COMPILER_HELPER_RUNTIME_O' => '0'];
        $this->runCommand(
            [PHP_BINARY, $root.'/bin/compile.php', '-o', $out, $source],
            $root,
            expectExit: 0,
            env: $env
        );
        $aotOut = $this->runCommand([$out], $root);
        $this->assertSame($vmOut, $aotOut);
    }

    /**
     * @param list<string>          $cmd
     * @param array<string, string> $env
     */
    private function runCommand(array $cmd, string $cwd, int $expectExit = 0, array $env = []): string
    {
        $fullEnv = null;
        if ([] !== $env) {
            $fullEnv = array_merge(getenv() ?: [], $env);
        }
        $proc = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $fullEnv
        );
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
