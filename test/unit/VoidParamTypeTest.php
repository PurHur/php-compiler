<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26517 */
final class VoidParamTypeTest extends TestCase
{
    public function testStandaloneVoidParamRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function acceptsVoid(void $value): void {}
echo "ok\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('void cannot be used as a parameter type');
        $runtime->parseAndCompile($code, 'void_param.php');
    }

    public function testVoidParamOnAbstractMethodRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class C {
    abstract public function f(void $x): void;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('void cannot be used as a parameter type');
        $runtime->parseAndCompile($code, 'void_param_abstract.php');
    }

    public function testVoidParamOnInterfaceMethodRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(void $x): void;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('void cannot be used as a parameter type');
        $runtime->parseAndCompile($code, 'void_param_interface.php');
    }

    public function testVoidInUnionParamRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(int|void $x): int {
    return $x;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Void can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'void_union_param.php');
    }

    public function testNullableVoidParamRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(?void $x): void {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Void can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'nullable_void_param.php');
    }

    public function testVoidUnionReturnRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): int|void {}
echo "ok\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Void can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'void_union_return.php');
    }

    public function testNullableVoidReturnRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): ?void {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Void can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'nullable_void_return.php');
    }

    public function testVoidNeverUnionReturnPrefersVoidMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): void|never {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Void can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'void_never_union_return.php');
    }

    public function testStandaloneVoidReturnStillAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): void {}
class C {
    public function m(): void {}
}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'void_return_ok.php');
        $this->assertNotNull($block);
    }
}
