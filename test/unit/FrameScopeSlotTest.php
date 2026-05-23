<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * VM Frame scope must preserve compile-time slot indices (?: merge blocks, #137).
 */
final class FrameScopeSlotTest extends TestCase
{
    public function testTernaryEchoesChosenBranch(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $vm = realpath($repoRoot.'/bin/vm.php');
        if (false === $vm) {
            $this->markTestSkipped('bin/vm.php missing');
        }
        $cmd = array_merge([PHP_BINARY, $vm, '-r', 'echo true ? "yes" : "no";']);
        $pipes = [];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repoRoot);
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc));
        $this->assertSame('yes', $stdout);
    }
}
