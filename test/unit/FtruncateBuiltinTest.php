<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ftruncate() VM/AOT smoke (#3256).
 */
final class FtruncateBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$path = sys_get_temp_dir() . '/phpc_ftruncate_unit.txt';
$fp = fopen($path, 'w+');
$nw = fwrite($fp, 'aaaaaaaaaa');
rewind($fp);
echo ftruncate($fp, 4) ? 'ok' : 'no', "\n";
fclose($fp);
echo file_get_contents($path), "\n";
@unlink($path);
PHP;

    private const EXPECT = "ok\naaaa";

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php', self::CODE));
    }

    /** Issue #12622 — negative literal size must throw ValueError (not mis-wire stream arg). */
    public function testNegativeLiteralSizeThrowsValueError(): void
    {
        $code = <<<'PHP'
$path = sys_get_temp_dir() . '/phpc_ftruncate_neg_unit.txt';
$fp = fopen($path, 'w+');
try {
    ftruncate($fp, -1);
    echo "fail\n";
} catch (ValueError $e) {
    echo ($e->getMessage() === 'ftruncate(): Argument #2 ($size) must be greater than or equal to 0') ? "ok\n" : 'msg:'.$e->getMessage()."\n";
}
fclose($fp);
@unlink($path);
PHP;
        $this->assertSame('ok', $this->runBin('bin/vm.php', $code));
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
        $this->assertSame(self::EXPECT, $this->runAotBinary());
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_ftruncate_');
        $out = $tmp.'_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
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
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_ftruncate_vm_');
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
