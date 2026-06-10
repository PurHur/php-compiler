<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** VM + AOT lint for token_name() (#3171, #7254). */
final class TokenNameBuiltinTest extends TestCase
{
    public function testTokenNameVmFallbackMap(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo token_name(T_FUNCTION), "\n";
echo token_name(T_ECHO), "\n";
echo token_name(T_VARIABLE), "\n";
echo token_name(T_OPEN_TAG_WITH_ECHO), "\n";
echo token_name(T_PAAMAYIM_NEKUDOTAYIM), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'token_name_vm.php'));
        $this->assertSame(
            "T_FUNCTION\nT_ECHO\nT_VARIABLE\nT_OPEN_TAG_WITH_ECHO\nT_DOUBLE_COLON\n",
            ob_get_clean()
        );
    }

    public function testTokenNameEnumComponentAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/token_name_constants.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for token_name probe (#3171)'
        );
    }
}
