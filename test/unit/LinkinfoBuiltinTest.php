<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** linkinfo() VM + AOT compile smoke (issue #6083). */
final class LinkinfoBuiltinTest extends TestCase
{
    private const CODE_VM = <<<'PHP'
$base = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $base . '/link';
$lstat = lstat($link);
$info = linkinfo($link);
echo ($lstat !== false && $info !== false && $info === $lstat['dev']) ? 'ok' : 'fail', "\n";
if (linkinfo('/no/such/phpc-linkinfo-path') === -1) {
    echo 'gone', "\n";
} else {
    echo 'bad', "\n";
}
PHP;

    private const CODE_AOT_LINT = <<<'PHP'
$link = 'test/compliance/cases/stdlib/is_link_fixture/link';
$i1 = linkinfo($link);
$i2 = linkinfo($link);
echo ($i1 > 0 && $i1 === $i2) ? 'ok' : 'fail', "\n";
PHP;

    private const EXPECT_VM = "ok\ngone\n";

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT_VM, $this->runBin('bin/vm.php', self::CODE_VM));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testAotCompileLinkinfoLowering(): void
    {
        $repo = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($repo)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_linkinfo_lint_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n" . self::CODE_AOT_LINT);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            ['php', $repo . '/bin/compile.php', '-l', $tmp],
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
        @unlink($tmp);
        $this->assertSame(0, proc_close($compile), trim((string) $compileErr));
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo . '/' . $bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_linkinfo_vm_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n" . $code);
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
