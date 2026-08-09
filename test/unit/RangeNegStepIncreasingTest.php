<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * range() increasing + negative step → Zend ValueError under PROFILE≥8.3 (#29351).
 */
final class RangeNegStepIncreasingTest extends TestCase
{
    public function testVmThrowsUnderProfile84(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "ValueError\n"
            ."range(): Argument #3 (\$step) must be greater than 0 for increasing ranges\n"
            ."desc=5,4,3,2,1\n"
            ."eq=5\n",
            $out
        );
    }

    public function testVmLegacyFlipOnDefaultProfile(): void
    {
        $out = $this->runBin('bin/vm.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '']);
        $this->assertSame(
            "inc=1,2,3,4,5\n"
            ."desc=5,4,3,2,1\n"
            ."eq=5\n",
            $out
        );
    }

    public function testJitThrowsUnderProfile84(): void
    {
        $out = $this->runBin('bin/jit.php', $this->probeCode(), ['PHP_COMPILER_PROFILE' => '8.4']);
        $this->assertSame(
            "ValueError\n"
            ."range(): Argument #3 (\$step) must be greater than 0 for increasing ranges\n"
            ."desc=5,4,3,2,1\n"
            ."eq=5\n",
            $out
        );
    }

    private function probeCode(): string
    {
        return <<<'PHP'
try {
    $inc = range(1, 5, -1);
    echo 'inc=', implode(',', $inc), "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
echo 'desc=', implode(',', range(5, 1, 1)), "\n";
echo 'eq=', implode(',', range(5, 5, -1)), "\n";
PHP;
    }

    /**
     * @param array<string, string> $extraEnv
     */
    private function runBin(string $bin, string $code, array $extraEnv): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_range_neg_');
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
        $this->assertSame(0, $exit, (string) $err);

        return (string) $out;
    }
}
