<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #14938 — paren-invoke on instance property FCC emits Warning then callable Error. */
final class FccInstanceMethodErrorTest extends TestCase
{
    public function testUndefinedInstancePropertyParenInvokeMatchesZend(): void
    {
        $repro = <<<'PHP'
<?php
class C { public function m() {} }
$o = new C;
try {
    ($o->m)(new C());
} catch (Throwable $e) {
    echo $e->getMessage();
}
PHP;
        $path = sys_get_temp_dir().'/phpc_fcc_instance_method_'.getmypid().'.php';
        file_put_contents($path, $repro);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php').' '
                .escapeshellarg($path).' 2>&1';
            $output = shell_exec($cmd);
            $this->assertIsString($output);
            $this->assertStringContainsString('Undefined property: C::$m', $output);
            $this->assertStringContainsString('Value of type null is not callable', $output);
        } finally {
            @unlink($path);
        }
    }
}
