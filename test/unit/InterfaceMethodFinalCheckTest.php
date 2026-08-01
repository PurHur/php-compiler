<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26514 */
final class InterfaceMethodFinalCheckTest extends TestCase
{
    public function testFinalInterfaceMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    final public function f(): void;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Interface method I::f() must not be final');
        $runtime->parseAndCompile($code, 'interface_final.php');
    }

    public function testNonFinalInterfaceMethodCompilesAndIsImplementable(): void
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
        $block = $runtime->parseAndCompile($code, 'interface_non_final.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
