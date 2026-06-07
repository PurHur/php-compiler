<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6677 */
final class InterfaceMethodVisibilityCheckTest extends TestCase
{
    public function testProtectedInterfaceMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    protected function f(): void;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access type for interface method I::f() must be public');
        $runtime->parseAndCompile($code, 'interface_protected.php');
    }

    public function testPrivateInterfaceMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    private function f(): void;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access type for interface method I::f() must be public');
        $runtime->parseAndCompile($code, 'interface_private.php');
    }

    public function testPublicInterfaceMethodCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(): void;
}
class C implements I {
    public function f(): void {
        echo "ok\n";
    }
}
(new C())->f();
PHP;
        $block = $runtime->parseAndCompile($code, 'interface_public.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
