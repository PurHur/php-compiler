<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\InheritanceVariance;
use PHPCompiler\Compiler\MethodSig;
use PHPCompiler\Compiler\TypeSig;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3323, #23504, #25727, #26520 */
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

    /** Zend: by-ref ↔ by-value override must fatal (#25633, zend_inheritance.c). */
    public function testByRefParamDroppedFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(&$x) {} }
class B extends A { public function f($x) {} }
echo "accepted\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of B::f($x) must be compatible with A::f(&$x)');
        $runtime->parseAndCompile($code, 'byref_drop.php');
    }

    /** Zend: adding by-ref on override must fatal (#25633). */
    public function testByRefParamAddedFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f($x) {} }
class B extends A { public function f(&$x) {} }
echo "accepted\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of B::f(&$x) must be compatible with A::f($x)');
        $runtime->parseAndCompile($code, 'byref_add.php');
    }

    /** Matching by-ref on both sides is accepted (#25633). */
    public function testMatchingByRefOverrideAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(&$x) {} }
class B extends A { public function f(&$x) {} }
echo "accepted\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'byref_match.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("accepted\n", ob_get_clean());
    }

    /** Zend: dropping by-ref return on override must fatal (#26530, zend_inheritance.c). */
    public function testByRefReturnDroppedFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function &f(): int { $x = 1; return $x; } }
class B extends A { public function f(): int { return 1; } }
echo "accepted\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of B::f(): int must be compatible with & A::f(): int');
        $runtime->parseAndCompile($code, 'byref_ret_drop.php');
    }

    /** Matching by-ref returns on both sides is accepted (#26530). */
    public function testMatchingByRefReturnOverrideAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function &f(): int { $x = 1; return $x; } }
class B extends A { public function &f(): int { $x = 2; return $x; } }
echo "accepted\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'byref_ret_match.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("accepted\n", ob_get_clean());
    }

    /** Zend: child may add by-ref return when parent returns by-value (#26530). */
    public function testByRefReturnAddedOnOverrideAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(): int { return 1; } }
class B extends A { public function &f(): int { $x = 1; return $x; } }
echo "accepted\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'byref_ret_add.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("accepted\n", ob_get_clean());
    }

    /** Zend: child cannot narrow a union param (int|string → int) (#25632). */
    public function testUnionParamNarrowFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(int|string $x): void {} }
class B extends A { public function f(int $x): void {} }
echo "accepted\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessageMatches(
            '/Declaration of B::f\(int \$x\): void must be compatible with A::f\((int\|string|string\|int) \$x\): void/'
        );
        $runtime->parseAndCompile($code, 'union_param_narrow.php');
    }

    /** Zend: implements cannot narrow a union param (#25632). */
    public function testUnionParamNarrowOnImplementsFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I { public function f(int|string $x): void; }
class C implements I { public function f(int $x): void {} }
echo "ok\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessageMatches(
            '/Declaration of C::f\(int \$x\): void must be compatible with I::f\((int\|string|string\|int) \$x\): void/'
        );
        $runtime->parseAndCompile($code, 'union_param_implements.php');
    }

    /** Zend: abstract override cannot narrow a union param (#25632). */
    public function testUnionParamNarrowOnAbstractFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A { abstract public function f(int|string $x): void; }
class B extends A { public function f(int $x): void {} }
echo "ok\n";
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessageMatches(
            '/Declaration of B::f\(int \$x\): void must be compatible with A::f\((int\|string|string\|int) \$x\): void/'
        );
        $runtime->parseAndCompile($code, 'union_param_abstract.php');
    }

    /** Zend: widening union on override (int → int|string) is allowed (#25632). */
    public function testUnionParamWidenAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(int $x): void {} }
class B extends A { public function f(int|string $x): void {} }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'union_param_widen.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Zend: cannot redeclare a concrete parent method as abstract (#25660). */
    public function testAbstractFromConcreteFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(): void {} }
abstract class B extends A { abstract public function f(): void; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot make non abstract method A::f() abstract in class B');
        $runtime->parseAndCompile($code, 'abstract_from_concrete.php');
    }

    /** Zend: abstractizing a concrete __construct still fatals (#25660). */
    public function testAbstractFromConcreteConstructorFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function __construct() {} }
abstract class B extends A { abstract public function __construct(); }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot make non abstract method A::__construct() abstract in class B');
        $runtime->parseAndCompile($code, 'abstract_from_concrete_ctor.php');
    }

    /** Zend: abstract override of abstract parent remains OK (#25660). */
    public function testAbstractFromAbstractAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A { abstract public function f(): void; }
