<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * openlog(null) under strict_types — TypeError like Zend (#30372).
 */
final class OpenlogNullStrict30372Test extends TestCase
{
    public function testVmTypeErrorUnderStrictTypes(): void
    {
        $out = $this->runPhpScript(realpath(dirname(__DIR__, 2).'/bin/vm.php'));
        $this->assertSame(
            "TypeError:openlog(): Argument #1 (\$prefix) must be of type string, null given\n",
            $out
        );
    }

    public function testJitTypeErrorUnderStrictTypes(): void
    {
        $out = $this->runPhpScript(realpath(dirname(__DIR__, 2).'/bin/jit.php'));
        $this->assertStringContainsString(
            'openlog(): Argument #1 ($prefix) must be of type string, null given',
            $out
        );
    }

    public function testSoftNullStillDeprecatesAndReturnsTrue(): void
    {
        $root = dirname(__DIR__, 2);
        $script = <<<'PHP'
<?php
error_reporting(E_ALL);
$deprecations = [];
set_error_handler(static function (int $n, string $m) use (&$deprecations): bool {
    if (E_DEPRECATED === $n) {
        $deprecations[] = $m;
    }
    return true;
});
$r = openlog(null, LOG_PID, LOG_USER);
echo var_export($r, true), "\n";
echo isset($deprecations[0]) ? $deprecations[0] : "no-dep", "\n";
PHP;
        $tmp = tempnam(sys_get_temp_dir(), 'openlog_soft_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $script);
        try {
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
            $this->assertSame(0, $exit, (string) $stdout.(string) $stderr);
            $this->assertStringStartsWith("true\n", (string) $stdout);
            $this->assertStringContainsString(
                'Passing null to parameter #1 ($prefix) of type string is deprecated',
                (string) $stdout
            );
        } finally {
            @unlink($tmp);
        }
    }

    private function runPhpScript(string $bin): string
    {
        $root = dirname(__DIR__, 2);
        $repro = realpath($root.'/test/repro/issue_30372_openlog_null_strict.php');
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
