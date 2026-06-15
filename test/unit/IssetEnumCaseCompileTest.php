<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #8802 */
final class IssetEnumCaseCompileTest extends TestCase
{
    public function testIssetOnEnumCaseExpressionRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
}
var_dump(isset(E::A));
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Cannot use isset() on the result of an expression (you can use "null !== expression" instead)'
        );
        $runtime->parseAndCompile($code, 'isset_enum_case.php');
    }

    public function testIssetOnEnumCaseVariableStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: int {
    case A = 1;
}
$v = E::A;
var_dump(isset($v));
PHP;
        $block = $runtime->parseAndCompile($code, 'isset_enum_case_var.php');
        $this->assertNotNull($block);
    }
}