abstract class B extends A { abstract public function f(): void; }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'abstract_from_abstract.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Zend: eval inherit also rejects abstractizing a concrete parent (#25660). */
    public function testAbstractFromConcreteFailsViaEval(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
eval('class A { public function f(): void {} }');
eval('abstract class B extends A { abstract public function f(): void; }');
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'abstract_from_concrete_eval.php');
        $this->assertNotNull($block);
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot make non abstract method A::f() abstract in class B');
        ob_start();
        try {
            $runtime->run($block);
        } finally {
            ob_end_clean();
        }
    }

    /** Zend: cannot make static method non-static (#25634). */
    public function testStaticToInstanceFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A1 { public static function f() {} }
class B1 extends A1 { public function f() {} }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot make static method A1::f() non static in class B1');
        $runtime->parseAndCompile($code, 'static_to_inst.php');
    }

    /** Zend: cannot make non-static method static (#25634). */
    public function testInstanceToStaticFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A1 { public function f() {} }
class B1 extends A1 { public static function f() {} }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot make non static method A1::f() static in class B1');
        $runtime->parseAndCompile($code, 'inst_to_static.php');
    }

    /** Zend: cannot weaken public→protected (#25634). */
    public function testVisibilityWeakenPublicToProtectedFails(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A2 { public function f() {} }
class B2 extends A2 { protected function f() {} }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access level to B2::f() must be public (as in class A2)');
        $runtime->parseAndCompile($code, 'vis_weaken.php');
    }

    /** Zend: cannot weaken protected→private (#25634). */
    public function testVisibilityWeakenProtectedToPrivateFails(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { protected function f() {} }
class B extends A { private function f() {} }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access level to B::f() must be protected (as in class A) or weaker');
        $runtime->parseAndCompile($code, 'vis_prot_priv.php');
    }

    /** Zend: visibility strengthen protected→public is OK (#25634). */
    public function testVisibilityStrengthenAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A2 { protected function f() {} }
class B2 extends A2 { public function f() {} }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'vis_strengthen.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Private parent methods are not overridden — incompatible child is OK (#25634). */
    public function testPrivateParentNotOverridden(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { private static function f(): int { return 1; } }
class B extends A { public function f(): string { return "x"; } }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'priv_not_override.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Zend: private impl of protected abstract rejected (#25662). */
    public function testAbstractProtectedToPrivateFails(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A { abstract protected function f(): void; }
class B extends A { private function f(): void {} }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access level to B::f() must be protected (as in class A) or weaker');
        $runtime->parseAndCompile($code, 'abs_vis_prot_priv.php');
    }

    /** Zend: public impl of protected abstract is OK (#25662). */
    public function testAbstractProtectedToPublicAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A { abstract protected function f(): void; }
class B extends A { public function f(): void {} }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'abs_vis_prot_pub.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Zend: protected→private on abstract also fails across eval (#25662). */
    public function testAbstractProtectedToPrivateFailsAcrossEval(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
eval('abstract class A { abstract protected function f(): void; }');
eval('class B extends A { private function f(): void {} }');
echo "LOADED\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'abs_vis_eval.php');
        $this->assertNotNull($block);
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Access level to B::f() must be protected (as in class A) or weaker');
        $runtime->run($block);
    }

    /** Zend: untyped __toString is compatible with Stringable::__toString(): string (#25727). */
    public function testUntypedToStringImplementsStringable(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class S implements Stringable {
    public function __toString() {
        return 'hi';
    }
}
echo (string) (new S()), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'stringable_untyped_tostring.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("hi\n", ob_get_clean());
    }

    /** Declared non-string __toString still fatals (#25025 / #25727). */
    public function testToStringIntReturnStillRejected(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class S implements Stringable {
    public function __toString(): int {
        return 1;
    }
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('S::__toString(): Return type must be string when declared');
        $runtime->parseAndCompile($code, 'stringable_int_tostring.php');
    }

    /** Two traits with incompatible abstract return types (#26381, Zend/zend_inheritance.c). */
    public function testIncompatibleTraitAbstractReturnTypesFailAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T1 { abstract public function f(): int; }
trait T2 { abstract public function f(): string; }
class C {
    use T1, T2;
    public function f(): int { return 1; }
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of C::f(): int must be compatible with T2::f(): string');
        $runtime->parseAndCompile($code, 'trait_abs_return_conflict.php');
    }

    /** Two traits with incompatible abstract parameter types (#26381). */
    public function testIncompatibleTraitAbstractParamTypesFailAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T1 { abstract public function f(int $x); }
trait T2 { abstract public function f(string $x); }
class C {
    use T1, T2;
    public function f(int $x) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of C::f(int $x) must be compatible with T2::f(string $x)');
        $runtime->parseAndCompile($code, 'trait_abs_param_conflict.php');
    }

    /** Trait-vs-trait abstract conflict when composing class has no method (#26381). */
    public function testTraitVersusTraitAbstractConflictWithoutClassMethod(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T1 { abstract public function f(): int; }
trait T2 { abstract public function f(): string; }
abstract class C {
    use T1, T2;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of T1::f(): int must be compatible with T2::f(): string');
        $runtime->parseAndCompile($code, 'trait_abs_vs_trait.php');
    }

    /** Compatible identical trait abstracts compose (#26381). */
    public function testCompatibleTraitAbstractsAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T1 { abstract public function f(): int; }
trait T2 { abstract public function f(): int; }
class C {
    use T1, T2;
    public function f(): int { return 1; }
}
echo (new C)->f(), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'trait_abs_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("1\n", ob_get_clean());
    }

    /** Child dropping parent default is incompatible (zend_inheritance.c, #26520). */
    public function testChildDroppingParentDefaultFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(int $x = 1) {} }
class B extends A { public function f(int $x) {} }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of B::f(int $x) must be compatible with A::f(int $x = 1)');
        $runtime->parseAndCompile($code, 'default_drop.php');
    }

    /** Abstract parent: same default-drop LSP (#26520). */
    public function testChildDroppingAbstractParentDefaultFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A { abstract public function f(int $x = 1); }
