<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * readlink() VM/AOT smoke (#28425 AOT missing-path + Reflection peer).
 */
final class ReadlinkBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$base = 'test/compliance/cases/stdlib/is_link_fixture';
$link = $base . '/link';
$file = $base . '/target.txt';
echo readlink($link), "\n";
if (!is_link($file)) {
    echo 'notlink', "\n";
} else {
    echo 'bad', "\n";
}
if (!is_link('/no/such/phpc-readlink-path')) {
    echo 'gone', "\n";
} else {
    echo 'badgone', "\n";
}
PHP;

    private const EXPECT = "target.txt\nnotlink\ngone\n";

    private const AOT_MISSING_CODE = <<<'PHP'
$missing = @readlink('/no/such/phpc-readlink-path');
echo (false === $missing) ? "false\n" : ("bad:" . gettype($missing) . "\n");
PHP;

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
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php', self::CODE));
    }

    /**
     * AOT: missing path → false (bridge compiles; NestedJIT success leaf is follow-up).
     *
     * @group llvm
     * @group jit
     */
    public function testAotMissingPathReturnsFalse(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame("false\n", $this->runAotBinary(self::AOT_MISSING_CODE));
    }

    private function runAotBinary(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_readlink_');
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
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_readlink_');
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
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc));
        @unlink($tmp);

        return (string) $out;
    }
}
