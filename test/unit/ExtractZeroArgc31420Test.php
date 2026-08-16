<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * extract() wrong argc → ArgumentCountError (#31420).
 *
 * php-src: ext/standard/array.c ZEND_PARSE_PARAMETERS_START(1, 3)
 */
final class ExtractZeroArgc31420Test extends TestCase
{
    public function testVmZeroAndExcessArgc(): void
    {
        $out = $this->runBin('bin/vm.php');
        $this->assertSame($this->expected(), $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }

    public function testJitZeroAndExcessArgc(): void
    {
        $out = $this->runBin('bin/jit.php');
        $this->assertSame($this->expected(), $out);
        $this->assertStringNotContainsString('LogicException', $out);
        $this->assertStringNotContainsString('in this compiler build', $out);
    }

    private function expected(): string
    {
        return "ArgumentCountError:extract() expects at least 1 argument, 0 given\n"
            ."ArgumentCountError:extract() expects at most 3 arguments, 4 given\n";
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/maintainer_gap_extract_zero_argc.php';
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
        $code = proc_close($proc);
        $this->assertSame(0, $code, $stdout.$stderr);

        return $stdout;
    }
}
