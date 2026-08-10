<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * http_response_code(null) under strict_types — TypeError like Zend (#30019).
 *
 * php-src: ext/standard/head.c — Z_PARAM_LONG + zend_verify_arg_type
 */
final class HttpResponseCodeNullStrictTest extends TestCase
{
    public function testVmTypeErrorUnderStrictTypes(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/vm.php');
        $repro = realpath($root.'/test/repro/http_response_code_null_strict.php');
        $this->assertNotFalse($bin);
        $this->assertNotFalse($repro);

        $out = $this->runPhpScript($bin, $repro);
        $this->assertSame(
            'TypeError:http_response_code(): Argument #1 ($response_code) must be of type int, null given'."\n",
            $out
        );
    }

    public function testJitTypeErrorUnderStrictTypes(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/jit.php');
        $repro = realpath($root.'/test/repro/http_response_code_null_strict.php');
        $this->assertNotFalse($bin);
        $this->assertNotFalse($repro);

        $out = $this->runPhpScript($bin, $repro);
        $this->assertStringContainsString(
            'TypeError:http_response_code(): Argument #1 ($response_code) must be of type int, null given',
            $out
        );
    }

    public function testAotLintStrictNullRepro(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/repro/http_response_code_null_strict.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for http_response_code null-strict AOT probe (#30019)'
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
