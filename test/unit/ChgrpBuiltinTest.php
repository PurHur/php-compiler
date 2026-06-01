<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * chgrp() / lchgrp() VM/AOT smoke (issue #3311).
 */
final class ChgrpBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$base = 'test/compliance/cases/stdlib/chmod_fixture';
$path = $base . '/chgrp_unit_data.txt';
if (!is_dir($base)) {
    mkdir($base, 0777, true);
}
if (file_put_contents($path, 'x')) {
    $st = stat($path);
    $gid = (int) ($st['gid'] ?? 0);
    if (chgrp($path, $gid)) {
        echo 'ok', "\n";
    } else {
        echo 'fail', "\n";
    }
    $link = $path . '_lnk';
    if (symlink($path, $link)) {
        if (lchgrp($link, $gid)) {
            echo 'lnk', "\n";
        } else {
            echo 'lnkf', "\n";
        }
        unlink($link);
    }
} else {
    echo 'setup', "\n";
}
if (chgrp('/no/such/phpc-chgrp-path', 0)) {
    echo 'bad', "\n";
} else {
    echo 'nogone', "\n";
}
PHP;

    private const AOT_CODE = <<<'PHP'
$path = 'test/compliance/cases/stdlib/chmod_fixture/chgrp_aot_unit_data.txt';
if (file_put_contents($path, 'x')) {
    $st = stat($path);
    $gid = (int) ($st['gid'] ?? 0);
    if (chgrp($path, $gid)) {
        echo "ok\n";
    } else {
        echo "fail\n";
    }
} else {
    echo "setup\n";
}
PHP;

    private const EXPECT = "ok\nlnk\nnogone\n";
    private const AOT_EXPECT = "ok\n";

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2).'/test/compliance/cases/stdlib/chmod_fixture';
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }
        foreach (['chgrp_unit_data.txt', 'chgrp_aot_unit_data.txt', 'chgrp_aot_data.txt', 'chgrp_data.txt'] as $name) {
            $path = $base.'/'.$name;
            if (is_file($path)) {
                @unlink($path);
            }
            $lnk = $path.'_lnk';
            if (is_link($lnk)) {
                @unlink($lnk);
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
        $this->assertSame(self::AOT_EXPECT, $this->runAotBinary());
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_chgrp_');
        $out = $tmp.'_bin';
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::AOT_CODE);
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
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_chgrp_');
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
