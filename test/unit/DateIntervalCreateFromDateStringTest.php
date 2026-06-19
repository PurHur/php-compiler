<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/** date_interval_create_from_date_string() VM + AOT compile smoke (#4606). */
final class DateIntervalCreateFromDateStringTest extends TestCase
{
    private const CODE_VM = <<<'PHP'
$iv = date_interval_create_from_date_string('1 day');
echo ($iv instanceof DateInterval) ? 'ok' : 'bad', "\n";
echo $iv->format('%d'), "\n";
$combo = date_interval_create_from_date_string('1 day 2 hours');
echo $combo->d, ':', $combo->h, "\n";
$plus = date_interval_create_from_date_string('1 day + 2 hours');
echo $plus->d, ':', $plus->h, "\n";
$minus = date_interval_create_from_date_string('1 day - 2 hours');
echo $minus->d, ':', $minus->h, "\n";
PHP;

    /**
     * @group llvm
     * @group jit
     */
    public function testAotCompileLowering(): void
    {
        $repo = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($repo)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $target = $repo.'/test/fixtures/aot/compile-only/date_interval_create_from_date_string.php';
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            ['php', $repo.'/bin/compile.php', '-l', $target],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $compileErr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($compile), trim((string) $compileErr));
    }

    public function testVmRelativeIntervalParsing(): void
    {
        $this->assertSame("ok\n1\n1:2\n1:2\n1:-2\n", $this->runBin('bin/vm.php', self::CODE_VM));
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_di_create_vm_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(['php', $path, $tmp], $descriptor, $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        @unlink($tmp);
        $this->assertSame(0, proc_close($proc));

        return (string) $out;
    }
}
