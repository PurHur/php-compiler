<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\metaphone;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for metaphone(). */
final class MetaphoneBuiltinTest extends TestCase
{
    public function testKnightsbridge(): void
    {
        $this->assertSame('NFTSBRJ', $this->runMetaphone('Knightsbridge'));
    }

    public function testEuler(): void
    {
        $this->assertSame('ELR', $this->runMetaphone('Euler'));
    }

    public function testEmpty(): void
    {
        $this->assertSame('', $this->runMetaphone(''));
    }

    public function testMaxPhonemes(): void
    {
        $this->assertSame('NFTS', $this->runMetaphone('Knightsbridge', 4));
    }

    private function runMetaphone(string $value, ?int $max = null): string
    {
        $runtime = new Runtime();
        $fn = new metaphone();
        $frame = $fn->getFrame($runtime->vmContext);
        $arg = new VMVariable();
        $arg->string($value);
        $args = [$arg];
        if (null !== $max) {
            $m = new VMVariable();
            $m->int($max);
            $args[] = $m;
        }
        $frame->calledArgs = $args;
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->toString();
    }
}
