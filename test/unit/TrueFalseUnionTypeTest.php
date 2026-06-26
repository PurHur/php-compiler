<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #12045 */
final class TrueFalseUnionTypeTest extends TestCase
{
    public function testTrueFalseUnionParamRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(true|false $x): string {
    return 't';
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type contains both true and false, bool should be used instead');
        $runtime->parseAndCompile($code, 'true_false_union_param.php');
    }

    public function testTrueFalseUnionReturnRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): true|false {
    return true;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type contains both true and false, bool should be used instead');
        $runtime->parseAndCompile($code, 'true_false_union_return.php');
    }

    public function testTrueFalseUnionPropertyRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public true|false $x;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Type contains both true and false, bool should be used instead');
        $runtime->parseAndCompile($code, 'true_false_union_property.php');
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
