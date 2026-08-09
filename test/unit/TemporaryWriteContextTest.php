<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../BaseTest.php';

/**
 * #29247 / #29522 — temporary array/new write contexts are compile fatals (zend_compile.c).
 */
class TemporaryWriteContextTest extends TestCase
{
    private function compileSnippet(string $code): void
    {
        $factory = new \PhpParser\ParserFactory();
        $parser = new \PHPCfg\Parser($factory->createForNewestSupportedVersion());
        $script = $parser->parse('<?php ' . $code, 'Command line code');
        (new Compiler())->compile($script);
    }

    public function testLiteralArrayDimAssignIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use temporary expression in write context');
        $this->compileSnippet('[1, 2][0] = 9;');
    }

    public function testLiteralArrayAppendIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use temporary expression in write context');
        $this->compileSnippet('[1, 2][] = 3;');
    }

    public function testUnsetLiteralArrayDimIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use temporary expression in write context');
        $this->compileSnippet('unset([1, 2][0]);');
    }

    public function testNewPropCoalesceAssignIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use temporary expression in write context');
        $this->compileSnippet('(new stdClass)->x ??= 1;');
    }

    public function testNewPropAssignStillFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use temporary expression in write context');
        $this->compileSnippet('(new stdClass)->x = 1;');
    }

    public function testUnsetNewPropIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use temporary expression in write context');
        $this->compileSnippet('unset((new stdClass)->x);');
    }

    public function testByRefArgLiteralArrayDimIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use temporary expression in write context');
        $this->compileSnippet('function f(&$x) { $x = 5; } f([1, 2][0]);');
    }

    public function testByRefArgNewPropIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use temporary expression in write context');
        $this->compileSnippet('function f(&$x) { $x = 5; } f((new stdClass)->x);');
    }

    public function testByRefArgFunctionReturnDimStillCompiles(): void
    {
        $this->compileSnippet('function f(&$x) { $x = 5; } function g() { return [1]; } f(g()[0]);');
        $this->assertTrue(true);
    }

    public function testVariableArrayDimAssignStillCompiles(): void
    {
        $this->compileSnippet('$a = [1, 2]; $a[0] = 9;');
        $this->assertTrue(true);
    }

    public function testFunctionReturnDimAssignStillCompiles(): void
    {
        $this->compileSnippet('function f() { return [1, 2]; } f()[0] = 9;');
        $this->assertTrue(true);
    }

    public function testVariablePropCoalesceAssignStillCompiles(): void
    {
        $this->compileSnippet('$o = new stdClass; $o->x ??= 1;');
        $this->assertTrue(true);
    }
}
