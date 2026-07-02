<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #14890 */
final class InterfaceMethodBodyCheckTest extends TestCase
{
    public function testInterfaceMethodWithBodyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(): void {
        echo "x\n";
    }
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Interface function I::f() cannot contain body');
        $runtime->parseAndCompile($code, 'interface_body.php');
    }

    public function testInterfaceMethodWithEmptyBodyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(): void {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Interface function I::f() cannot contain body');
        $runtime->parseAndCompile($code, 'interface_empty_body.php');
    }

    public function testInterfaceMethodWithoutBodyCompiles(): void
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
        $block = $runtime->parseAndCompile($code, 'interface_no_body.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
