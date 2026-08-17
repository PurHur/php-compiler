<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmEval;
use PHPUnit\Framework\TestCase;

/** @covers \PHPCompiler\ext\standard\VmEval::innermostUnclosedNestChar */
final class VmEvalUnclosedNestTest extends TestCase
{
    public function testDetectsUnclosedClassBrace(): void
    {
        $this->assertSame('{', VmEval::innermostUnclosedNestChar('class X { function foo() {'));
    }

    public function testIgnoresBracesInsideStrings(): void
    {
        $this->assertNull(VmEval::innermostUnclosedNestChar('echo "{";'));
    }

    public function testDoesNotReturnWrapUnclosedSource(): void
    {
        $wrapped = VmEval::wrapEvalCode('class X { function foo() {');
        $this->assertSame("<?php\nclass X { function foo() {", $wrapped);
        $this->assertStringNotContainsString('return ', $wrapped);
    }

    public function testStillReturnWrapsTrailingExpression(): void
    {
        $this->assertSame("<?php\nreturn \$x + 1;", VmEval::wrapEvalCode('$x + 1'));
    }

    /**
     * @covers \PHPCompiler\ext\standard\VmEval::isZeroLengthEvalSource
     * @covers issue #31914
     */
    public function testZeroLengthEvalSourceIsFailureNotWhitespace(): void
    {
        $this->assertTrue(VmEval::isZeroLengthEvalSource(''));
        $this->assertFalse(VmEval::isZeroLengthEvalSource('   '));
        $this->assertFalse(VmEval::isZeroLengthEvalSource(';'));
        $this->assertFalse(VmEval::isZeroLengthEvalSource("\n"));
        $false = VmEval::falseEvalFailureResult();
        $this->assertSame(VM\Variable::TYPE_BOOLEAN, $false->type);
        $this->assertFalse($false->toBool());
    }

    /** @covers \PHPCompiler\ext\standard\VmEval::normalizeParseMessage */
    public function testNormalizeParseMessageUsesZendUnclosedBrace(): void
    {
        $this->assertSame(
            "Unclosed '{'",
            VmEval::normalizeParseMessage(
                'eval(): Syntax error, unexpected EOF on line 2',
                'class X { function foo() {'
            )
        );
    }
}
