<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\InheritanceVariance;
use PHPCompiler\Compiler\MethodSig;
use PHPCompiler\Compiler\TypeSig;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3323, #23504 */
final class InheritanceVarianceTest extends TestCase
{
    public function testInterfaceExtendsIncompatibleStaticReturnFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {
    abstract public static function f(): void;
}
interface B extends A {
    abstract public static function f(): int;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of B::f(): int must be compatible with A::f(): void');
        $runtime->parseAndCompile($code, 'iface_static_variance.php');
    }

    public function testNarrowParameterTypeFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I { public function f(A $x): void; }
class A {}
class B extends A {}
class C implements I { public function f(B $x): void {} }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of C::f(B $x): void must be compatible with I::f(A $x): void');
        $runtime->parseAndCompile($code, 'narrow_param.php');
    }

    public function testWideParameterTypeAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I { public function f(B $x): void; }
class A {}
class B extends A {}
class C implements I { public function f(A $x): void {} }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'wide_param.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testCovariantSelfReturnAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { public function create(): self { return $this; } }
class Child extends Base { public function create(): Child { return $this; } }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'covariant_return.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testWidenedReturnTypeFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Child {}
class Base { public function create(): Child { return new Child(); } }
class Sub extends Base { public function create(): Base { return new Child(); } }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of Sub::create(): Base must be compatible with Base::create(): Child');
        $runtime->parseAndCompile($code, 'wide_return.php');
    }

    /** Zend/php-src: concrete parent __construct signatures are not enforced on children (#1492 M5 vendor). */
    public function testConcreteConstructorSignatureMismatchAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Factory {}
class CoreLLVM {
    public function __construct(Factory $factory, ?string $path = null) {}
}
class LLVM4 extends CoreLLVM {
    public function __construct(?string $path = null) {
        parent::__construct(new Factory(), $path);
    }
}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'ctor_mismatch_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testInterfaceReturnCovariantImplementationAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface IModule {}
class CModule implements IModule {}
interface IBuffer { public function parse(): IModule; }
class CBuffer implements IBuffer {
    public function parse(): CModule { return new CModule(); }
}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'iface_return.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testNeverReturnCovariantWithVoidParentAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I { public function f(): void; }
class C implements I { public function f(): never { throw new Exception('x'); } }

abstract class A { abstract public function g(): void; }
class B extends A { public function g(): never { exit; } }

class R { public function __clone(): never { throw new Exception('no clone'); } }

echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'never_void_covariance.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testVoidReturnOverNeverParentFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A { abstract public function g(): never; }
class B extends A { public function g(): void {} }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of B::g(): void must be compatible with A::g(): never');
        $runtime->parseAndCompile($code, 'void_over_never.php');
    }

    public function testIntReturnOverVoidParentFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I { public function f(): void; }
class C implements I { public function f(): int { return 1; } }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of C::f(): int must be compatible with I::f(): void');
        $runtime->parseAndCompile($code, 'int_over_void.php');
    }

    public function testAbstractConstructorSignatureMismatchFails(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class Base { abstract public function __construct(); }
class Child extends Base { public function __construct($x) {} }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of Child::__construct($x) must be compatible with Base::__construct()');
        $runtime->parseAndCompile($code, 'abstract_ctor.php');
    }

    /** Concrete parent: child must not add required parameters (#6412). */
    public function testConcreteParentExtraRequiredParamFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class ParentClass { public function foo(): void {} }
class Child extends ParentClass { public function foo(int $x): void {} }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of Child::foo(int $x): void must be compatible with ParentClass::foo(): void');
        $runtime->parseAndCompile($code, 'child_extra_param.php');
    }

    public function testConcreteParentExtraOptionalParamAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class ParentClass { public function foo(): void {} }
