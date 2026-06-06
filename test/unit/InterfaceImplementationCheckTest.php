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
        $this->expectExceptionMessage('Class Bad contains 2 abstract methods');
        $this->expectExceptionMessage('I::$x::get');
        $this->expectExceptionMessage('I::$x::set');
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
}
