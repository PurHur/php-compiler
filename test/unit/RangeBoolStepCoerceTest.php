<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * range(..., true/false) $step — Zend Z_PARAM_NUMBER bool coerce (#29505).
 *
 * php-src: ext/standard/array.c PHP_FUNCTION(range) / Z_PARAM_NUMBER $step
 *
 * AOT fixture: test/fixtures/aot/cases/range_bool_step.phpt
 */
final class RangeBoolStepCoerceTest extends TestCase
{
    public function testVmTrueCoercesToOneUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "1,2,3,4,5\n"
            ."ValueError\n"
            ."range(): Argument #3 (\$step) cannot be 0\n",
            $out
        );
    }

    public function testJitTrueCoercesToOneUnderProfile84(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "1,2,3,4,5\n"
            ."ValueError\n"
            ."range(): Argument #3 (\$step) cannot be 0\n",
            $out
        );
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
try {
    echo implode(',', range(1, 5, true)), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    echo implode(',', range(1, 5, false)), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
PHP;
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function runBin(string $bin, string $code, array $extraEnv): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_range_bool_step_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $env = $_ENV;
        foreach ($extraEnv as $k => $v) {
            if ('' === $v) {
                unset($env[$k]);
            } else {
                $env[$k] = $v;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, (string) $err.(string) $out);

        return (string) $out;
    }
}
