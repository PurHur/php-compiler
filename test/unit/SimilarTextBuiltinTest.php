<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\similar_text;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** VM builtin for similar_text(). */
final class SimilarTextBuiltinTest extends TestCase
{
    public function testHelloWorld(): void
    {
        $this->assertSame(6, $this->runSimilarText('Hello World', 'Hello PHP'));
    }

    public function testPercentByReference(): void
    {
        $percent = 0.0;
        $sim = $this->runSimilarText('Hello World', 'Hello PHP', $percent);
        $this->assertSame(6, $sim);
        $this->assertEqualsWithDelta(60.0, $percent, 0.0001);
    }

    public function testEmptyStrings(): void
    {
        $this->assertSame(0, $this->runSimilarText('', ''));
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
            $ref = new VMVariable();
            $holder = new VMVariable();
            $holder->float(0.0);
            $ref->indirect($holder);
            $args[] = $ref;
        }
        $frame->calledArgs = $args;
        $frame->returnVar = new VMVariable();
        $fn->execute($frame);
        if (null !== $percent && 3 === \count($args)) {
            $percent = $args[2]->resolveIndirect()->toFloat();
        }

        return $frame->returnVar->toInt();
    }
}
