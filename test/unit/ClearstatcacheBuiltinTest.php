<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * clearstatcache() VM/AOT smoke (#1196).
 */
final class ClearstatcacheBuiltinTest extends TestCase
{
    private const OPTIONAL_CODE = <<<'PHP'
clearstatcache();
clearstatcache(false);
clearstatcache(true, 'test/compliance/cases/stdlib/clearstatcache_fixture.txt');
echo "ok\n";
PHP;

    private const CODE = <<<'PHP'
$path = tempnam(sys_get_temp_dir(), 'phpc_clearstatcache_unit_');
if (!is_string($path)) {
    echo 'notemp', "\n";
    return;
}
touch($path);
$r0 = clearstatcache();
$r1 = clearstatcache(true, $path);
$ok = is_file($path);
@unlink($path);
echo null === $r0 ? 'n0' : 'v0', "\n";
echo null === $r1 ? 'n1' : 'v1', "\n";
echo $ok ? 'file' : 'nofile', "\n";
PHP;

    private const VM_EXPECT = "n0\nn1\nfile";

    private const AOT_CODE = <<<'PHP'
clearstatcache();
clearstatcache(true);
$path = sys_get_temp_dir() . '/phpc_clearstatcache_aot_' . getmypid();
clearstatcache(true, $path);
echo "ok\n";
PHP;

    private const AOT_EXPECT = 'ok';

    private const NAMED_FILENAME_CODE = <<<'PHP'
$path = tempnam(sys_get_temp_dir(), 'phpc_clearstatcache_named_');
if (!is_string($path)) {
    echo "notemp\n";
    return;
}
touch($path);
filesize($path);
clearstatcache(filename: $path);
echo "named_filename_ok\n";
@unlink($path);
PHP;

    public function testVmNamedFilenameOnlyArg(): void
    {
        $this->assertSame('named_filename_ok', $this->runBin('bin/vm.php', self::NAMED_FILENAME_CODE));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testAotNamedFilenameOnlyArg(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame('named_filename_ok', $this->runAotBinary(self::NAMED_FILENAME_CODE));
    }

    public function testVmAcceptsOptionalArgs(): void
    {
        $this->assertSame('ok', $this->runBin('bin/vm.php', self::OPTIONAL_CODE));
    }

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::VM_EXPECT, $this->runBin('bin/vm.php', self::CODE));
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
        $this->assertSame(self::AOT_EXPECT, $this->runAotBinary());
    }

    private function runAotBinary(string $code = self::AOT_CODE): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_clearstatcache_');
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
        $this->assertSame(0, proc_close($run), trim((string) $stderr));
        @unlink($tmp);
        @unlink($out);

        return rtrim((string) $stdout, "\n");
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

        return rtrim((string) $out, "\n");
    }
}
