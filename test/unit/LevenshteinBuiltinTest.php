<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\levenshtein;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for levenshtein(). */
final class LevenshteinBuiltinTest extends TestCase
{
    public function testEmptyStrings(): void
    {
        $this->assertSame(0, $this->runLevenshtein('', ''));
    }

    public function testKittenToSitting(): void
    {
        $this->assertSame(3, $this->runLevenshtein('kitten', 'sitting'));
    }

    public function testCustomCosts(): void
    {
        $this->assertSame(1, $this->runLevenshtein('abc', 'ab', 2, 1, 1));
    }

    public function testEmptyToNonempty(): void
    {
        $this->assertSame(3, $this->runLevenshtein('', 'abc'));
    }

    public function testLongStringsBeyond255Bytes(): void
    {
        $a = str_repeat('a', 300);
        $b = str_repeat('b', 300);
        $this->assertSame(300, $this->runLevenshtein($a, $b));
    }

    private function runLevenshtein(
        string $a,
        string $b,
        ?int $ins = null,
        ?int $rep = null,
        ?int $del = null
    ): int {
        $runtime = new Runtime();
        $fn = new levenshtein();
        $frame = $fn->getFrame($runtime->vmContext);
        $args = [new VMVariable(), new VMVariable()];
        $args[0]->string($a);
        $args[1]->string($b);
        if (null !== $ins) {
            $v = new VMVariable();
            $v->int($ins);
            $args[] = $v;
        }
        if (null !== $rep) {
            $v = new VMVariable();
            $v->int($rep);
            $args[] = $v;
        }
        if (null !== $del) {
            $v = new VMVariable();
            $v->int($del);
            $args[] = $v;
        }
        $frame->calledArgs = $args;
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);

        return $frame->returnVar->toInt();
    }
}
