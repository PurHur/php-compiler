<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4970 #6967 #7414 */
final class NeverUnionTypeTest extends TestCase
{
    public function testNeverInUnionPropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public int|never $x;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'never_union_property.php');
    }

    public function testNeverInUnionReturnTypeCompilesAndThrows(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): string|never {
    throw new Exception('x');
}
try {
    f();
} catch (Exception $e) {
    echo $e->getMessage();
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'never_union.php'));
        $this->assertSame('x', ob_get_clean());
    }

    public function testNeverInIntersectionReturnTypeRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function g(): int&never {
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'never_intersection.php');
    }

    public function testNullableNeverParamRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(?never $x = null): void {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'never_nullable_param.php');
    }

    public function testNullableNeverReturnRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): ?never {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'never_nullable_return.php');
    }

    public function testNeverNullUnionParamRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(never|null $x): void {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('never can only be used as a standalone type');
        $runtime->parseAndCompile($code, 'never_null_union_param.php');
    }

    public function testStandaloneNeverReturnTypeStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): never {
    throw new Exception('x');
}
f();
PHP;
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile($code, 'never_standalone.php'));
            $this->fail('Expected uncaught exception');
        } catch (\Throwable $e) {
            $this->assertSame('x', $e->getMessage());
        }
        ob_end_clean();
    }
}
