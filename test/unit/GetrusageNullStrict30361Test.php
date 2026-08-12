<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * getrusage(null) under strict_types — TypeError like Zend (#30361).
 */
final class GetrusageNullStrict30361Test extends TestCase
{
    public function testVmTypeErrorUnderStrictTypes(): void
    {
        $out = $this->runPhpScript(realpath(dirname(__DIR__, 2).'/bin/vm.php'));
        $this->assertSame(
            "TypeError:getrusage(): Argument #1 (\$mode) must be of type int, null given\n"
            ."TypeError:getrusage(): Argument #1 (\$mode) must be of type int, null given\n",
            $out
        );
    }

    public function testJitTypeErrorUnderStrictTypes(): void
    {
        $out = $this->runPhpScript(realpath(dirname(__DIR__, 2).'/bin/jit.php'));
        $this->assertStringContainsString(
            'getrusage(): Argument #1 ($mode) must be of type int, null given',
            $out
        );
    }

    public function testAotTypeErrorUnderStrictTypes(): void
    {
        $root = dirname(__DIR__, 2);
        if (!LlvmToolchain::hasLibrary($root)) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = sys_get_temp_dir().'/phpc_getrusage_30361_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
declare(strict_types=1);
try {
    getrusage(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP);
        $bin = sys_get_temp_dir().'/phpc_getrusage_30361_'.getmypid().'.bin';
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $compileOut, $compileRc);
        $this->assertSame(0, $compileRc, implode("\n", $compileOut));
        $this->assertFileExists($bin);
        try {
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(
                'getrusage(): Argument #1 ($mode) must be of type int, null given',
                implode("\n", $runOut)
            );
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    public function testSoftNullStillDeprecatesAndReturnsArray(): void
    {
        $root = dirname(__DIR__, 2);
        $code = <<<'PHP'
<?php
error_reporting(E_ALL);
$dep = false;
set_error_handler(static function (int $n, string $m) use (&$dep): bool {
    if (E_DEPRECATED === $n) {
        $dep = true;
    }
    return true;
});
$r = getrusage(null);
echo ($dep ? 'dep' : 'nodep'), ':', (is_array($r) ? 'array' : gettype($r)), "\n";
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'getrusage_soft_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $code);
        $cmd = [PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=1', realpath($root.'/bin/vm.php'), $tmp];
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $env[(string) $key] = (string) $value;
            }
        }
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);
        $this->assertSame(0, $exit, (string) $stdout.(string) $stderr);
        $this->assertSame("dep:array\n", $stdout);
    }

    private function runPhpScript(string $bin): string
    {
        $root = dirname(__DIR__, 2);
        $repro = realpath($root.'/test/repro/issue_30361_getrusage_null_strict.php');
        $this->assertNotFalse($repro);
        $cmd = [PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=1', $bin, $repro];
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $env[(string) $key] = (string) $value;
            }
        }
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, (string) $stdout.(string) $stderr);

        return (string) $stdout;
    }
}
