<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\RedundantTrueFalseUnionCheck;
use PHPUnit\Framework\TestCase;

/** @covers issue #12045, #17996, #26555, #29961 */
final class TrueFalseUnionTypeTest extends TestCase
{
    public function testTrueFalseUnionParamCompilesThenFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(true|false $x): string {
    return 't';
}
PHP;
        $block = $runtime->parseAndCompile($code, 'true_false_union_param.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RedundantTrueFalseUnionCheck::FATAL_MESSAGE);
        $runtime->run($block, false);
    }

    public function testFalseTrueUnionParamUsesMustWording(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(false|true $x): string {
    return 't';
}
PHP;
        $block = $runtime->parseAndCompile($code, 'false_true_union_param.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('bool must be used instead');
        $runtime->run($block, false);
    }

    public function testTrueFalseUnionReturnCompilesThenFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): true|false {
    return true;
}
PHP;
        $block = $runtime->parseAndCompile($code, 'true_false_union_return.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RedundantTrueFalseUnionCheck::FATAL_MESSAGE);
        $runtime->run($block, false);
    }

    public function testTrueFalseUnionPropertyCompilesThenFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public true|false $x;
}
PHP;
        $block = $runtime->parseAndCompile($code, 'true_false_union_property.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RedundantTrueFalseUnionCheck::FATAL_MESSAGE);
        $runtime->run($block, false);
    }

    public function testStandaloneTrueTypeStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(true $x): string {
    return $x ? 't' : 'f';
}
echo f(true);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'standalone_true.php'));
        $this->assertSame('t', ob_get_clean());
    }

    public function testBoolTrueUnionParamFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(bool|true $x): string {
    return 't';
}
PHP;
        $block = $runtime->parseAndCompile($code, 'bool_true_union_param.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RedundantTrueFalseUnionCheck::DUPLICATE_TRUE_MESSAGE);
        $runtime->run($block, false);
    }

    public function testBoolFalseUnionReturnFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): bool|false {
    return false;
}
PHP;
        $block = $runtime->parseAndCompile($code, 'bool_false_union_return.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RedundantTrueFalseUnionCheck::DUPLICATE_FALSE_MESSAGE);
        $runtime->run($block, false);
    }

    public function testTrueBoolUnionPropertyFatalsAtRuntime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public true|bool $x;
}
PHP;
        $block = $runtime->parseAndCompile($code, 'true_bool_union_property.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RedundantTrueFalseUnionCheck::DUPLICATE_TRUE_MESSAGE);
        $runtime->run($block, false);
    }

    public function testTrueFalseBoolStillUsesBothMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(true|false|bool $x): string {
    return 't';
}
PHP;
        $block = $runtime->parseAndCompile($code, 'true_false_bool_union.php');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(RedundantTrueFalseUnionCheck::FATAL_MESSAGE);
        $runtime->run($block, false);
    }
}
