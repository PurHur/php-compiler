<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3386, #3536 */
final class InterfaceImplementationCheckTest extends TestCase
{
    public function testMissingInterfaceMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(): int;
}
class C implements I {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class C contains 1 abstract method');
        $this->expectExceptionMessage('I::f');
        $runtime->parseAndCompile($code, 'missing.php');
    }

    public function testImplementedInterfaceCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(): int;
}
class C implements I {
    public function f(): int {
        return 1;
    }
}
echo C::class, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("C\n", ob_get_clean());
    }

    public function testAbstractClassMayLeaveInterfaceMethodsUnimplemented(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(): int;
}
abstract class C implements I {}
PHP;
        $block = $runtime->parseAndCompile($code, 'abstract_ok.php');
        $this->assertNotNull($block);
    }

    public function testMissingInheritedAbstractStaticFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class Base {
    abstract public static function make(): string;
}
class Child extends Base {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Base::make');
        $runtime->parseAndCompile($code, 'missing_inherited_static.php');
    }

    public function testMissingInterfaceAbstractStaticFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    abstract public static function f(): void;
}
class C implements I {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class C contains 1 abstract method');
        $this->expectExceptionMessage('I::f');
        $runtime->parseAndCompile($code, 'missing_iface_static.php');
    }

    public function testInterfaceAbstractStaticDispatch(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    abstract public static function f(): void;
}
class C implements I {
    public static function f(): void { echo "impl\n"; }
}
C::f();
PHP;
        $block = $runtime->parseAndCompile($code, 'iface_static_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("impl\n", ob_get_clean());
    }

    public function testParentImplementationSatisfiesChild(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f(): int;
}
class Base implements I {
    public function f(): int {
        return 1;
    }
}
class Child extends Base {}
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'inherit_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testPlainInterfacePropertyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public string $name;
}
class C implements I {
    public string $name = 'x';
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Interfaces may not include properties');
        $runtime->parseAndCompile($code, 'plain_iface_property.php');
    }

    public function testInterfaceAsymmetricVisibilityPropertyCompiles(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility disabled on reference profile (#12508)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    private(set) string $slug;
}
class C implements I {
    public string $slug = 'b';
}
$c = new C();
echo $c->slug, "\n";
try {
    $c->slug = 'x';
    echo "set ok\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        $block = $runtime->parseAndCompile($code, 'iface_asymmetric_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame(
            "b\nCannot modify private(set) property C::\$slug from global scope\n",
            ob_get_clean()
        );
    }

    public function testMissingInterfacePropertyHookFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public int $x { get; set; }
}
class Bad implements I {
    public int $y = 1;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class Bad must implement 1 interface property');
        $this->expectExceptionMessage('I::$x');
        $this->expectExceptionMessage('{ get; set; }');
        $runtime->parseAndCompile($code, 'missing_iface_property.php');
    }

    public function testImplementedInterfacePropertyHookCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public int $x { get; set; }
}
class Good implements I {
    public int $x = 1;
}
echo (new Good())->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'iface_property_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("1\n", ob_get_clean());
    }

    public function testMissingInterfaceStaticPropertyHookFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public static string $p { get; set; }
}
class Bad implements I {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class Bad must implement 1 interface property');
        $this->expectExceptionMessage('I::$p');
        $this->expectExceptionMessage('{ get; set; }');
        $runtime->parseAndCompile($code, 'missing_iface_static_property.php');
    }

    public function testImplementedInterfaceStaticPropertyHookCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public static string $p { get; set; }
}
class Good implements I {
    private static string $_p = 'a';
    public static string $p {
        get => self::$_p;
        set { self::$_p = $value; }
    }
}
echo Good::$p, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'iface_static_property_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("a\n", ob_get_clean());
    }

    public function testConcreteClassAbstractPropertyHookFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public string $p {
        get;
    }
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class C contains 1 abstract method');
        $this->expectExceptionMessage('C::$p::get');
        $runtime->parseAndCompile($code, 'concrete_abstract_hook.php');
    }

    public function testMissingParentAbstractPropertyHooksFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    public string $p {
        get;
        set;
    }
}
class B extends A {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class B contains 2 abstract methods');
        $this->expectExceptionMessage('A::$p::get');
        $this->expectExceptionMessage('A::$p::set');
        $runtime->parseAndCompile($code, 'missing_parent_hooks.php');
    }

    public function testEvalAbstractPropertyHookFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $outer = <<<'PHP'
<?php
$ok = eval('abstract class BaseE { abstract public string $x { get; } } class ChildE extends BaseE {}');
echo ($ok === false ? 'eval-false' : 'eval-not-false'), "\n";
echo class_exists('ChildE', false) ? "child-exists\n" : "child-missing\n";
PHP;
        $block = $runtime->parseAndCompile($outer, 'eval_abstract_property_hook.php');
        ob_start();
        $runtime->run($block);
        $out = ob_get_clean();
        $this->assertSame("eval-false\nchild-missing\n", $out);
        $this->assertFalse(isset($runtime->vm()->context->classes['childe']));
    }

    public function testAbstractClassGetSetHooksSubclassCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    public string $p {
        get;
        set;
    }
}
class B extends A {
    public string $p {
        get => $this->p;
        set => $this->p = $value;
    }
}
$b = new B();
$b->p = 'hi';
echo $b->p, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'abstract_hooks_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("hi\n", ob_get_clean());
    }
}
