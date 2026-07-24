<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #22927 */
final class AbstractMethodBodyCheckTest extends TestCase
{
    public function testAbstractMethodWithBodyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract function f() {
        return 1;
    }
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Abstract function A::f() cannot contain body');
        $runtime->parseAndCompile($code, 'abstract_body.php');
    }

    public function testAbstractMethodWithEmptyBodyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract function f() {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Abstract function A::f() cannot contain body');
        $runtime->parseAndCompile($code, 'abstract_empty_body.php');
    }

    public function testTraitAbstractMethodWithBodyFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T {
    abstract function f() {
        return 1;
    }
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Abstract function T::f() cannot contain body');
        $runtime->parseAndCompile($code, 'trait_abstract_body.php');
    }

    public function testAbstractMethodWithoutBodyCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract function f();
}
class B extends A {
    function f() {
        echo "ok\n";
    }
}
(new B())->f();
PHP;
        $block = $runtime->parseAndCompile($code, 'abstract_no_body.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
