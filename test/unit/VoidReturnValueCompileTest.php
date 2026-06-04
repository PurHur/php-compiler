<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4215 */
final class VoidReturnValueCompileTest extends TestCase
{
    public function testVoidRejectsValueReturnAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): void {
    return 1;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('A void function must not return a value');
        $runtime->parseAndCompile($code, 'void_value_return.php');
    }

    public function testVoidRejectsNullReturnAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): void {
    return null;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'A void function must not return a value (did you mean "return;" instead of "return null;"?)'
        );
        $runtime->parseAndCompile($code, 'void_null_return.php');
    }

    public function testVoidAllowsBareReturn(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): void {
    return;
}
f();
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'void_bare_return.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }
}
