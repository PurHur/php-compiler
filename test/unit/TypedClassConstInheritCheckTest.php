<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5953 */
final class TypedClassConstInheritCheckTest extends TestCase
{
    public function testIncompatibleInheritedTypedConstantFailsCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { public const string FOO = 'a'; }
class Bad extends Base { public const int FOO = 1; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type of Bad::FOO must be compatible with Base::FOO of type string');
        $runtime->parseAndCompile($code, 'typed_const_inherit_bad.php');
    }

    public function testCompatibleTypedConstantOverrideCompilesAndRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { public const string FOO = 'a'; }
class Child extends Base { public const string FOO = 'b'; }
echo Child::FOO, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'typed_const_inherit_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("b\n", ob_get_clean());
    }

    public function testUntypedChildOverrideOfTypedParentFailsCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { public const string FOO = 'a'; }
class Bad extends Base { public const FOO = 1; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type of Bad::FOO must be compatible with Base::FOO of type string');
        $runtime->parseAndCompile($code, 'typed_const_inherit_untyped.php');
    }

    public function testPrivateTypedParentAllowsIncompatibleChildRedefinition(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { private const string FOO = 'a'; }
class Child extends Base { public const int FOO = 1; }
echo Child::FOO, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'typed_const_inherit_private.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("1\n", ob_get_clean());
    }

    public function testGrandparentTypedConstantCheckedWhenMidDoesNotRedefine(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { public const string FOO = 'a'; }
class Mid extends Base {}
class Bad extends Mid { public const int FOO = 1; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type of Bad::FOO must be compatible with Base::FOO of type string');
        $runtime->parseAndCompile($code, 'typed_const_inherit_grand.php');
    }

    public function testIncompatibleTypedTraitConstantOverrideFailsCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public const string FOO = 'a'; }
class C {
    use T;
    public const int FOO = 1;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type of C::FOO must be compatible with T::FOO of type string');
        $runtime->parseAndCompile($code, 'typed_trait_const_inherit_bad.php');
    }

    /** @covers issue #5982 */
    public function testIncompatibleInterfaceTypedConstantOverrideFailsCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I { public const array X = [1]; }
class C implements I { public const string X = 'not-array'; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type of C::X must be compatible with I::X of type array');
        $runtime->parseAndCompile($code, 'interface_typed_const_inherit_bad.php');
    }

    /** @covers issue #5982 */
    public function testCompatibleInterfaceTypedConstantOverrideCompilesAndRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I { public const array X = [1]; }
class C implements I { public const array X = [2, 3]; }
echo C::X[0], "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'interface_typed_const_inherit_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("2\n", ob_get_clean());
    }
}
