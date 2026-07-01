<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #6633 #11473 #14334 */
final class NeverParamTypeTest extends TestCase
{
    public function testStandaloneNeverParamRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function acceptsNever(never $value): void {}
echo "ok\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never cannot be used as a parameter type');
        $runtime->parseAndCompile($code, 'never_param.php');
    }

    public function testNeverParamOnAbstractMethodRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class C {
    abstract public function f(never $x): void;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never cannot be used as a parameter type');
        $runtime->parseAndCompile($code, 'never_param_abstract.php');
    }

    public function testNeverParamOnInterfaceMethodRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(never $x): void;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never cannot be used as a parameter type');
        $runtime->parseAndCompile($code, 'never_param_interface.php');
    }

    public function testNeverInUnionParamRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(int|never $x): int {
    return $x;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'never_union_param.php');
    }

    public function testNeverIntersectionUnionParamRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(never&string $x): void {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'never_intersection_param.php');
    }

}
