<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4455 */
final class FinalClassConstCheckTest extends TestCase
{
    public function testChildCannotOverrideFinalClassConstant(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    final const X = 1;
}
class Child extends Base {
    const X = 2;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Child::X cannot override final constant Base::X');
        $runtime->parseAndCompile($code, 'final_const.php');
    }

    public function testClassCannotOverrideFinalInterfaceConstant(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface I {
    final const X = 1;
}
class C implements I {
    const X = 2;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('C::X cannot override final constant I::X');
        $runtime->parseAndCompile($code, 'final_iface_const.php');
    }

    public function testInterfaceCannotOverrideParentFinalConstant(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
interface A {
    final const X = 1;
}
interface B extends A {
    const X = 2;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('B::X cannot override final constant A::X');
        $runtime->parseAndCompile($code, 'final_iface_extend.php');
    }

    public function testNonFinalConstantMayBeOverridden(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    const X = 1;
}
class Child extends Base {
    const X = 2;
}
echo Child::X, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'override_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("2\n", ob_get_clean());
    }

    public function testClassCannotOverrideFinalTraitConstant(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { final public const X = 1; }
class C { use T; public const X = 2; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'C and T define the same constant (X) in the composition of C. '
            .'However, the definition differs and is considered incompatible. Class was composed'
        );
        $runtime->parseAndCompile($code, 'trait_final_const_override.php');
    }

    public function testInheritedFinalFromGrandparentBlocksChild(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Grand {
    final const X = 1;
}
class Mid extends Grand {}
class Child extends Mid {
    const X = 2;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Child::X cannot override final constant Grand::X');
        $runtime->parseAndCompile($code, 'grand_final.php');
    }

    /** @covers issue #22329 */
    public function testEvalCannotOverrideFinalClassConstant(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A {
    final public const X = 'a';
}
eval('class B extends A { public const X = "b"; }');
echo B::X, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_const_eval.php');
        $this->assertNotNull($block);
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('B::X cannot override final constant A::X');
        $runtime->run($block);
    }
}
