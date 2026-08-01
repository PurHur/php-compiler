<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26638 */
final class NullsafeByRefRhsCompileCheckTest extends TestCase
{
    public function testNullsafePropertyAssignRefIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$a = null;
$b = &$a?->x;
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot take reference of a nullsafe chain');
        $runtime->parseAndCompile($code, 'nullsafe_byref_prop.php');
    }

    public function testNullsafeMethodAssignRefIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$c = null;
$b = &$c?->m();
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot take reference of a nullsafe chain');
        $runtime->parseAndCompile($code, 'nullsafe_byref_method.php');
    }

    public function testNullsafeChainedPropertyAssignRefIsCompileFatal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$a = (object) ['x' => (object) ['y' => 1]];
$b = &$a?->x->y;
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot take reference of a nullsafe chain');
        $runtime->parseAndCompile($code, 'nullsafe_byref_chained.php');
    }

    public function testNullsafeWriteContextStillUsesWriteMessage(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$a = null;
$a?->x = 1;
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage("Can't use nullsafe operator in write context");
        $runtime->parseAndCompile($code, 'nullsafe_write_context.php');
    }

    public function testPlainPropertyAssignRefStillCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$a = (object) ['x' => 1];
$b = &$a->x;
PHP;
        $block = $runtime->parseAndCompile($code, 'plain_prop_byref.php');
        $this->assertNotNull($block);
    }
}
