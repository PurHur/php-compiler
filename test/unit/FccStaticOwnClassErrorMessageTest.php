<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Issue #14935 — static FCC (C::m)(...) uses Zend "Undefined constant" wording. */
final class FccStaticOwnClassErrorMessageTest extends TestCase
{
    public function testStaticInstanceMethodFccErrorMessageMatchesZend(): void
    {
        $repro = <<<'PHP'
<?php
class C { public function m() {} }
try {
    (C::m)(new C());
} catch (Throwable $e) {
    echo $e->getMessage();
}
PHP;
        $path = sys_get_temp_dir().'/phpc_fcc_static_own_class_'.getmypid().'.php';
        file_put_contents($path, $repro);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__, 2).'/bin/vm.php').' '
                .escapeshellarg($path).' 2>&1';
            $output = shell_exec($cmd);
            $this->assertIsString($output);
            $this->assertStringContainsString('Undefined constant C::m', $output);
            $this->assertStringNotContainsString('Undefined class constant', $output);
        } finally {
            @unlink($path);
        }
    }
}
