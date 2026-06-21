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

    public function testNegativeInsertionCostMatchesZend(): void
    {
        $this->assertSame(0, $this->runLevenshtein('a', 'b', -1, 1, 1));
    }

    public function testZeroInsertionCostMatchesZend(): void
    {
        $this->assertSame(1, $this->runLevenshtein('a', 'b', 0, 1, 1));
        $this->assertSame(1, $this->runLevenshtein('abc', 'ab', 0, 1, 1));
    }

    public function testTooManyArgumentsThrowsArgumentCountError(): void
    {
        $this->expectException(\ArgumentCountError::class);
        $this->expectExceptionMessage('levenshtein() expects at most 5 arguments, 6 given');
        $runtime = new Runtime();
        $fn = new levenshtein();
        $frame = $fn->getFrame($runtime->vmContext);
        $args = [];
        foreach ([1, 2, 3, 4, 5, 6] as $i) {
            $v = new VMVariable();
            $v->int($i);
            $args[] = $v;
        }
        $frame->calledArgs = $args;
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
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
