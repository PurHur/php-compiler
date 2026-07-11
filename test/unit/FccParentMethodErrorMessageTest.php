<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #14928 — parent FCC (C::m)(...) uses Zend "Undefined constant" wording. */
final class FccParentMethodErrorMessageTest extends TestCase
{
    public function testParentInstanceMethodFccErrorMessageMatchesZend(): void
    {
        $repro = <<<'PHP'
<?php
class P { public function m() {} }
class C extends P {}
try {
    (C::m)(new C());
} catch (Throwable $e) {
    echo $e->getMessage();
}
PHP;
        $path = sys_get_temp_dir().'/phpc_fcc_parent_method_'.getmypid().'.php';
        file_put_contents($path, $repro);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php').' '
                .escapeshellarg($path).' 2>&1';
            $output = shell_exec($cmd);
            $this->assertIsString($output);
            $this->assertSame('Undefined constant C::m', trim($output));
        } finally {
            @unlink($path);
        }
    }
}
