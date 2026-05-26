<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\levenshtein;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for levenshtein(). */
final class LevenshteinBuiltinTest extends TestCase
{
    public function testIdenticalStrings(): void
    {
        $this->assertSame(0, $this->invoke('abc', 'abc'));
    }

    public function testKittenSitting(): void
    {
        $this->assertSame(3, $this->invoke('kitten', 'sitting'));
    }

    public function testEmptyToNonempty(): void
    {
        $this->assertSame(3, $this->invoke('', 'abc'));
    }

    public function testCustomCosts(): void
    {
        $this->assertSame(5, $this->invoke('a', 'b', 5, 5, 5));
    }

    public function testTooLongReturnsMinusOne(): void
    {
        $this->assertSame(-1, $this->invoke(str_repeat('a', 256), 'b'));
    }

    private function invoke(
        string $s1,
        string $s2,
        int $ins = 1,
        int $repl = 1,
        int $del = 1
    ): int {
        $runtime = new Runtime();
        $fn = new levenshtein();
        $frame = $fn->getFrame($runtime->vmContext);
        $args = [new VMVariable(), new VMVariable()];
        $args[0]->string($s1);
        $args[1]->string($s2);
        if (1 !== $ins) {
            $v = new VMVariable();
            $v->int($ins);
            $args[] = $v;
        }
        if (1 !== $repl) {
            while (\count($args) < 3) {
                $args[] = new VMVariable();
                $args[2]->int(1);
            }
            $v = new VMVariable();
            $v->int($repl);
            $args[] = $v;
        }
        if (1 !== $del) {
            while (\count($args) < 4) {
                $pad = new VMVariable();
                $pad->int(1);
                $args[] = $pad;
            }
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
