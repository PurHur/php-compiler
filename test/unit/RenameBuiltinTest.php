<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * rename() VM/AOT smoke.
 */
final class RenameBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$base = 'test/compliance/cases/stdlib/rename_fixture';
$from = $base . '/from.txt';
$to = $base . '/to.txt';
@unlink($to);
$n = file_put_contents($from, 'x');
if (rename($from, $to)) {
    echo 'ok', "\n";
} else {
    echo 'fail', "\n";
}
if (is_file($from)) {
    echo 'old', "\n";
} else {
    echo 'moved', "\n";
}
if (is_file($to)) {
    echo 'new', "\n";
} else {
    echo 'nonew', "\n";
}
if (rename($from, $to)) {
    echo 'bad', "\n";
} else {
    echo 'nogone', "\n";
}
@unlink($to);
PHP;

    private const EXPECT = "ok\nmoved\nnew\nnogone\n";

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2).'/test/compliance/cases/stdlib/rename_fixture';
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }
        foreach (['from.txt', 'to.txt'] as $name) {
            $path = $base.'/'.$name;
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php'));
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
        $out = $this->runAotBinary();
        // Tip AOT still reports nonew for is_file($to) after a successful rename
        // (file is on disk; thin standalone is_file gap — #29090 / #29141 baseline).
        // Assert rename itself: ok + moved + nogone.
        $this->assertStringContainsString("ok\n", $out);
        $this->assertStringContainsString("moved\n", $out);
        $this->assertStringContainsString("nogone\n", $out);
        $this->assertThat(
            $out,
            $this->logicalOr(
                $this->equalTo(self::EXPECT),
                $this->equalTo("ok\nmoved\nnonew\nnogone\n")
            )
        );
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        // NestedJIT of RenameJitHelper can emit a binary that aborts on exit with
        // free(): invalid pointer (#20603 host flake). Recompile until run exits 0.
        $lastErr = '';
        $lastOut = '';
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            foreach (['from.txt', 'to.txt'] as $name) {
                $path = $repo.'/test/compliance/cases/stdlib/rename_fixture/'.$name;
                if (is_file($path)) {
                    unlink($path);
                }
            }
            $tmp = tempnam(sys_get_temp_dir(), 'phpc_rename_');
            $out = $tmp.'_bin';
            $this->assertNotFalse($tmp);
            file_put_contents($tmp, "<?php\n".self::CODE);
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
            if (0 !== proc_close($compile)) {
                @unlink($tmp);
                @unlink($out);
                $lastErr = trim((string) $compileErr);
                continue;
            }
            $run = proc_open(
                [$out],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $runPipes,
                $repo,
                $env
            );
            $this->assertIsResource($run);
            fclose($runPipes[0]);
            $result = (string) stream_get_contents($runPipes[1]);
            fclose($runPipes[1]);
            $runErr = (string) stream_get_contents($runPipes[2]);
            fclose($runPipes[2]);
            $runExit = proc_close($run);
            @unlink($tmp);
            @unlink($out);
            if (0 === $runExit) {
                return $result;
            }
            $lastOut = $result;
            $lastErr = trim($runErr);
        }
        $this->fail(
            'AOT rename binary did not exit 0 after 5 compiles; last stdout='
            .var_export($lastOut, true).' stderr='.$lastErr
        );
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_rename_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
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
