<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * range(..., null) $step — Zend soft-null DEP then ValueError cannot be 0 (#29352).
 *
 * php-src: ext/standard/array.c PHP_FUNCTION(range) / Z_PARAM_NUMBER $step
 *
 * VMTest data-provider is currently blocked by unrelated --EXTENSIONS-- cases;
 * this unit guard runs the issue repro via bin/vm.php + bin/jit.php.
 * AOT fixture: test/fixtures/aot/cases/range_null_step.phpt
 */
final class RangeNullStepSoftNullTest extends TestCase
{
    public function testVmDepThenValueErrorUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "ERR[8192]: range(): Passing null to parameter #3 (\$step) of type int|float is deprecated\n"
            ."ValueError\n"
            ."range(): Argument #3 (\$step) cannot be 0\n",
            $out
        );
    }

    public function testJitDepThenValueErrorUnderProfile84(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "ERR[8192]: range(): Passing null to parameter #3 (\$step) of type int|float is deprecated\n"
            ."ValueError\n"
            ."range(): Argument #3 (\$step) cannot be 0\n",
            $out
        );
    }

    private function probeCode(): string
    {
        return <<<'PHP'
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
try {
    range(0, 2, null);
    echo "ok\n";
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
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_range_null_step_');
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
