<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplDoublyLinkedList::add(null) soft-null E_DEPRECATED (#31803).
 */
final class Issue31803DllistAddNullSoftTest extends TestCase
{
    public function testVmAddNullSoftDeprecatesAndInsertsAtZero(): void
    {
        $out = $this->runBin('bin/vm.php');
        $this->assertSame($this->expectedOutput(), $out);
    }

    public function testJitAddNullSoftDeprecatesAndInsertsAtZero(): void
    {
        $out = $this->runBin('bin/jit.php');
        $this->assertSame($this->expectedOutput(), $out);
    }

    private function expectedOutput(): string
    {
        return "ERR:E_DEPRECATED:SplDoublyLinkedList::add(): Passing null to parameter #1 (\$index) of type int is deprecated\n"
            ."count=2 top0=x\n";
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $probe = $repo.'/test/repro/maintainer_gap_dllist_add_null.php';
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $probe],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, $stdout.$stderr);

        return $stdout;
    }
}
