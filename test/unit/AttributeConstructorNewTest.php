<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5418 #22391 */
final class AttributeConstructorNewTest extends TestCase
{
    public function testNewInAttributeConstructorMaterializesOnReflection(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class SomeAttr {
    public function __construct(public object $o) {}
}
#[SomeAttr(new stdClass())]
class C {}
var_dump((new ReflectionClass(C::class))->getAttributes()[0]->newInstance()->o);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_constructor_new.php'));
        $out = ob_get_clean();
        $this->assertMatchesRegularExpression('/object\(stdClass\)#\d+/', $out);
    }

    public function testNewWithArrayCtorArgMaterializesOnReflection(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {
    public array $a;
    public function __construct(array $a) { $this->a = $a; }
}
#[Attribute]
class A {
    public function __construct(public Box $b) {}
}
#[A(new Box([9]))]
class T {}
$r = (new ReflectionClass(T::class))->getAttributes(A::class)[0]->newInstance();
echo implode(",", $r->b->a), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_constructor_new_array.php'));
        $this->assertSame("9\n", ob_get_clean());
    }

    public function testBareArrayAttributeArg(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[Attribute]
class A {
    public function __construct(public array $a) {}
}
#[A([1, "k" => 2])]
class T {}
$r = (new ReflectionClass(T::class))->getAttributes(A::class)[0]->newInstance();
echo $r->a[0], ",", $r->a["k"], "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'attribute_constructor_bare_array.php'));
        $this->assertSame("1,2\n", ob_get_clean());
    }

    /**
     * #27709 — anonymous class in attribute ctor arg: Zend const-expr fatal, not "Dynamic class name…".
     *
     * @see Zend/zend_compile.c — zend_compile_const_expr / ZEND_ACC_ANON_CLASS
     */
    public function testAnonymousClassInAttributeCtorIsConstExprFatal(): void
    {
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use anonymous class in constant expression');
        $runtime->parseAndCompile(
            <<<'PHP'
<?php
#[Attribute]
class A { public function __construct(public object $o) {} }
#[A(new class {})]
class C {}
PHP,
            'attribute_constructor_anon_class.php'
        );
    }
}
