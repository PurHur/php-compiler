<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * class/interface/trait/enum_exists(null $autoload) — soft-null DEP + no crash (#31443).
 *
 * php-src: Zend/zend_builtin_functions.c — Z_PARAM_BOOL $autoload
 */
final class Issue31443ExistsNullAutoloadTest extends TestCase
{
    public function testVmSoftNullDepAndResults(): void
    {
        $out = $this->runBin('bin/vm.php', $this->softProbePath());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testJitSoftNullDepAndResults(): void
    {
        $out = $this->runBin('bin/jit.php', $this->softProbePath());
        $this->assertSame($this->expectedSoftOutput(), $out);
    }

    public function testVmStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/vm.php', $this->strictProbePath());
        $this->assertSame(
            "TypeError: class_exists(): Argument #2 (\$autoload) must be of type bool, null given\n",
            $out
        );
    }

    public function testJitStrictTypesTypeError(): void
    {
        $out = $this->runBin('bin/jit.php', $this->strictProbePath());
        $this->assertSame(
            "TypeError: class_exists(): Argument #2 (\$autoload) must be of type bool, null given\n",
            $out
        );
    }

    private function expectedSoftOutput(): string
    {
        return "DEPRECATED: class_exists(): Passing null to parameter #2 (\$autoload) of type bool is deprecated\n"
            ."class_exists=true\n"
            ."DEPRECATED: interface_exists(): Passing null to parameter #2 (\$autoload) of type bool is deprecated\n"
            ."interface_exists=true\n"
            ."DEPRECATED: trait_exists(): Passing null to parameter #2 (\$autoload) of type bool is deprecated\n"
            ."trait_exists=false\n"
            ."DEPRECATED: enum_exists(): Passing null to parameter #2 (\$autoload) of type bool is deprecated\n"
            ."enum_exists=false\n";
    }

    private function softProbePath(): string
    {
        return dirname(__DIR__).'/repro/maintainer_gap_exists_null_autoload.php';
    }

    private function strictProbePath(): string
    {
        return dirname(__DIR__).'/repro/maintainer_gap_exists_null_autoload_strict.php';
    }

    private function runBin(string $bin, string $src): string
    {
        $repo = dirname(__DIR__, 2);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $src],
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
