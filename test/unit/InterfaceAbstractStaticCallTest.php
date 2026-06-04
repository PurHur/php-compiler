<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5383 */
final class InterfaceAbstractStaticCallTest extends TestCase
{
    public function testInterfaceAbstractStaticCallThrowsZendMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public static function f(): void;
}
I::f();
PHP;
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot call abstract method I::f()');
        $runtime->run($runtime->parseAndCompile($code, 'interface_abstract_static_call.php'));
    }

    public function testAbstractClassStaticCallThrowsZendMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract public static function f(): void;
}
A::f();
PHP;
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot call abstract method A::f()');
        $runtime->run($runtime->parseAndCompile($code, 'abstract_static_call.php'));
    }
}
