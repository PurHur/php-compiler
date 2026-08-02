<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * fmod() VM/AOT smoke (#26994 — AOT must not return 0 via VmMath recursion).
 *
 * Multi-call UnaryMinus-first-arg sequences can still mis-wire under AOT
 * (second negative literal → 0); covered by {@see FmodAfterRoundTest} / #15736,
 * not this kernel bridge fix.
 */
final class FmodBuiltinTest extends TestCase
{
    private const CODE = <<<'PHP'
echo fmod(5.7, 1.3), "\n";
echo fmod(5.5, 2.0), "\n";
echo fmod(0, 3), "\n";
PHP;

    private const EXPECT = "0.5\n1.5\n0\n";

    private const NEG_CODE = <<<'PHP'
echo fmod(-1.5, 1.2), "\n";
PHP;

    private const NEG_EXPECT = "-0.3\n";

    public function testVmMatchesPhpSubset(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php', self::CODE));
    }

    public function testVmNegativeLiteralMatchesPhpSubset(): void
    {
        $this->assertSame(self::NEG_EXPECT, $this->runBin('bin/vm.php', self::NEG_CODE));
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
    public function testAotNegativeLiteralMatchesPhpSubset(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertSame(self::NEG_EXPECT, $this->runAotBinary(self::NEG_CODE));
    }

    private function runAotBinary(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_fmod_');
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

        return $this->normalize((string) $result);
    }

    private function runBin(string $bin, string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $path = $repo.'/'.$bin;
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_fmod_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".$code);
        $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(['php', $path, $tmp], $descriptor, $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $result = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc));
        @unlink($tmp);

        return $this->normalize((string) $result);
    }

    private function normalize(string $output): string
    {
        return str_replace("\r\n", "\n", $output);
    }
}
