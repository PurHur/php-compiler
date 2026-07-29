<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4263 */
final class FinalMethodOverrideCheckTest extends TestCase
{
    public function testOverrideFinalParentMethodFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    final public function foo(): void {}
}
class Child extends Base {
    public function foo(): void {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot override final method Base::foo()');
        $runtime->parseAndCompile($code, 'override_final.php');
    }

    public function testNonFinalOverrideCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    public function foo(): void {}
}
class Child extends Base {
    public function foo(): void { echo "ok\n"; }
}
(new Child())->foo();
PHP;
        $block = $runtime->parseAndCompile($code, 'ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testTraitMethodCannotOverrideFinalParentMethod(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    final public function foo(): void {}
}
trait T {
    public function foo(): void {}
}
class Child extends Base {
    use T;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot override final method Base::foo()');
        $runtime->parseAndCompile($code, 'trait_override_final.php');
    }

    /** @covers issue #24884 — cross-eval must hit inheritFromParent, not only same-script FinalMethodOverrideCheck */
    public function testEvalCannotOverrideFinalParentMethod(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    final public function f(): void {}
}
eval('class B extends A { public function f(): void {} }');
echo "EVAL_OK\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_method_eval_override.php');
        $this->assertNotNull($block);
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot override final method A::f()');
        $runtime->run($block);
    }

    /** @covers issue #24884 */
    public function testEvalTraitCannotOverrideFinalParentMethod(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    final public function f(): void {}
}
eval('trait T { public function f(): void {} } class B extends A { use T; }');
echo "EVAL_OK\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_method_eval_trait_override.php');
        $this->assertNotNull($block);
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot override final method A::f()');
        $runtime->run($block);
    }

    /** @covers issue #24884 */
    public function testEvalCannotOverrideFinalGrandparentMethod(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    final public function f(): void {}
}
class M extends A {}
eval('class C extends M { public function f(): void {} }');
echo "EVAL_OK\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_method_eval_grandparent.php');
        $this->assertNotNull($block);
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot override final method A::f()');
        $runtime->run($block);
    }
}
