<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * flush() VM/AOT smoke (issue #3388).
 */
final class FlushBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
echo function_exists('flush') ? '1' : '0', "\n";
ob_start();
echo 'buf';
flush();
echo 'end';
ob_end_flush();
PHP;

    private const SHUTDOWN_CODE = <<<'PHP'
ob_start();
echo 'chunk';
flush();
echo ob_get_level();
PHP;

    private const SHUTDOWN_AOT_CODE = <<<'PHP'
ob_start();
echo 'chunk';
flush();
PHP;

    private const EXPECT = "1\nbufend";
    private const SHUTDOWN_EXPECT = 'chunk1';
    private const SHUTDOWN_AOT_EXPECT = 'chunk';

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php', self::CODE));
    }

    public function testVmShutdownObFlushMatchesPhpSubset(): void
    {
        $this->assertSame(self::SHUTDOWN_EXPECT, $this->runBin('bin/vm.php', self::SHUTDOWN_CODE));
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
        $this->assertSame(self::EXPECT, $this->runAotBinary(self::CODE));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testAotShutdownObFlushMatchesPhpSubset(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame(self::SHUTDOWN_AOT_EXPECT, $this->runAotBinary(self::SHUTDOWN_AOT_CODE));
    }

    private function runAotBinary(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_flush_');
        $out = $tmp.'_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
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
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_flush_vm_');
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
