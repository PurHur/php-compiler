<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3301 @covers issue #3284 @covers issue #27677 */
final class FprintfFscanfBuiltinTest extends TestCase
{
    public function testJitFwriteEnsuresStreamIoLinkedForUserScriptAot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitFwrite.php');
        $this->assertStringContainsString(
            'StreamIoRuntime::ensureLinkedForUserScriptLowering',
            $source,
            'fprintf/vfprintf AOT without fopen must pull __compiler_fwrite (#27677)'
        );
    }

    public function testFprintfBuiltinRegisteredOnVm(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(function_exists('fprintf'));
echo "\n";
$path = sys_get_temp_dir() . '/phpc_fprintf_ut_' . getmypid() . '.txt';
$fp = fopen($path, 'w+');
$n = fprintf($fp, '%s', 'ok');
fclose($fp);
echo file_get_contents($path), ' ', $n, "\n";
@unlink($path);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'fprintf_vm.php'));
        $this->assertSame("true\nok 2\n", ob_get_clean());
    }

    public function testFscanfBuiltinRegisteredOnVm(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(function_exists('fscanf'));
echo "\n";
$fp = fopen('php://memory', 'r+');
fwrite($fp, '7');
rewind($fp);
$n = 0;
var_export(fscanf($fp, '%d', $n));
echo "\n";
var_export($n);
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'fscanf_vm.php'));
        $this->assertSame("true\n1\n7\n", ob_get_clean());
    }

    public function testFscanfExistsAotLint(): void
    {
        $root = \dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/fscanf_exists.php';
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
            \trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for fscanf exists probe (#3284)'
        );
    }
}
