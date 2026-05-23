<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * mkdir() VM/AOT smoke.
 */
final class MkdirBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$base = 'test/compliance/cases/stdlib/mkdir_fixture';
$one = $base . '/unit_one';
if (mkdir($one, 0755)) {
    if (is_dir($one)) {
        echo "ok\n";
    } else {
        echo "bad\n";
    }
} else {
    echo "fail\n";
}
$nested = $base . '/unit/nested';
if (mkdir($nested, 0777, true)) {
    if (is_dir($nested)) {
        echo "rec\n";
    } else {
        echo "badrec\n";
    }
} else {
    echo "failrec\n";
}
PHP;

    private const AOT_CODE = <<<'PHP'
$one = 'test/compliance/cases/stdlib/mkdir_fixture/aot_unit_one';
if (mkdir($one)) {
    if (is_dir($one)) {
        echo "ok\n";
    } else {
        echo "bad\n";
    }
} else {
    echo "fail\n";
}
$nested = 'test/compliance/cases/stdlib/mkdir_fixture/aot_unit_nested/deep';
if (mkdir($nested, 0777, true)) {
    if (is_dir($nested)) {
        echo "rec\n";
    } else {
        echo "badrec\n";
    }
} else {
    echo "failrec\n";
}
PHP;

    private const EXPECT = "ok\nrec\n";
    private const AOT_EXPECT = "ok\nrec\n";

    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2).'/test/compliance/cases/stdlib/mkdir_fixture';
        if (!is_dir($base)) {
            mkdir($base, 0777, true);
        }
        foreach (['unit_one', 'unit/nested', 'aot_unit_one', 'aot_unit_nested'] as $rel) {
            $path = $base.'/'.$rel;
            if (is_dir($path)) {
                self::removeTree($path);
            } elseif (is_file($path)) {
                @unlink($path);
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

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if (false === $items) {
            return;
        }
        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $full = $path.'/'.$item;
            if (is_dir($full)) {
                self::removeTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }

    private function runAotBinary(): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_mkdir_');
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
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_mkdir_');
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
