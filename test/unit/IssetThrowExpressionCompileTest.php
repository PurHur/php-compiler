<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #29086 */
final class IssetThrowExpressionCompileTest extends TestCase
{
    public function testIssetOnThrowExpressionRejectedAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
isset(throw new Exception('x'));
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Cannot use isset() on the result of an expression (you can use "null !== expression" instead)'
        );
        $runtime->parseAndCompile($code, 'isset_throw_expression.php');
    }

    public function testEmptyOnThrowExpressionStillThrowsAtRuntime(): void
    {
        // Zend allows empty() on expressions; empty(throw …) evaluates the throw.
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
empty(throw new Exception('x'));
PHP;
        $block = $runtime->parseAndCompile($code, 'empty_throw_expression.php');
        $this->assertNotNull($block);
    }

    public function testIssetOnVariableStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$v = 1;
var_dump(isset($v));
PHP;
        $block = $runtime->parseAndCompile($code, 'isset_var.php');
        $this->assertNotNull($block);
    }
}
