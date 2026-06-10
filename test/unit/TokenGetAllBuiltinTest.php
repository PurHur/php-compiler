<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** VM + AOT lint for token_get_all() (#3171, #4561). */
final class TokenGetAllBuiltinTest extends TestCase
{
    public function testTokenGetAllVmEchoProbe(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$t = token_get_all('<?php echo 1;');
echo ($t[1][0] === T_ECHO ? "ok\n" : "fail\n");
echo token_name(T_ECHO), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'token_get_all_vm.php'));
        $this->assertSame("ok\nT_ECHO\n", ob_get_clean());
    }

    public function testTokenGetAllAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/token_get_all_native.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for token_get_all probe (#3171)'
        );
    }
}
