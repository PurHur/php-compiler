<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * filetype() VM/AOT smoke.
 */
final class FiletypeBuiltinTest extends TestCase
{
    private const CODE_OK = <<<'PHP'
$linkBase = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $linkBase . '/link';
$file = $linkBase . '/target.txt';
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
echo filetype($link), "\n";
echo filetype($file), "\n";
echo filetype($dir), "\n";
PHP;

    private const CODE_VM = <<<'PHP'
$linkBase = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $linkBase . '/link';
$file = $linkBase . '/target.txt';
$dir = 'test/compliance/cases/stdlib/glob_scandir_fixture';
echo filetype($link), "\n";
echo filetype($file), "\n";
echo filetype($dir), "\n";
$gone = filetype('/no/such/phpc-filetype-path');
if ($gone == false) {
    echo 'gone', "\n";
} else {
    echo 'bad', "\n";
}
PHP;

    private const EXPECT_VM = "link\nfile\ndir\ngone\n";

    private const EXPECT_AOT = "link\nfile\ndir\n";

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2).'/test/compliance/cases/stdlib/is_link_fixture';
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }
        file_put_contents($base.'/target.txt', 'ok');
        $link = $base.'/link';
        if (is_link($link)) {
            unlink($link);
        }
        symlink('target.txt', $link);
    }

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
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_filetype_');
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
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_filetype_');
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
