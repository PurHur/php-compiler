<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7266 */
final class CurlHandleEnumTest extends TestCase
{
    public function testCurlHandleBuiltinClassesExist(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('CurlHandle', false));
echo "\n";
var_export(class_exists('CurlMultiHandle', false));
echo "\n";
var_export(class_exists('CurlShareHandle', false));
echo "\n";
var_export(enum_exists('CurlHandle', false));
echo "\n";
var_export(enum_exists('CurlMultiHandle', false));
echo "\n";
var_export(enum_exists('CurlShareHandle', false));
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'curl_handle_classes.php'));
        $this->assertSame(
            "false\nfalse\nfalse\nfalse\nfalse\nfalse",
            ob_get_clean(),
            'php-src ext/curl/curl.stub.php registers handle classes only when ext/curl is loaded (#12117)'
        );
    }

    public function testCurlHandleClassesAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/curl_handle_classes.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for CurlHandle class probe (#7266)'
        );
    }
}
