<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26515 */
final class VariadicPromotedPropertyCompileCheckTest extends TestCase
{
    public function testVariadicPromotedConstructorParamFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public function __construct(public int ...$x) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot declare variadic promoted property');
        $runtime->parseAndCompile($code, 'variadic_promoted.php');
    }

    public function testVariadicPromotedTraitConstructorFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T {
    public function __construct(public int ...$x) {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot declare variadic promoted property');
        $runtime->parseAndCompile($code, 'variadic_promoted_trait.php');
    }

    public function testNonVariadicPromotionStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    public function __construct(public int $x) {}
}
echo (new A(7))->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'promo_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("7\n", ob_get_clean());
    }

    public function testNonPromotedVariadicStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class B {
    public function __construct(int ...$x) {
        echo count($x), "\n";
    }
}
new B(1, 2, 3);
PHP;
        $block = $runtime->parseAndCompile($code, 'variadic_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("3\n", ob_get_clean());
    }
}
