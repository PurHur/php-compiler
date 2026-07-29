<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #24906 */
final class NonAbstractMethodBodyCheckTest extends TestCase
{
    public function testConcreteMethodWithoutBodyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function f();
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Non-abstract method C::f() must contain body');
        $runtime->parseAndCompile($code, 'method_nobody.php');
    }

    public function testAbstractClassConcreteMethodWithoutBodyFails(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    public function f();
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Non-abstract method A::f() must contain body');
        $runtime->parseAndCompile($code, 'abs_class_nobody.php');
    }

    public function testTraitConcreteMethodWithoutBodyFails(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T {
    public function f();
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Non-abstract method T::f() must contain body');
        $runtime->parseAndCompile($code, 'trait_nobody.php');
    }

    public function testAbstractMethodWithoutBodyCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract public function f();
}
class B extends A {
    public function f() {
        echo "ok\n";
    }
}
(new B())->f();
PHP;
        $block = $runtime->parseAndCompile($code, 'abstract_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testEmptyMethodBodyCompilesAndRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function f() {}
}
echo "ok\n";
(new C())->f();
PHP;
        $block = $runtime->parseAndCompile($code, 'empty_body.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testInterfaceMethodWithoutBodyCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function f();
}
class C implements I {
    public function f() {
        echo "ok\n";
    }
}
(new C())->f();
PHP;
        $block = $runtime->parseAndCompile($code, 'iface_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
