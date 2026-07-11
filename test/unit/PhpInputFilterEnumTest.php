<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\BuiltinStubEnumTestSkip;
use PHPUnit\Framework\TestCase;

/** @covers issue #7284 */
final class PhpInputFilterEnumTest extends TestCase
{
    use BuiltinStubEnumTestSkip;

    public function testPhpInputFilterBuiltinEnumAndFilterInput(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('PhpInputFilter', false));
echo "\n";
var_export(PhpInputFilter::Get->value === INPUT_GET);
echo "\n";
var_export(filter_input(PhpInputFilter::Get, 'missing', FILTER_VALIDATE_INT) === null);
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'phpinputfilter_enum.php'));
        $this->assertSame("true\ntrue\ntrue\n", ob_get_clean());
    }

    public function testPhpInputFilterEnumAotLint(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/phpinputfilter_enum.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for PhpInputFilter enum probe (#7284)'
        );
    }
}
