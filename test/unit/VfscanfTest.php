<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26758 (retired public vfscanf from #6174) */
final class VfscanfTest extends TestCase
{
    public function testVfscanfNotRegisteredOnVm(): void
    {
        $this->assertFalse(CompilerVersion::supportsVfscanf());
        $runtime = new Runtime();
        $this->assertFalse(isset($runtime->vmContext->functions['vfscanf']));
        $this->assertTrue(isset($runtime->vmContext->functions['fscanf']));
        $this->assertTrue(isset($runtime->vmContext->functions['sscanf']));

        $code = <<<'PHP'
<?php
var_export(function_exists('vfscanf'));
echo "\n";
var_export(function_exists('fscanf'));
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'vfscanf_phantom_vm.php'));
        $this->assertSame("false\ntrue\n", ob_get_clean());
    }

    public function testVfscanfAbsentAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/vfscanf_exists.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for vfscanf phantom probe (#26758)'
        );
    }
}
