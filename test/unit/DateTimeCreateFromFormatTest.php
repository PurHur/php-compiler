<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/** DateTime::createFromFormat() VM + AOT compile smoke (#9921, ext/date/php_datetime.c). */
final class DateTimeCreateFromFormatTest extends TestCase
{
    private const CODE_VM = <<<'PHP'
$bad = DateTime::createFromFormat('Y', 'notadate');
var_export($bad);
echo "\n";
$ok = DateTime::createFromFormat('Y-m-d', '2024-06-05');
var_export($ok !== false);
echo "\n";
echo $ok->format('Y-m-d'), "\n";
echo get_class($ok), "\n";
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
        $target = $repo.'/test/fixtures/aot/compile-only/datetime_create_from_format_mutable.php';
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

    public function testVmMutableCreateFromFormat(): void
    {
        $out = $this->runBin('bin/vm.php', self::CODE_VM);
        $this->assertStringContainsString('false', $out);
        $this->assertStringContainsString('true', $out);
        $this->assertStringContainsString('2024-06-05', $out);
        $this->assertStringContainsString('DateTime', $out);
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_dt_cff_vm_');
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