class B extends A { public function f(int $x) {} }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of B::f(int $x) must be compatible with A::f(int $x = 1)');
        $runtime->parseAndCompile($code, 'default_drop_abstract.php');
    }

    /** Child may add a default when the parent has none (#26520). */
    public function testChildAddingDefaultAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(int $x) {} }
class B extends A { public function f(int $x = 1) { echo "ok\n"; } }
(new B)->f();
PHP;
        $block = $runtime->parseAndCompile($code, 'default_add.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    /** Different default values remain compatible (#26520). */
    public function testChildChangingDefaultValueAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(int $x = 1) {} }
class B extends A { public function f(int $x = 2) { echo $x, "\n"; } }
(new B)->f();
PHP;
        $block = $runtime->parseAndCompile($code, 'default_change.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("2\n", ob_get_clean());
    }

    /**
     * Hooked property type widen: Zend cites $prop::get() prototypes (#29690).
     */
    public function testHookedPropertyTypeWidenCitesGetHook(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
                $this->markTestSkipped('property hooks disabled on reference profile');
            }
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
class A {
  public string $prop {
    get => "a";
    set(string $v) {}
  }
}
class B extends A {
  public string|int $prop {
    get => 1;
    set(string|int $v) {}
  }
}
PHP;
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(
                'Declaration of B::$prop::get(): string|int must be compatible with A::$prop::get(): string'
            );
            $runtime->parseAndCompile($code, 'hook_prop_type_widen.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }

    /**
     * Set-only hooked property type narrow: Zend cites $prop::set() (#29690).
     */
    public function testHookedPropertyTypeNarrowCitesSetHook(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
                $this->markTestSkipped('property hooks disabled on reference profile');
            }
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
class A {
  public string|int $prop {
    set(string|int $v) {}
  }
}
class B extends A {
  public string $prop {
    set(string $v) {}
  }
}
PHP;
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage(
                'Declaration of B::$prop::set(string $v): void must be compatible with A::$prop::set(string|int $v): void'
            );
            $runtime->parseAndCompile($code, 'hook_prop_type_narrow_set.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }

    /** Plain vs hooked still uses property-type LSP wording (#29690). */
    public function testPlainChildAgainstHookedParentUsesPropertyTypeMessage(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
                $this->markTestSkipped('property hooks disabled on reference profile');
            }
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
class A {
  public string $prop {
    get => "a";
    set {}
  }
}
class B extends A {
  public int $prop;
}
PHP;
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage('Type of B::$prop must be string (as in class A)');
            $runtime->parseAndCompile($code, 'plain_vs_hooked_prop.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }
}
