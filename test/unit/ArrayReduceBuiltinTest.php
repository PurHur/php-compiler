<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** array_reduce() VM smoke (issue #1213). */
final class ArrayReduceBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
echo array_reduce([2, 3], 'pow'), "\n";
echo array_reduce([1, 2, 3], 'pow', 10), "\n";
echo array_reduce([], 'pow') === null ? 'null' : 'other', "\n";
echo array_reduce([], 'pow', 0), "\n";
PHP;

    public function testVmReduceWithPow(): void
    {
        $this->assertSame("8\n1000000\nnull\n0\n", $this->runBin('bin/vm.php', self::CODE));
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_array_reduce_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(['php', $path, $tmp], $descriptor, $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, trim((string) $err));

        return (string) $out;
    }
}
