<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * fnmatch(null) under strict_types — TypeError like Zend (#30123; re-#20139).
 *
 * php-src: ext/standard/fnmatch.c PHP_FUNCTION(fnmatch); basic_functions.stub.php
 * Non-strict DEP+coerce remains covered by GlobFnmatchNullPatternDepArgIndexTest (#29660).
 */
final class FnmatchNullStrict30123Test extends TestCase
{
    public function testVmTypeErrorUnderStrictTypes(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/vm.php');
        $repro = realpath($root.'/test/repro/fnmatch_null_strict_30123.php');
        $this->assertNotFalse($bin);
        $this->assertNotFalse($repro);

        $out = $this->runPhpScript($bin, $repro);
        $this->assertSame(
            "ok:pattern:fnmatch(): Argument #1 (\$pattern) must be of type string, null given\n"
            ."ok:filename:fnmatch(): Argument #2 (\$filename) must be of type string, null given\n",
            $out
        );
    }

    public function testJitTypeErrorUnderStrictTypes(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/jit.php');
        $repro = realpath($root.'/test/repro/fnmatch_null_strict_30123.php');
        $this->assertNotFalse($bin);
        $this->assertNotFalse($repro);

        $out = $this->runPhpScript($bin, $repro);
        $this->assertStringContainsString(
            'ok:pattern:fnmatch(): Argument #1 ($pattern) must be of type string, null given',
            $out
        );
        $this->assertStringContainsString(
            'ok:filename:fnmatch(): Argument #2 ($filename) must be of type string, null given',
            $out
        );
    }

    public function testNonStrictStillDeprecateAndCoerce(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/vm.php');
        $this->assertNotFalse($bin);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_fnmatch_ns_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, <<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";

    return true;
});
$p = fnmatch(null, 'a');
echo 'pattern:', var_export($p, true), "\n";
$f = fnmatch('a', null);
echo 'filename:', var_export($f, true), "\n";
PHP);
        try {
            $out = $this->runPhpScript($bin, $tmp);
            $this->assertSame(
                "ERR[8192]: fnmatch(): Passing null to parameter #1 (\$pattern) of type string is deprecated\n"
                ."pattern:false\n"
                ."ERR[8192]: fnmatch(): Passing null to parameter #2 (\$filename) of type string is deprecated\n"
                ."filename:false\n",
                $out
            );
        } finally {
            @unlink($tmp);
        }
    }

    public function testAotLintStrictNullRepro(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/repro/fnmatch_null_strict_30123.php';
        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for fnmatch null-strict AOT probe (#30123)'
        );
    }

    private function runPhpScript(string $bin, string $repro): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = [PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=1', $bin, $repro];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $env[(string) $key] = (string) $value;
            }
        }
        $env['PHP_COMPILER_PROFILE'] = '8.4';
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
