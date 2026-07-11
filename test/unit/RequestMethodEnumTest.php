<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\BuiltinStubEnumTestSkip;
use PHPUnit\Framework\TestCase;

/** @covers issue #7230 */
final class RequestMethodEnumTest extends TestCase
{
    use BuiltinStubEnumTestSkip;

    public function testRequestMethodBuiltinEnumExists(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('RequestMethod', false));
echo "\n";
var_export(unitenum_exists('RequestMethod'));
echo "\n";
var_export(RequestMethod::Post->name);
echo "\n";
var_export(RequestMethod::Post->value);
echo "\n";
var_export(RequestMethod::Get->value);
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'requestmethod_enum.php'));
        $this->assertSame("true\nfalse\n'Post'\n'POST'\n'GET'\n", ob_get_clean());
    }

    public function testRequestMethodEnumAotLint(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/requestmethod_enum.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for RequestMethod enum probe (#7230)'
        );
    }
}
