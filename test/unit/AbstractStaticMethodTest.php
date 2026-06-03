<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4312 */
final class AbstractStaticMethodTest extends TestCase
{
    public function testAbstractStaticDispatchFromClassAndObject(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class Base {
    abstract public static function make(): string;
}
class Child extends Base {
    public static function make(): string { return 'ok'; }
}
echo Child::make(), "\n";
echo (new Child())::make(), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'abstract_static.php'));
        $this->assertSame("ok\nok\n", ob_get_clean());
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
        $this->expectExceptionMessage('Class Child contains 1 abstract method');
        $this->expectExceptionMessage('Base::make');
        $runtime->parseAndCompile($code, 'missing_static.php');
    }

    public function testPrivateAbstractStaticFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract private static function x(): void;
}
PHP;
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage('cannot be declared private');
        $runtime->parseAndCompile($code, 'private_abstract_static.php');
    }
}
