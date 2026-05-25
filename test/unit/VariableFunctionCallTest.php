<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Variable function calls ($fn()) — issue #56. */
final class VariableFunctionCallTest extends TestCase
{
    private const CODE = <<<'PHP'
$fn = 'strlen';
echo $fn('hi'), "\n";

function greet(string $name): string
{
    return 'hi '.$name;
}
$g = 'greet';
echo $g('bob'), "\n";
PHP;

    private const EXPECT = <<<'TXT'
2
hi bob

TXT;

    public function testVmBuiltinVariableFunctionCall(): void
    {
        $code = <<<'PHP'
<?php
$fn = 'strlen';
echo $fn('abc');
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('3', ob_get_clean());
    }

    public function testVmUndefinedVariableFunctionThrows(): void
    {
        $code = <<<'PHP'
<?php
$fn = 'not_a_real_function_xyz';
$fn();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Call to undefined function not_a_real_function_xyz()');
        $rt->run($block);
    }

    public function testVmVariableFunctionCompliance(): void
    {
        $this->assertSame(self::EXPECT, $this->runBin('bin/vm.php'));
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testJitDynamicVariableFunctionCallCompiles(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM not available');
        }
        $code = <<<'PHP'
<?php
$name = $_GET['op'] ?? 'strlen';
echo $name('hi');
PHP;
        $stderr = $this->runJitCompileProbe($code);
        $this->assertStringNotContainsString('Variable function calls not yet supported', $stderr);
    }

    /**
     * @group llvm
     * @group jit
     */
    public function testAotLintVariableFunctionCall(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped(LlvmToolchain::readyFailureReason() ?? 'LLVM not available');
        }
        $repo = dirname(__DIR__, 2);
        $bin = realpath($repo.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repo);
        $cmd = array_merge(
            LlvmToolchain::envPrefix($repo),
            [PHP_BINARY, $bin, '-l']
        );
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fwrite($pipes[0], "<?php\n".self::CODE);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, trim((string) $stderr));
        $this->assertStringNotContainsString('Variable function calls not yet supported', (string) $stderr);
    }

    private function runJitCompileProbe(string $code): string
    {
        $repo = dirname(__DIR__, 2);
        $sourcePath = tempnam(sys_get_temp_dir(), 'phpc_var_fn_jit_');
        $this->assertNotFalse($sourcePath);
        $phpPath = $sourcePath.'.php';
        rename($sourcePath, $phpPath);
        file_put_contents($phpPath, $code);

        $probePath = tempnam(sys_get_temp_dir(), 'phpc_var_fn_probe_');
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
        LlvmToolchain::applyProcessEnv($env, $repo);
        $argv = array_merge(
            LlvmToolchain::envPrefix($repo),
            [PHP_BINARY, $probePhp, $phpPath]
        );
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($proc);
        @unlink($phpPath);
        @unlink($probePhp);

        return (string) $stderr;
    }

    private function runBin(string $bin): string
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_var_fn_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, "<?php\n".self::CODE);
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value)) {
                $env[$key] = $value;
            }
        }
        LlvmToolchain::applyProcessEnv($env, $repo);
        $argv = array_merge(
            LlvmToolchain::envPrefix($repo),
            ['php', $repo.'/'.$bin, $tmp]
        );
        $proc = proc_open(
            $argv,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $code, trim($stderr));

        return $stdout;
    }
}
