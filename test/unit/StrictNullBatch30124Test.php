<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * strict_types null → TypeError batch (#30124; ext/standard basic_functions.c / assert.stub.php).
 *
 * php-src: debug_backtrace, assert_options, ini_restore, time_sleep_until Z_PARAM_* + zend_verify_arg_type.
 */
final class StrictNullBatch30124Test extends TestCase
{
    public function testVmTypeErrorsUnderStrictTypes(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/vm.php');
        $repro = realpath($root.'/test/repro/issue_30124_strict_null_batch.php');
        $this->assertNotFalse($bin);
        $this->assertNotFalse($repro);

        $out = $this->runPhpScript($bin, $repro);
        $this->assertStringContainsString(
            'debug_backtrace(): Argument #1 ($options) must be of type int, null given',
            $out
        );
        $this->assertStringContainsString(
            'assert_options(): Argument #1 ($option) must be of type int, null given',
            $out
        );
        $this->assertStringContainsString(
            'ini_restore(): Argument #1 ($option) must be of type string, null given',
            $out
        );
        $this->assertStringContainsString(
            'time_sleep_until(): Argument #1 ($timestamp) must be of type float, null given',
            $out
        );
        $this->assertStringNotContainsString('array (', $out);
        $this->assertStringNotContainsString('false', $out);
    }

    public function testJitTypeErrorsUnderStrictTypes(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/jit.php');
        $repro = realpath($root.'/test/repro/issue_30124_strict_null_batch.php');
        $this->assertNotFalse($bin);
        $this->assertNotFalse($repro);

        $out = $this->runPhpScript($bin, $repro);
        $this->assertStringContainsString(
            'debug_backtrace(): Argument #1 ($options) must be of type int, null given',
            $out
        );
        $this->assertStringContainsString(
            'assert_options(): Argument #1 ($option) must be of type int, null given',
            $out
        );
        $this->assertStringContainsString(
            'ini_restore(): Argument #1 ($option) must be of type string, null given',
            $out
        );
        $this->assertStringContainsString(
            'time_sleep_until(): Argument #1 ($timestamp) must be of type float, null given',
            $out
        );
    }

    public function testAssertOptionsStillDeprecatesBeforeTypeError(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/vm.php');
        $this->assertNotFalse($bin);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_assert_opt_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, <<<'PHP'
<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";
    return true;
});
try {
    assert_options(null);
} catch (Throwable $e) {
    echo get_class($e).": ".$e->getMessage()."\n";
}
PHP);
        try {
            $out = $this->runPhpScript($bin, $tmp);
            $this->assertStringContainsString(
                'ERR[8192]: Function assert_options() is deprecated since 8.3',
                $out
            );
            $this->assertStringContainsString(
                'TypeError: assert_options(): Argument #1 ($option) must be of type int, null given',
                $out
            );
        } finally {
            @unlink($tmp);
        }
    }

    public function testNonStrictStillDeprecateAndCoerce(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/vm.php');
        $this->assertNotFalse($bin);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_strict_ns_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, <<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";
    return true;
});
$r = debug_backtrace(null);
echo 'dbg:', is_array($r) ? 'array' : gettype($r), "\n";
PHP);
        try {
            $out = $this->runPhpScript($bin, $tmp);
            $this->assertStringContainsString(
                'ERR[8192]: debug_backtrace(): Passing null to parameter #1 ($options) of type int is deprecated',
                $out
            );
            $this->assertStringContainsString('dbg:array', $out);
        } finally {
            @unlink($tmp);
        }
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
