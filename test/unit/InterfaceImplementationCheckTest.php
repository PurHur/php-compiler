<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/** @covers issue #3386, #3536 */
final class InterfaceImplementationCheckTest extends TestCase
{
    use PropertyHookTestSkip;

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
        if (!CompilerVersion::supportsAsymmetricVisibility()) {
            $this->markTestSkipped('asymmetric visibility disabled on reference profile (#12508)');
        }
        if (!CompilerVersion::supportsParenthesizedAsymmetricSetModifier()) {
            $this->markTestSkipped('parenthesized asymmetric set modifier disabled on 8.4.0-dev reference profile (#16450)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public (private(set)) string $slug;
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
        $this->skipUnlessPropertyHooksEnabled();
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

    /** Exact #28374 shape: string $name hooks; plain Good OK; BadI omission fatals. */
    public function testIssue28374InterfaceHookedPropertyOmissionFails(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public string $name { get; set; }
}
class Good implements I {
    public string $name = "g";
}
echo (new Good())->name, "\n";
class BadI implements I {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class BadI must implement 1 interface property');
        $this->expectExceptionMessage('I::$name');
        $this->expectExceptionMessage('{ get; set; }');
        $runtime->parseAndCompile($code, 'issue_28374_iface_hook.php');
    }

    /** #28374: require-split interface — omission must fatal at DECLARE (not only same-script compile). */
    public function testIssue28374RequireSplitMissingInterfaceHookedPropertyFails(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $dir = sys_get_temp_dir().'/phpc_28374_'.bin2hex(random_bytes(4));
        mkdir($dir);
        $iface = $dir.'/iface.php';
        $bad = $dir.'/bad.php';
        $main = $dir.'/main.php';
        try {
            file_put_contents($iface, "<?php\ninterface I { public string \$name { get; set; } }\n");
            file_put_contents($bad, "<?php\nclass BadI implements I {}\necho \"BadI ok\\n\";\n");
            file_put_contents($main, '<?php'."\nrequire ".var_export($iface, true).";\nrequire ".var_export($bad, true).";\n");
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(file_get_contents($main), $main);
            $this->assertNotNull($block);
            ob_start();
            try {
                $runtime->run($block, false);
                ob_end_clean();
                $this->fail('Expected ScriptExit for BadI missing interface hooked property');
            } catch (\PHPCompiler\VM\ScriptExit $e) {
                $out = (string) ob_get_clean();
                $this->assertSame(255, $e->status);
                $this->assertStringNotContainsString('BadI ok', $out);
                // Message text: compliance case interface_property_hook_missing_require.phpt
            }
        } finally {
            @unlink($iface);
            @unlink($bad);
            @unlink($main);
            @rmdir($dir);
        }
    }

    public function testImplementedInterfacePropertyHookCompiles(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
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
        $this->skipUnlessPropertyHooksEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public static string $p { get; set; }
}
class Bad implements I {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(\PHPCompiler\SourcePreprocessor\PropertyHooks::STATIC_HOOK_COMPILE_ERROR);
        $runtime->parseAndCompile($code, 'missing_iface_static_property.php');
    }

    public function testImplementedInterfaceStaticPropertyHookRejected(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
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
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(\PHPCompiler\SourcePreprocessor\PropertyHooks::STATIC_HOOK_COMPILE_ERROR);
        $runtime->parseAndCompile($code, 'iface_static_property_ok.php');
    }

    public function testConcreteClassAbstractPropertyHookFailsAtCompileTime(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
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
        $this->skipUnlessPropertyHooksEnabled();
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

    /** Exact #28373 shape: `abstract public … { get; set; }` on abstract parent. */
    public function testMissingParentAbstractKeywordPropertyHooksFailsAtCompileTime(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract public string $x { get; set; }
}
class Bad extends A {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Class Bad contains 2 abstract methods');
        $this->expectExceptionMessage('A::$x::get');
        $this->expectExceptionMessage('A::$x::set');
        $runtime->parseAndCompile($code, 'issue_28373_missing_parent_abstract_hooks.php');
    }

    /** Plain typed property still satisfies abstract parent hooked property (#28373). */
    public function testPlainPropertySatisfiesParentAbstractHookedProperty(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract public string $x { get; set; }
}
class Plain extends A {
    public string $x;
}
$p = new Plain();
$p->x = 'hi';
echo $p->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'issue_28373_plain_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("hi\n", ob_get_clean());
    }

    public function testEvalAbstractPropertyHookFailsAtCompileTime(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
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
