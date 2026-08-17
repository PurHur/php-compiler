<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** SplTempFileObject / SplFixedArray::setSize null DEP cite #1 (#31807). */
final class Issue31807SplTempFixedArrayNullCiteTest extends TestCase
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
        return "ERR:E_DEPRECATED:SplTempFileObject::__construct(): Passing null to parameter #1 (\$maxMemory) of type int is deprecated\n"
            ."temp ok\n"
            ."ERR:E_DEPRECATED:SplFixedArray::setSize(): Passing null to parameter #1 (\$size) of type int is deprecated\n"
            ."size=0\n";
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $probe = $repo.'/test/repro/maintainer_gap_spl_temp_fixedarray_null_cite.php';
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
