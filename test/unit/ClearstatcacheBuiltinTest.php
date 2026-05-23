<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** clearstatcache() VM/JIT smoke (issue #1196). */
final class ClearstatcacheBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
clearstatcache();
clearstatcache(false);
clearstatcache(true, 'test/compliance/cases/stdlib/clearstatcache_fixture.txt');
echo "ok\n";
PHP;

    public function testVmAcceptsOptionalArgs(): void
    {
        $this->assertSame("ok\n", $this->runBin('bin/vm.php', self::CODE));
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_clearstatcache_');
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
