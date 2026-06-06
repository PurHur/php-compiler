<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT lint verify for session_id() enum-case TypeError guards (#6581).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(session_id)
 */
final class SessionIdEnumCaseJitCompileTest extends TestCase
{
    public function testSessionIdEnumCaseTypeErrorAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/session_id_enum_typeerror.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for session_id enum TypeError probe (#6581)'
        );
    }
}
