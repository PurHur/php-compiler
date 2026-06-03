<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * stream_get_contents() / get_resource_type() VM/AOT smoke (#3142).
 */
final class StreamGetContentsBuiltinTest extends TestCase
{
    private const CODE_VM = <<<'PHP'
$f = fopen('php://memory', 'r+');
echo function_exists('stream_get_contents') ? '1' : '0';
echo function_exists('get_resource_type') ? '1' : '0', "\n";
echo get_resource_type($f), "\n";
echo stream_get_contents($f, 0), 'ok';
fclose($f);
PHP;

    private const CODE_AOT = <<<'PHP'
$path = sys_get_temp_dir() . '/phpc_sgc_unit_' . (string) getmypid() . '.txt';
file_put_contents($path, 'alphabeta');
$h = fopen($path, 'r');
echo function_exists('stream_get_contents') ? '1' : '0';
echo function_exists('get_resource_type') ? '1' : '0', "\n";
echo get_resource_type($h), "\n";
echo stream_get_contents($h, 4, 5);
fclose($h);
@unlink($path);
PHP;

    private const EXPECT_VM = "11\nstream\nok";

    private const EXPECT_AOT = "11\nstream\nbeta";

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT_VM, $this->runBin('bin/vm.php', self::CODE_VM));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testAotNativeBinaryMatchesPhpSubset(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame(self::EXPECT_AOT, $this->runAotBinary());
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_sgc_');
        $out = $tmp.'_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE_AOT);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            ['php', $repo.'/bin/compile.php', '-o', $out, $tmp],
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
        $this->assertFileExists($out);

        $run = proc_open(
            [$out],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $runExit = proc_close($run);
        @unlink($tmp);
        @unlink($out);
        $this->assertSame(0, $runExit, trim((string) $stderr));

        return preg_replace('/\r\n?/', "\n", trim((string) $stdout)) ?? '';
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_sgc_vm_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, trim((string) $err));

        return preg_replace('/\r\n?/', "\n", trim((string) $out)) ?? '';
    }
}
