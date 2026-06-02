<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * symlink() VM/AOT smoke (issue #3227).
 */
final class SymlinkBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
echo function_exists('symlink') ? '1' : '0', "\n";
$base = 'test/compliance/cases/stdlib/symlink_fixture';
$src = $base . '/target.txt';
$link = $base . '/sym';
$_u0 = @unlink($link);
$_u1 = @unlink($src);
$n = file_put_contents($src, 'data');
$ok = symlink('target.txt', $link);
if ($ok) {
    echo readlink($link), "\n";
    echo file_get_contents($link), "\n";
} else {
    echo 'fail', "\n";
}
$_u2 = @unlink($link);
$_u3 = @unlink($src);
@unlink($base . '/nope.txt');
$bad = symlink('/no/such/phpc-symlink-src', $base . '/nope.txt');
if ($bad) {
    echo 'bad', "\n";
} else {
    echo 'nogone', "\n";
}
PHP;

    private const EXPECT = "1\ntarget.txt\ndata\nbad\n";

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('symlink')) {
            $this->markTestSkipped('symlinks unavailable on this platform');
        }
        $base = dirname(__DIR__, 2).'/test/compliance/cases/stdlib/symlink_fixture';
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }
        foreach (['target.txt', 'sym', 'nope.txt'] as $name) {
            $path = $base.'/'.$name;
            if (is_link($path) || is_file($path)) {
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
        $this->assertSame(self::EXPECT, $this->runAotBinary());
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_symlink_');
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

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_symlink_');
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
