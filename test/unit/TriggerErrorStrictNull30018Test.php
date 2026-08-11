<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * trigger_error/user_error(null) under strict_types — TypeError like Zend (#30018; re-#21035).
 *
 * php-src: Zend/zend_builtin_functions.stub.php — trigger_error(string $message, …)
 */
final class TriggerErrorStrictNull30018Test extends TestCase
{
    public function testVmTypeErrorsUnderStrictTypes(): void
    {
        $out = $this->runPhpScript(realpath(dirname(__DIR__, 2).'/bin/vm.php'));
        $this->assertStringContainsString(
            'trigger_error(): Argument #1 ($message) must be of type string, null given',
            $out
        );
        $this->assertStringContainsString(
            'user_error(): Argument #1 ($message) must be of type string, null given',
            $out
        );
        $this->assertStringNotContainsString('Notice:', $out);
    }

    public function testJitTypeErrorsUnderStrictTypes(): void
    {
        $out = $this->runPhpScript(realpath(dirname(__DIR__, 2).'/bin/jit.php'));
        $this->assertStringContainsString(
            'trigger_error(): Argument #1 ($message) must be of type string, null given',
            $out
        );
        $this->assertStringContainsString(
            'user_error(): Argument #1 ($message) must be of type string, null given',
            $out
        );
    }

    public function testNonStrictStillDeprecateAndCoerce(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/vm.php');
        $this->assertNotFalse($bin);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_te_ns_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, <<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";
    return true;
});
$r = trigger_error(null);
echo 'ret:', var_export($r, true), "\n";
PHP);
        try {
            $out = $this->runPhpScript($bin, $tmp);
            $this->assertStringContainsString(
                'ERR[8192]: trigger_error(): Passing null to parameter #1 ($message) of type string is deprecated',
                $out
            );
            $this->assertStringContainsString('ret:true', $out);
        } finally {
            @unlink($tmp);
        }
    }

    private function runPhpScript(string $bin, ?string $repro = null): string
    {
        $root = dirname(__DIR__, 2);
        $repro ??= realpath($root.'/test/repro/issue_30018_trigger_error_strict_null.php');
        $this->assertNotFalse($repro);
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
