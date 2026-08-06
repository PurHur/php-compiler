<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/**
 * AOT: successful property write inside try must reach code after the try (#28078).
 *
 * DynamicObjectReadonlyGuard splits the try-body LLVM block; beginTry must seal the
 * live compileSubBlock tail (not only blockStorage[tryBody]), or sealFunction emits
 * `ret void` and skips the merge (`echo "after"` / isFinal).
 *
 * @group llvm
 */
final class TryCatchPropertyWriteAotTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        if (!LlvmToolchain::isReady($this->repoRoot)) {
            $reason = LlvmToolchain::readyFailureReason() ?? 'LLVM 9 toolchain not available';
            $this->markTestSkipped($reason.' — try/property AOT test needs LLVM');
        }
    }

    public function testPropertyWriteInsideTryContinuesAfter(): void
    {
        $src = <<<'PHP'
<?php
class A { public $x = 1; }
$a = new A();
try { $a->x = 2; echo "wrote\n"; } catch (Throwable $e) { echo "err\n"; }
echo "after\n";
PHP;
        $this->assertAotSourceOutput($src, "wrote\nafter\n");
    }

    public function testFinalPlainPropertyTryWriteIsFinalUnderProfile84(): void
    {
        $repro = $this->repoRoot.'/test/repro/maintainer_gap_final_plain_property_try_84.php';
        $this->assertFileExists($repro);
        $this->assertAotFileOutput($repro, "wrote\nisFinal=1\n", ['PHP_COMPILER_PROFILE' => '8.4']);
    }

    /** @param array<string, string> $extraEnv */
    private function assertAotSourceOutput(string $source, string $expected, array $extraEnv = []): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpc_try_prop_src_');
        $this->assertNotFalse($path);
        $path .= '.php';
        file_put_contents($path, $source);
        try {
            $this->assertAotFileOutput($path, $expected, $extraEnv);
        } finally {
            @unlink($path);
        }
    }

    /** @param array<string, string> $extraEnv */
    private function assertAotFileOutput(string $path, string $expected, array $extraEnv = []): void
    {
        $out = tempnam(sys_get_temp_dir(), 'phpc_try_prop_aot_');
        $this->assertNotFalse($out);
        $env = array_merge($this->llvmEnv(), $extraEnv);
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open(
            [PHP_BINARY, $this->repoRoot.'/bin/compile.php', '-o', $out, $path],
            $descriptorSpec,
            $pipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, 'AOT compile failed: '.$stderr);
        $this->assertFileExists($out);
        $run = proc_open(
            [$out],
            $descriptorSpec,
            $runPipes,
            $this->repoRoot,
            $env
        );
        $this->assertIsResource($run);
        fclose($runPipes[0]);
        $stdout = stream_get_contents($runPipes[1]);
        $runErr = stream_get_contents($runPipes[2]);
        fclose($runPipes[1]);
        fclose($runPipes[2]);
        $runCode = proc_close($run);
        @unlink($out);
        $this->assertSame(0, $runCode, 'AOT binary failed: '.$runErr);
        $this->assertSame($expected, $stdout);
    }

    /** @return array<string, string> */
    private function llvmEnv(): array
    {
        $env = $_ENV;
        foreach (['PATH', 'HOME', 'TMPDIR', 'TMP', 'TEMP', 'PHP_COMPILER_LLVM_PATH', 'PHP_COMPILER_PROFILE'] as $key) {
            $v = getenv($key);
            if (false !== $v && '' !== $v) {
                $env[$key] = $v;
            }
        }

        return $env;
    }
}
