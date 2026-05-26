<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\metaphone;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for metaphone(). */
final class MetaphoneBuiltinTest extends TestCase
{
    public function testProgram(): void
    {
        $this->assertSame('PRKRM', $this->runMetaphone('program'));
    }

    public function testWashington(): void
    {
        $this->assertSame('WXNKTN', $this->runMetaphone('Washington'));
    }

    public function testMaxPhonemes(): void
    {
        $this->assertSame('PRKR', $this->runMetaphone('program', 4));
    }

    public function testEmpty(): void
    {
        $this->assertSame('', $this->runMetaphone(''));
    }

    /**
     * @return string
     */
    private function runMetaphone(string $value, int $max = 0): string
    {
        $runtime = new Runtime();
        $fn = new metaphone();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string($value);
        $args = [$arg];
        if (0 !== $max) {
            $maxVar = new VMVariable();
            $maxVar->int($max);
            $args[] = $maxVar;
        }
        $frame->calledArgs = $args;
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->toString();
    }
}
