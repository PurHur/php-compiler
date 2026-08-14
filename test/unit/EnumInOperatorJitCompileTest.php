<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Compiler\CompileFatal;
use PHPUnit\Framework\TestCase;

/**
 * Enum `in` operator is a php-src Parse error (#31158). Compile-only; no LLVM.
 *
 * php-src: Zend/zend_language_parser.y — no `in` operator
 */
final class EnumInOperatorJitCompileTest extends TestCase
{
    public function testEnumInOperatorIsParseError(): void
    {
        $code = <<<'PHP'
<?php
enum E: string { case A = 'a'; case B = 'b'; }
var_export(E::A in [E::A, E::B]);
PHP;
        $runtime = new Runtime();
        $this->expectException(CompileFatal::class);
        $this->expectExceptionMessage('syntax error, unexpected identifier "in"');
        $runtime->parseAndCompile($code, 'enum_in_jit_compile.php');
    }
}
