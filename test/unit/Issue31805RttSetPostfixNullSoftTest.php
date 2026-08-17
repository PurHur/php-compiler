<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** RecursiveTreeIterator::setPostfix(null) soft-null (#31805). */
final class Issue31805RttSetPostfixNullSoftTest extends TestCase
{
    public function testVm(): void
    {
        $this->assertSame($this->expected(), $this->runBin('bin/vm.php'));
    }

    public function testJit(): void
    {
        $this->assertSame($this->expected(), $this->runBin('bin/jit.php'));
    }

    private function expected(): string
    {
        return "ERR:E_DEPRECATED:RecursiveTreeIterator::setPostfix(): Passing null to parameter #1 (\$postfix) of type string is deprecated\n"
            ."postfix=''\n";
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $probe = $repo.'/test/repro/maintainer_gap_rtt_setpostfix_null.php';
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
