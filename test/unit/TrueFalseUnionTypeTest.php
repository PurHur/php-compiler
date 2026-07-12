<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\RedundantTrueFalseUnionCheck;
use PHPUnit\Framework\TestCase;

/** @covers issue #12045, #17996 */
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
}
