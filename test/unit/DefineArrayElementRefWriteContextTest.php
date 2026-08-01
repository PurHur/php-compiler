<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../BaseTest.php';

/**
 * #26488 / #5409 — define()/ConstFetch array element assign-by-ref is write-context fatal.
 *
 * Zend rejects any constant fetch dim as a temporary write target, including
 * runtime define() and undefined names (zend_compile.c).
 */
class DefineArrayElementRefWriteContextTest extends TestCase
{
    private function compileSnippet(string $code): void
    {
        $factory = new \PhpParser\ParserFactory();
        $parser = new \PHPCfg\Parser($factory->createForNewestSupportedVersion());
        $script = $parser->parse('<?php ' . $code, 'Command line code');
        (new Compiler())->compile($script);
    }

    public function testDefineArrayElementAssignRefIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use temporary expression in write context');
        $this->compileSnippet(<<<'PHP'
define('A', [1]);
$a = &A[0];
$a = 2;
var_dump(A);
PHP);
    }

    public function testDefineArrayElementAssignIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use temporary expression in write context');
        $this->compileSnippet(<<<'PHP'
define('A', [1]);
A[0] = 2;
PHP);
    }

    public function testUndefinedConstArrayElementAssignRefIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use temporary expression in write context');
        $this->compileSnippet(<<<'PHP'
$a = &UNDEFINED_CONST[0];
PHP);
    }

    public function testConstArrayElementAssignRefStillFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot use temporary expression in write context');
        $this->compileSnippet(<<<'PHP'
const A = [1];
$a = &A[0];
PHP);
    }

    public function testVariableArrayElementAssignRefStillCompiles(): void
    {
        $this->compileSnippet(<<<'PHP'
$arr = [1];
$a = &$arr[0];
$a = 2;
PHP);
        $this->assertTrue(true);
    }
}
