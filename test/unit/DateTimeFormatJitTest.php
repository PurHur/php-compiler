<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/** DateTime::format() VM + AOT compile smoke (#4043, ext/date/php_datetime.c). */
final class DateTimeFormatJitTest extends TestCase
{
    private const CODE = <<<'PHP'
$dt = new DateTime('2024-01-15 12:00:00', new DateTimeZone('UTC'));
echo $dt->format('Y-m-d'), "\n";
$di = new DateTimeImmutable('2024-06-05 08:00:00', new DateTimeZone('UTC'));
echo $di->format('Y-m-d H:i:s'), "\n";
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
        $target = $repo.'/test/fixtures/aot/compile-only/datetime_format_basic.php';
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

    /**
     * @group llvm
     * @group jit
     */
    public function testJitFormat(): void
    {
        $out = $this->runBin('bin/jit.php', self::CODE);
        $this->assertStringContainsString('2024-01-15', $out);
        $this->assertStringContainsString('2024-06-05 08:00:00', $out);
    }

    public function testVmFormat(): void
    {
        $out = $this->runBin('bin/vm.php', self::CODE);
        $this->assertStringContainsString('2024-01-15', $out);
        $this->assertStringContainsString('2024-06-05 08:00:00', $out);
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_dt_fmt_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(['php', $path, $tmp], $descriptor, $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        @unlink($tmp);
        $this->assertSame(0, proc_close($proc));

        return trim((string) $stderr).("\n" !== trim((string) $stderr) && '' !== trim((string) $out) ? "\n" : '').(string) $out;
    }
}
