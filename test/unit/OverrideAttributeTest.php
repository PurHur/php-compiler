<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3211 */
final class OverrideAttributeTest extends TestCase
{
    private function requireOverrideValidation(): void
    {
        if (!CompilerVersion::supportsOverrideAttribute()) {
            $this->markTestSkipped('Override validation disabled on reference profile');
        }
    }

    public function testOverrideWithoutParentCompilesOnReferenceProfile(): void
    {
        if (CompilerVersion::supportsOverrideAttribute()) {
            $this->markTestSkipped('Requires PHP 8.2 reference profile');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {}
class B extends A {
    #[\Override]
    public function foo(): void {}
}
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'override_no_parent_ref.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testInvalidOverrideFailsAtCompileTime(): void
    {
        $this->requireOverrideValidation();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    public function foo(): void {}
}
class Child extends Base {
    #[\Override]
    public function bar(): void {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Child::bar() has #[\Override] attribute, but no matching parent method exists');
        $runtime->parseAndCompile($code, 'override_invalid.php');
    }

    public function testValidOverrideCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    public function foo(): void {}
}
class Child extends Base {
    #[\Override]
    public function foo(): void {}
}
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'override_valid.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testOverrideOnTraitComposedMethodCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public function f(): void {} }
class C { use T; #[\Override] public function f(): void {} }
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'override_trait.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testOverrideSignatureMismatchFailsAtCompileTime(): void
    {
        $this->requireOverrideValidation();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    public function foo(int $x): void {}
}
class Child extends Base {
    #[\Override]
    public function foo(string $x): void {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of Child::foo(string $x): void must be compatible with Base::foo(int $x): void');
        $runtime->parseAndCompile($code, 'override_signature_mismatch.php');
    }

    public function testOverrideCovariantObjectReturnCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { public function foo(): object {} }
class Child extends Base {
    #[\Override]
    public function foo(): \stdClass { return new \stdClass(); }
}
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'override_covariant_object.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testOverrideOnTraitMethodValidatesAtUseSite(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T {
    #[\Override]
    public function foo(): void {}
}
class A {
    public function foo(): void {}
}
class B extends A {
    use T;
}
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'override_trait_body.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testOverrideOnTraitMethodFailsWhenNoParentAtUseSite(): void
    {
        $this->requireOverrideValidation();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T {
    #[\Override]
    public function foo(): void {}
}
class C {
    use T;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('T::foo() has #[\Override] attribute, but no matching parent method exists');
        $runtime->parseAndCompile($code, 'override_trait_body_invalid.php');
    }

    public function testOverrideViaParentInterfaceCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I { public function foo(): void; }
abstract class Base implements I {}
class Child extends Base {
    #[\Override]
    public function foo(): void {}
}
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'override_parent_iface.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testOverrideOnClassFailsAtCompileTime(): void
    {
        $this->requireOverrideValidation();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    public function foo(): void {}
}
#[\Override]
class Child extends Base {
    public function foo(): void {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Attribute "Override" cannot target class (allowed targets: method, class constant, property)');
        $runtime->parseAndCompile($code, 'override_on_class.php');
    }

    public function testOverrideOnTraitDeclarationFailsAtCompileTime(): void
    {
        $this->requireOverrideValidation();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
#[\Override]
trait T {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Attribute "Override" cannot target class (allowed targets: method, class constant, property)');
        $runtime->parseAndCompile($code, 'override_on_trait.php');
    }

    public function testOverrideFailsWhenParentMethodIsPrivate(): void
    {
        $this->requireOverrideValidation();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    private function hidden(): void {}
}
class Child extends Base {
    #[\Override]
    public function hidden(): void {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Child::hidden() has #[\Override] attribute, but no matching parent method exists');
        $runtime->parseAndCompile($code, 'override_private_parent.php');
    }

    public function testOverrideOnProtectedParentMethodCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    protected function hidden(): void {}
}
class Child extends Base {
    #[\Override]
    public function hidden(): void {}
}
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'override_protected_parent.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testOverrideOnAliasedAwayTraitMethodNameCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public function f(): void { echo "t\n"; } }
class C {
    use T { f as protected other; }
    #[\Override]
    public function f(): void { echo "c\n"; }
}
(new C)->f();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'override_trait_alias_original.php'));
        $this->assertSame("c\n", ob_get_clean());
    }

    public function testOverrideWithParentDeclaredLaterInFileCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class B extends A {
    #[\Override]
    public function f(): void {}
}
class A {
    public function f(): void {}
}
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'override_forward_ref.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testOverrideOnInterfaceClassConstantCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I { public const X = 1; }
class C implements I { #[\Override] public const X = 2; }
echo C::X, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'override_const_iface.php'));
        $this->assertSame("2\n", ob_get_clean());
    }

    public function testOverrideOnExtendsClassConstantCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { public const X = 1; }
class Child extends Base { #[\Override] public const X = 2; }
echo Child::X, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'override_const_extends.php'));
        $this->assertSame("2\n", ob_get_clean());
    }

    public function testInvalidOverrideOnClassConstantFailsAtCompileTime(): void
    {
        $this->requireOverrideValidation();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C { #[\Override] public const X = 1; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('C::X has #[\Override] attribute, but no matching parent constant exists');
        $runtime->parseAndCompile($code, 'override_const_invalid.php');
    }

    public function testOverrideOnExtendsPropertyCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { public int $x = 1; }
class Child extends Base { #[\Override] public int $x = 2; }
echo (new Child())->x, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'override_prop_extends.php'));
        $this->assertSame("2\n", ob_get_clean());
    }

    public function testInvalidOverrideOnPropertyFailsAtCompileTime(): void
    {
        $this->requireOverrideValidation();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C { #[\Override] public int $x = 1; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('C::$x has #[\Override] attribute, but no matching parent property exists');
        $runtime->parseAndCompile($code, 'override_prop_invalid.php');
    }
}
