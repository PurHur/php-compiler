<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * preg_split() VM/AOT smoke (#1178, #27647).
 */
final class PregSplitBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
$parts = preg_split('/\s+/', 'one two  three');
echo count($parts), "\n";
echo $parts[0], '|', $parts[1], '|', $parts[2], "\n";
$bad = preg_split('(bad[', 'x');
echo $bad === false ? 'false' : 'bad', "\n";
PHP;

    private const EXPECT = "3\none|two|three\nfalse\n";

    /** Issue #27647 — PREG_SPLIT_OFFSET_CAPTURE must fold under thin AOT. */
    private const OFFSET_CAPTURE_CODE = <<<'PHP'
$parts = preg_split('/a/', 'xay', -1, PREG_SPLIT_OFFSET_CAPTURE);
echo $parts[0][0], ':', $parts[0][1], '|', $parts[1][0], ':', $parts[1][1], "\n";
PHP;

    private const OFFSET_CAPTURE_EXPECT = "x:0|y:2\n";

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php', self::CODE));
    }

    public function testVmOffsetCaptureMatchesPhpSubset(): void
    {
        $this->assertSame(
            self::OFFSET_CAPTURE_EXPECT,
            $this->runBin('bin/vm.php', self::OFFSET_CAPTURE_CODE)
        );
    }

    /** Issue #27946 — unknown flag bits masked; DELIM_CAPTURE without () is a no-op. */
    public function testVmUnknownFlagsMaskedLikeZend(): void
    {
        $code = <<<'PHP'
echo json_encode(preg_split('/a/', 'a', -1, 999)), "\n";
echo json_encode(preg_grep('/a/', [1, 'a'], 999)), "\n";
echo json_encode(preg_grep('/a/', [1, 'a'], 998)), "\n";
try {
    $m = null;
    preg_match('/a/', 'a', $m, 999);
    echo "match:ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
PHP;
        $expect = "[]\n[1]\n{\"1\":\"a\"}\nValueError\n";
        $this->assertSame($expect, $this->runBin('bin/vm.php', $code));
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
        $this->assertSame(self::EXPECT, $this->runAotBinary(self::CODE));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testAotOffsetCaptureMatchesPhpSubset(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame(
            self::OFFSET_CAPTURE_EXPECT,
            $this->runAotBinary(self::OFFSET_CAPTURE_CODE)
        );
    }

    private function runAotBinary(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_preg_split_');
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
        $stderr = stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($compile), $stderr);
        $this->assertStringNotContainsString('compileTimeInt', $stderr);

        $run = proc_open(
            [$out],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($run);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($run));
        @unlink($tmp);
        @unlink($out);

        return $stdout;
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_preg_split_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n" . $code);
        $proc = proc_open(
            ['php', $repo . '/' . $bin, $tmp],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc));
        @unlink($tmp);

        return $stdout;
    }
}
