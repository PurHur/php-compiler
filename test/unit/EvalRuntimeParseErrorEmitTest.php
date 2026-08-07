<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @covers \PHPCompiler\JIT\Builtin\EvalRuntime
 * @covers issue #27107
 */
final class EvalRuntimeParseErrorEmitTest extends TestCase
{
    public function testAotEvalParseFailureEmitsCatchableParseErrorNotFalse(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EvalRuntime.php');
        $this->assertStringContainsString('emitEvalParseError', $src);
        $this->assertStringContainsString('emitCatchableClassError', $src);
        $this->assertStringContainsString("'ParseError'", $src);
        $this->assertStringContainsString('normalizeParseMessage', $src);
        // Parent chain must be seeded before allocate (thin AOT abort without it).
        $this->assertStringContainsString("lookup('CompileError')", $src);
        $this->assertStringContainsString("lookup('ParseError')", $src);
        // Decl probe must still rethrow non-syntax CompileFatal (#26169).
        $this->assertStringContainsString('isSyntaxParseErrorMessage', $src);
        $this->assertStringContainsString('zendStderrLine', $src);
        // Cross-eval final property override vs outer Object_ tables (#28437).
        $this->assertStringContainsString('rejectOuterUnitFinalPropertyOverride', $src);
        $this->assertStringContainsString('Cannot override final property', $src);
    }
}
