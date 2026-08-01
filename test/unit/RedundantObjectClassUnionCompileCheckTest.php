<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26563 */
final class RedundantObjectClassUnionCompileCheckTest extends TestCase
{
    public function testObjectClassUnionParamIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {}
function f(object|A $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type A|object contains both object and a class type, which is redundant'
        );
        $runtime->parseAndCompile($code, 'object_class_union_param.php');
    }

    public function testClassObjectUnionOrderNormalized(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {}
function f(A|object $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type A|object contains both object and a class type, which is redundant'
        );
        $runtime->parseAndCompile($code, 'class_object_union_param.php');
    }

    public function testObjectInterfaceUnionIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {}
function f(object|I $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type I|object contains both object and a class type, which is redundant'
        );
        $runtime->parseAndCompile($code, 'object_iface_union.php');
    }

    public function testObjectTraversableExplicitIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(object|Traversable $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type Traversable|object contains both object and a class type, which is redundant'
        );
        $runtime->parseAndCompile($code, 'object_traversable_union.php');
    }

    public function testObjectIterableRemainsValid(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(object|iterable $x) { return $x; }
PHP;
        $runtime->parseAndCompile($code, 'object_iterable_ok.php');
        $this->assertTrue(true);
    }

    public function testObjectNullRemainsValid(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(object|null $x) { return $x; }
PHP;
        $runtime->parseAndCompile($code, 'object_null_ok.php');
        $this->assertTrue(true);
    }

    public function testObjectDnfIntersectionIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {}
interface B {}
function f(object|(A&B) $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type (A&B)|object contains both object and a class type, which is redundant'
        );
        $runtime->parseAndCompile($code, 'object_dnf_union.php');
    }

    public function testObjectClassUnionReturnIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {}
function f($x): object|A { return $x; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type A|object contains both object and a class type, which is redundant'
        );
        $runtime->parseAndCompile($code, 'object_class_union_return.php');
    }

    public function testObjectClassUnionPropertyIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {}
class C { public object|A $p; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type A|object contains both object and a class type, which is redundant'
        );
        $runtime->parseAndCompile($code, 'object_class_union_prop.php');
    }

    public function testNamespacedClassInMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
namespace N;
class A {}
function f(object|A $x) {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type N\\A|object contains both object and a class type, which is redundant'
        );
        $runtime->parseAndCompile($code, 'object_class_union_ns.php');
    }

    public function testObjectStaticReturnIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Test {
    public function foo(): static|object {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Type static|object contains both object and a class type, which is redundant'
        );
        $runtime->parseAndCompile($code, 'object_static_union.php');
    }
}
