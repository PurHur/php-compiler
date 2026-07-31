<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * @covers issue #25384 — cross-file / eval inheritance variance (zend_inheritance.c)
 */
final class InheritanceVarianceCrossFileTest extends TestCase
{
    /** @covers issue #25384 */
    public function testEvalRejectsIncompatibleReturnOverride(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A1 { function f(): int { return 1; } }
eval('class B1 extends A1 { function f(): string { return "x"; } }');
echo "ret_accepted\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'issue_25384_eval_ret.php');
        $this->assertNotNull($block);
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of B1::f(): string must be compatible with A1::f(): int');
        $runtime->run($block);
    }

    /** @covers issue #25384 */
    public function testEvalRejectsIncompatibleParamOverride(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A2 { function f(int $x) {} }
eval('class B2 extends A2 { function f(string $x) {} }');
echo "param_accepted\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'issue_25384_eval_param.php');
        $this->assertNotNull($block);
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of B2::f(string $x) must be compatible with A2::f(int $x)');
        $runtime->run($block);
    }

    /** @covers issue #25384 */
    public function testEvalAllowsCovariantObjectToStdClass(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A3 { function f(): object { return new stdClass; } }
eval('class B3 extends A3 { function f(): stdClass { return new stdClass; } }');
echo get_class((new B3)->f()), "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'issue_25384_eval_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("stdClass\n", ob_get_clean());
    }

    /** @covers issue #25384 */
    public function testEvalRejectsIncompatibleAbstractReturnOverride(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class A { abstract function f(): int; }
eval('class B extends A { function f(): string { return "x"; } }');
echo "abs_accepted\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'issue_25384_eval_abs.php');
        $this->assertNotNull($block);
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of B::f(): string must be compatible with A::f(): int');
        $runtime->run($block);
    }

    /** @covers issue #25633 — by-ref mismatch on eval inherit */
    public function testEvalRejectsByRefParamOverride(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A4 { function f(&$x) {} }
eval('class B4 extends A4 { function f($x) {} }');
echo "byref_accepted\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'issue_25633_eval_byref.php');
        $this->assertNotNull($block);
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Declaration of B4::f($x) must be compatible with A4::f(&$x)');
        $runtime->run($block);
    }
}
