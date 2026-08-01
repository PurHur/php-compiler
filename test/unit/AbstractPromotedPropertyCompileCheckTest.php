<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26529 */
final class AbstractPromotedPropertyCompileCheckTest extends TestCase
{
    public function testAbstractClassPromotedConstructorFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract public function __construct(public int $x);
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot declare promoted property in an abstract constructor');
        $runtime->parseAndCompile($code, 'abstract_promo.php');
    }

    public function testInterfacePromotedConstructorFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    public function __construct(public int $x);
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot declare promoted property in an abstract constructor');
        $runtime->parseAndCompile($code, 'iface_promo.php');
    }

    public function testAbstractTraitPromotedConstructorFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T {
    abstract public function __construct(public int $x);
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot declare promoted property in an abstract constructor');
        $runtime->parseAndCompile($code, 'trait_promo.php');
    }

    public function testConcretePromotedConstructorStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(public int $x) {}
}
echo (new C(7))->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'promo_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("7\n", ob_get_clean());
    }

    public function testAbstractConstructorWithoutPromotionStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A {
    abstract public function __construct(int $x);
}
class B extends A {
    public function __construct(int $x) {
        echo $x, "\n";
    }
}
new B(3);
PHP;
        $block = $runtime->parseAndCompile($code, 'abstract_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("3\n", ob_get_clean());
    }
}
