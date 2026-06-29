<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\BuiltinStubEnumTestSkip;
use PHPUnit\Framework\TestCase;

/** @covers issue #7235 */
final class SocketTypeEnumTest extends TestCase
{
    use BuiltinStubEnumTestSkip;

    public function testSocketTypeBuiltinEnumExists(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('SocketType', false));
echo "\n";
var_export(enum_exists('SocketType', false));
echo "\n";
var_export(SocketType::Stream->name);
echo "\n";
var_export(SocketType::Stream->value);
echo "\n";
var_export(SocketType::Datagram->value);
echo "\n";
$case = SocketType::Stream;
var_export($case instanceof SocketType);
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'socket_type_enum.php'));
        $this->assertSame("true\ntrue\n'Stream'\n1\n2\ntrue\n", ob_get_clean());
    }

    public function testSocketTypeEnumAotLint(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/socket_type_enum.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for SocketType enum probe (#7235)'
        );
    }
}
