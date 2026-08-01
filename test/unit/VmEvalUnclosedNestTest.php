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
}