class Child extends ParentClass { public function foo(int $x = 0): void {} }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'child_extra_optional_param.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testCovariantObjectReturnAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { public function foo(): object {} }
class Child extends Base { public function foo(): \stdClass { return new \stdClass(); } }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'covariant_object_return.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testCovariantIterableArrayReturnAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { public function foo(): iterable { return []; } }
class Child extends Base { public function foo(): array { return []; } }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'covariant_iterable_return.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Interface `: self` return — implementing class may use `: static` (zend_inheritance.c, #6734). */
    public function testInterfaceSelfReturnChildStaticAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function make(): self;
}
class C implements I {
    public function make(): static {
        return new static();
    }
}
echo (new C())->make()::class, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'iface_self_static.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("C\n", ob_get_clean());
    }

    /** Parent `: self` return — child may use `: static` (zend_inheritance.c, #6734). */
    public function testParentSelfReturnChildStaticAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    public function make(): self { return $this; }
}
class Child extends Base {
    public function make(): static { return new static(); }
}
echo (new Child())->make()::class, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'parent_self_child_static.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("Child\n", ob_get_clean());
    }

    /** Parent `: static` return — child `: self` must fail (zend_inheritance.c, #6734). */
    public function testParentStaticReturnChildSelfFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    public function make(): static { return new static(); }
}
class Child extends Base {
    public function make(): self { return $this; }
}
PHP;
        $this->expectException(\CompileError::class);
        $runtime->parseAndCompile($code, 'parent_static_child_self.php');
    }

    public function testReturnCompatibilityInterfaceSelfResolvedClassWithChildStatic(): void
    {
        class_exists(InheritanceVariance::class);

        $parentType = new TypeSig();
        $parentType->classLc = 'i';
        $parentType->classDisplay = 'I';

        $childType = new TypeSig();
        $childType->static = true;

        $parent = new MethodSig('i', [], [], [], $parentType);
        $child = new MethodSig('c', [], [], [], $childType);

        $this->assertNull(InheritanceVariance::methodCompatibilityError(
            'C',
            'make',
            $child,
            'I',
            $parent,
            static fn (string $sub, string $super): bool => false,
            static fn (string $class, string $iface): bool => 'c' === $class && 'i' === $iface
        ));
    }

    /** Zend: child may widen param nullability (string → ?string), #23504. */
    public function testNullableParamWidenAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(string $x): void {} }
class B extends A { public function f(?string $x): void {} }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'null_param_widen.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Zend: child may widen class param to object, #23504. */
    public function testObjectParamWidenAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(\stdClass $x): void {} }
class B extends A { public function f(object $x): void {} }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'object_param_widen.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Zend: child may narrow return nullability (?string → string), #23504. */
    public function testNullableReturnNarrowAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(): ?string { return null; } }
class B extends A { public function f(): string { return "x"; } }
echo (new B())->f(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'null_return_narrow.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("x\n", ob_get_clean());
    }

    /** Zend: child may narrow return to intersection subtype, #23504. */
    public function testIntersectionReturnNarrowAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {}
interface J {}
class A { public function f(): I { return new class implements I {}; } }
class B extends A { public function f(): I&J { return new class implements I, J {}; } }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'intersect_return.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Zend: child must not widen return nullability (string → ?string), #23504. */
    public function testNullableReturnWidenFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(): string { return "x"; } }
class B extends A { public function f(): ?string { return null; } }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of B::f(): ?string must be compatible with A::f(): string');
        $runtime->parseAndCompile($code, 'null_return_widen.php');
    }

    /** Zend: child must not narrow object param to class, #23504. */
    public function testObjectParamNarrowFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(object $x): void {} }
class B extends A { public function f(\stdClass $x): void {} }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of B::f(stdClass $x): void must be compatible with A::f(object $x): void');
        $runtime->parseAndCompile($code, 'object_param_narrow.php');
    }

    /** Zend: property types are invariant — stdClass → object fatal (#23505). */
    public function testPropertyTypeWidenFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public stdClass $x; }
class B extends A { public object $x; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type of B::$x must be stdClass (as in class A)');
        $runtime->parseAndCompile($code, 'prop_type_widen.php');
    }

    /** Zend: identical property types (including self/self) inherit cleanly (#23505). */
    public function testIdenticalPropertyTypesAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public stdClass $x; }
class B extends A { public stdClass $x; }
class C { public self $y; }
class D extends C { public self $y; }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'prop_type_identical.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Zend: private parent properties may change type (#23505). */
    public function testPrivatePropertyTypeChangeAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { private stdClass $x; }
class B extends A { public object $x; }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'prop_private_redeclare.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Zend: adding a type to an untyped parent property fatals (#23505). */
    public function testAddingPropertyTypeFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public $x; }
class B extends A { public object $x; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type of B::$x must not be defined (as in class A)');
        $runtime->parseAndCompile($code, 'prop_add_type.php');
    }
}
