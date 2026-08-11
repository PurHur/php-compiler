<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * memory_get_usage/peak_usage(null) under strict_types — TypeError like Zend (#30346).
 */
final class MemoryGetUsageNullStrict30346Test extends TestCase
{
    public function testVmTypeErrorUnderStrictTypes(): void
    {
        $out = $this->runPhpScript(realpath(dirname(__DIR__, 2).'/bin/vm.php'));
        $this->assertSame(
            "memory_get_usage(): Argument #1 (\$real_usage) must be of type bool, null given\n"
            ."memory_get_peak_usage(): Argument #1 (\$real_usage) must be of type bool, null given\n",
            $out
        );
    }

    public function testJitTypeErrorUnderStrictTypes(): void
    {
        $out = $this->runPhpScript(realpath(dirname(__DIR__, 2).'/bin/jit.php'));
        $this->assertStringContainsString(
            'memory_get_usage(): Argument #1 ($real_usage) must be of type bool, null given',
            $out
        );
        $this->assertStringContainsString(
            'memory_get_peak_usage(): Argument #1 ($real_usage) must be of type bool, null given',
            $out
        );
    }

    private function runPhpScript(string $bin): string
    {
        $root = dirname(__DIR__, 2);
        $repro = realpath($root.'/test/repro/issue_30346_memory_get_usage_null_strict.php');
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
