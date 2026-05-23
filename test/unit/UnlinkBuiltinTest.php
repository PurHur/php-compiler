<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * unlink() VM/AOT smoke.
 */
final class UnlinkBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$path = 'test/compliance/cases/stdlib/unlink_fixture.txt';
if (unlink($path)) {
    echo 'ok', "\n";
} else {
    echo 'fail', "\n";
}
if (file_exists($path)) {
    echo 'still', "\n";
} else {
    echo 'gone', "\n";
}
$missing = '/no/such/phpc-unlink-path';
if (unlink($missing)) {
    echo 'bad', "\n";
} else {
    echo 'no', "\n";
}
PHP;

    private const EXPECT = "ok\ngone\nno\n";

    protected function setUp(): void
    {
        parent::setUp();
        $path = dirname(__DIR__, 2).'/test/compliance/cases/stdlib/unlink_fixture.txt';
        @unlink($path);
        file_put_contents($path, 'x');
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
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_unlink_');
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
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_unlink_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
        $proc = proc_open(
            ['php', $repo.'/'.$bin, $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc));
        @unlink($tmp);

        return (string) $out;
    }
}
