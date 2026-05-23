<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * lstat() VM/AOT smoke (#1198).
 */
final class LstatBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$base = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $base . '/link';
$stat = stat($link);
$lstat = lstat($link);
echo ($stat !== false && $lstat !== false && $stat['size'] !== $lstat['size']) ? 'symlink' : 'fail', "\n";
echo ($lstat !== false) ? $lstat['size'] : 'fail', "\n";
PHP;

    private const EXPECT = <<<'TXT'
symlink
10
TXT;

    /** AOT compile path cannot lower array|false !== false yet (issue #211). */
    private const AOT_CODE = <<<'PHP'
$base = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $base . '/link';
$stat = stat($link);
$lstat = lstat($link);
echo ($stat['size'] !== $lstat['size']) ? 'symlink' : 'fail', "\n";
echo $lstat['size'], "\n";
PHP;

    private const AOT_EXPECT = <<<'TXT'
symlink
10
TXT;

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php', self::CODE));
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
        $this->assertSame(self::AOT_EXPECT, $this->runAotBinary(self::AOT_CODE));
    }

    private function runAotBinary(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_lstat_');
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

        return $this->normalize((string) $result);
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo . '/' . $bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_lstat_');
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

        return $this->normalize((string) $out);
    }

    private function normalize(string $text): string
    {
        return preg_replace('/\r\n?/', "\n", trim($text)) ?? '';
    }
}
