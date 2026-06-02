<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * fileatime() / filectime() / fileinode() VM/AOT smoke (issue #3481).
 */
final class FileStatTimesBuiltinTest extends TestCase
{
    private const CODE_OK = <<<'PHP'
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$a1 = fileatime($path);
$a2 = fileatime($path);
$c1 = filectime($path);
$c2 = filectime($path);
$i1 = fileinode($path);
$i2 = fileinode($path);
if ($a1 === false || $a2 === false || $a1 !== $a2) {
    echo 'atime fail', "\n";
} elseif ($c1 === false || $c2 === false || $c1 !== $c2) {
    echo 'ctime fail', "\n";
} elseif ($i1 === false || $i2 === false || $i1 !== $i2 || $i1 <= 0) {
    echo 'inode fail', "\n";
} else {
    echo 'ok', "\n";
}
PHP;

    private const CODE_VM = <<<'PHP'
$path = 'test/compliance/cases/stdlib/readfile_fixture/data.txt';
$a1 = fileatime($path);
$a2 = fileatime($path);
$c1 = filectime($path);
$c2 = filectime($path);
$i1 = fileinode($path);
$i2 = fileinode($path);
if ($a1 === false || $a2 === false || $a1 !== $a2) {
    echo 'atime fail', "\n";
} elseif ($c1 === false || $c2 === false || $c1 !== $c2) {
    echo 'ctime fail', "\n";
} elseif ($i1 === false || $i2 === false || $i1 !== $i2 || $i1 <= 0) {
    echo 'inode fail', "\n";
} else {
    echo 'ok', "\n";
}
if (fileatime('/no/such/phpc-file-stat-times-path')) {
    echo 'bad', "\n";
} else {
    echo 'gone', "\n";
}
PHP;

    private const EXPECT_VM = "ok\ngone\n";

    private const EXPECT_AOT = "ok\n";

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
        $this->assertSame(self::EXPECT_AOT, $this->runAotBinary(self::CODE_OK));
    }

    private function runAotBinary(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_file_stat_times_');
        $out = $tmp . '_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n" . $code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $compile = proc_open(
            ['php', $repo . '/bin/compile.php', '-o', $out, $tmp],
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
        $run = proc_open(
            [$out],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $runPipes,
            $repo,
            $env
        );
        $this->assertIsResource($run);
        fclose($runPipes[0]);
        $result = stream_get_contents($runPipes[1]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $this->assertSame(0, proc_close($run));
        @unlink($tmp);
        @unlink($out);

        return (string) $result;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo . '/' . $bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_file_stat_times_');
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
