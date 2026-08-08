<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * http_response_code() after body output — Warning + false; status unchanged (#28929).
 *
 * php-src: ext/standard/head.c — PHP_FUNCTION(http_response_code) / SG(headers_sent)
 */
final class HttpResponseCodeHeadersSentTest extends TestCase
{
    public function testVmRefusesSetAfterOutput(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/vm.php');
        $repro = realpath($root.'/test/repro/http_response_code_headers_sent.php');
        $this->assertNotFalse($bin);
        $this->assertNotFalse($repro);

        $cmd = [PHP_BINARY, $bin, $repro];
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
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, (string) $stdout);
        $this->assertStringContainsString("body\n", (string) $stdout);
        $this->assertStringContainsString('got=false', (string) $stdout);
        $this->assertStringContainsString('now=false', (string) $stdout);
        $this->assertStringContainsString('warnings=1', (string) $stdout);
        $this->assertStringContainsString('Cannot set response code - headers already sent', (string) $stdout);
    }

    public function testAotLintHeadersSentRepro(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/repro/http_response_code_headers_sent_aot.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for http_response_code headers-sent AOT probe (#28929)'
        );
    }
}
