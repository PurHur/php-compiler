<?php

declare(strict_types=1);

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/**
 * LLVM AOT compile gates for bootstrap assignOperand / Runtime class-const blockers (#1074).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class BootstrapAotJitCompileTest extends TestCase
{
    public function testRuntimeModeAotClassConstFetchCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $source = <<<'PHP'
<?php
declare(strict_types=1);
function bootstrap_runtime_mode(): int {
    return \PHPCompiler\Runtime::MODE_AOT;
}
echo bootstrap_runtime_mode(), "\n";
PHP;
        $this->assertCompileExitZero($source, 'Runtime::MODE_AOT class const fetch');
    }

    public function testNativeStringArrayIntoMixedValueBoxCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $source = <<<'PHP'
<?php
declare(strict_types=1);
function bootstrap_is_array_check(): string {
    $items = ['a', 'b', 'c'];
    return is_array($items) ? '1' : '0';
}
echo bootstrap_is_array_check(), "\n";
PHP;
        $stderr = $this->compileSource($source, 'native string array is_array');
        $this->assertStringNotContainsString('Source type: 196', $stderr);
    }

    private function skipUnlessLlvmReady(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(
                LlvmToolchain::readyFailureReason()
                ?? 'LLVM 9 toolchain not available. Run script/install-llvm9.sh from the repository root.'
            );
        }
    }

    private function assertCompileExitZero(string $source, string $label): void
    {
        $stderr = $this->compileSource($source, $label);
        $this->assertStringNotContainsString('Undefined class constant: MODE_AOT', $stderr);
    }

    private function compileSource(string $source, string $label): string
    {
        $tmpPhp = tempnam(sys_get_temp_dir(), 'bootstrap_aot_src_');
        $this->assertNotFalse($tmpPhp);
        $sourcePath = $tmpPhp.'.php';
        rename($tmpPhp, $sourcePath);
        file_put_contents($sourcePath, $source);

        $outfile = tempnam(sys_get_temp_dir(), 'bootstrap_aot_out_');
        $this->assertNotFalse($outfile);
        unlink($outfile);

        $repoRoot = dirname(__DIR__, 2);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $compileArgv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $repoRoot.'/bin/compile.php', '-o', $outfile, $sourcePath]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $compile = proc_open($compileArgv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($compile);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($compile);
        @unlink($sourcePath);
        if (is_file($outfile)) {
            @unlink($outfile);
        }
        $stderr = trim($stderr !== false ? $stderr : '');
        $this->assertSame(0, $exitCode, $label.' AOT compile failed: '.$stderr);

        return $stderr;
    }
}
