<?php

declare(strict_types=1);

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/**
 * @group llvm
 * @group jit
 */
final class JitStrictTypesTest extends TestCase
{
    public function testWeakModeStringToIntCallDoesNotHitNativeCastError(): void
    {
        $this->skipUnlessLlvmReady();
        $stderr = $this->runJitProbeInSubprocess(<<<'PHP'
<?php
function f(int $x) {
    return $x;
}
f('1');
PHP
        );
        self::assertStringNotContainsString(
            'Unsupported cast for arg type int64 from __string__',
            $stderr,
            'JIT should lower string actuals for int parameters (issue #1229)'
        );
    }

    public function testStrictModeStringToIntCallDoesNotHitNativeCastError(): void
    {
        $this->skipUnlessLlvmReady();
        $stderr = $this->runJitProbeInSubprocess(<<<'PHP'
<?php
declare(strict_types=1);
function f(int $x) {
    return $x;
}
f('1');
PHP
        );
        self::assertStringNotContainsString(
            'Unsupported cast for arg type int64 from __string__',
            $stderr,
            'JIT should lower string actuals for int parameters (issue #1229)'
        );
    }

    public function testStrictModeArrayParameterCompiles(): void
    {
        $this->skipUnlessLlvmReady();
        $stderr = $this->runJitProbeInSubprocess(<<<'PHP'
<?php
declare(strict_types=1);
function f(array $cfg) {
    return $cfg['k'] ?? '';
}
echo f(['k' => 'ok']);
PHP
        );
        self::assertStringNotContainsString(
            'Not implemented type conversion',
            $stderr,
            'JIT strict checks must map VM array types for typed parameters (#587)'
        );
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

    private function runJitProbeInSubprocess(string $code): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $sourcePath = tempnam(sys_get_temp_dir(), 'jit_strict_src_');
        $this->assertNotFalse($sourcePath);
        $phpPath = $sourcePath.'.php';
        rename($sourcePath, $phpPath);
        file_put_contents($phpPath, $code);

        $probePath = tempnam(sys_get_temp_dir(), 'jit_strict_probe_');
        $this->assertNotFalse($probePath);
        $probePhp = $probePath.'.php';
        rename($probePath, $probePhp);
        file_put_contents($probePhp, <<<'PROBE'
<?php
require 'test/bootstrap.php';
PHPCompiler\LlvmToolchain::applyCurrentProcessEnv(dirname(__DIR__));
$source = $argv[1];
$code = file_get_contents($source);
$runtime = new PHPCompiler\Runtime();
$block = $runtime->parseAndCompile($code, basename($source));
try {
    $runtime->jit($block);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
PROBE
        );

        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repoRoot);

        $argv = array_merge(
            LlvmToolchain::envPrefix($repoRoot),
            [PHP_BINARY, $probePhp, $phpPath]
        );
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($argv, $descriptorSpec, $pipes, $repoRoot, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($phpPath);
        @unlink($probePhp);

        return $stderr;
    }
}
