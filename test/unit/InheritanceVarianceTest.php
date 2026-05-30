<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3323 */
final class InheritanceVarianceTest extends TestCase
{
    public function testNarrowParameterTypeFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I { public function f(A $x): void; }
class A {}
class B extends A {}
class C implements I { public function f(B $x): void {} }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of C::f(B $x): void must be compatible with I::f(A $x): void');
        $runtime->parseAndCompile($code, 'narrow_param.php');
    }

    public function testWideParameterTypeAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I { public function f(B $x): void; }
class A {}
class B extends A {}
class C implements I { public function f(A $x): void {} }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'wide_param.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testCovariantSelfReturnAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base { public function create(): self { return $this; } }
class Child extends Base { public function create(): Child { return $this; } }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'covariant_return.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testWidenedReturnTypeFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Child {}
class Base { public function create(): Child { return new Child(); } }
class Sub extends Base { public function create(): Base { return new Child(); } }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of Sub::create(): Base must be compatible with Base::create(): Child');
        $runtime->parseAndCompile($code, 'wide_return.php');
    }
}
