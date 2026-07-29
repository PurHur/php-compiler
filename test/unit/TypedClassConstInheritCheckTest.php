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
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            if (!\PHPCompiler\CompilerVersion::supportsInterfaceTypedConstants()) {
                $this->markTestSkipped('typed interface constants require forward profile 8.3+ (#24917)');
            }
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
interface I { public const array X = [1]; }
class C implements I { public const string X = 'not-array'; }
PHP;
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage('Type of C::X must be compatible with I::X of type array');
            $runtime->parseAndCompile($code, 'interface_typed_const_inherit_bad.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #5982 */
    public function testCompatibleInterfaceTypedConstantOverrideCompilesAndRuns(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            if (!\PHPCompiler\CompilerVersion::supportsInterfaceTypedConstants()) {
                $this->markTestSkipped('typed interface constants require forward profile 8.3+ (#24917)');
            }
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
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    /** @covers issue #7042 */
    public function testConflictingInterfaceTypedConstantsWithoutOverrideFailsCompile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            if (!\PHPCompiler\CompilerVersion::supportsInterfaceTypedConstants()) {
                $this->markTestSkipped('typed interface constants require forward profile 8.3+ (#24917)');
            }
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
interface I { public const string X = 'a'; }
interface J { public const int X = 1; }
class C implements I, J {}
echo C::X, "\n";
PHP;
            $this->expectException(\CompileError::class);
            $this->expectExceptionMessage('Cannot inherit previously-inherited or override constant X from interface J');
            $runtime->parseAndCompile($code, 'interface_typed_const_multi_conflict.php');
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
