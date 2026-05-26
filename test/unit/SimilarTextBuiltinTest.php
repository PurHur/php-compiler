<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\similar_text;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for similar_text(). */
final class SimilarTextBuiltinTest extends TestCase
{
    public function testEmptyStrings(): void
    {
        $this->assertSame(0, $this->runSimilarText('', ''));
    }

    public function testBafoobarBarfoo(): void
    {
        $this->assertSame(5, $this->runSimilarText('bafoobar', 'barfoo'));
    }

    public function testArgumentOrder(): void
    {
        $this->assertSame(3, $this->runSimilarText('barfoo', 'bafoobar'));
    }

    public function testPercentByReference(): void
    {
        $percent = 0.0;
        $sim = $this->runSimilarText('bafoobar', 'barfoo', $percent);
        $this->assertSame(5, $sim);
        $this->assertEqualsWithDelta(71.428571428571, $percent, 1e-9);
    }

    private function runSimilarText(string $a, string $b, ?float &$percent = null): int
    {
        $runtime = new Runtime();
        $fn = new similar_text();
        $frame = $fn->getFrame($runtime->vmContext);
        $args = [new VMVariable(), new VMVariable()];
        $args[0]->string($a);
        $args[1]->string($b);
        if (null !== $percent) {
            $pctVar = new VMVariable();
            $pctVar->float(0.0);
            $args[] = $pctVar;
        }
        $frame->calledArgs = $args;
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        if (null !== $percent && isset($pctVar)) {
            $percent = $pctVar->toFloat();
        }

        return $frame->returnVar->toInt();
    }
}
