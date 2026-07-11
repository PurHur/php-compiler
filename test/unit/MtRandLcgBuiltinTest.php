<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmCombinedLcg;
use PHPCompiler\ext\standard\VmMt19937;
use PHPUnit\Framework\TestCase;

/** @coversNothing */
final class MtRandLcgBuiltinTest extends TestCase
{
    protected function setUp(): void
    {
        VmMt19937::resetForTests();
        VmCombinedLcg::resetForTests();
    }

    public function testMtRandMatchesZendSeed12345(): void
    {
        VmMt19937::seed(12345);
        self::assertSame(1996335345, VmMt19937::mtRand31());
        self::assertSame(82, VmMt19937::range(1, 100));
    }

    public function testMtRandRejectsInvertedRange(): void
    {
        VmMt19937::seed(1);
        $this->expectException(\ValueError::class);
        VmMt19937::range(10, 1);
    }

    public function testCombinedLcgSeededStepsMatchZendReference(): void
    {
        VmCombinedLcg::seed(12345, 67890);
        self::assertEqualsWithDelta(0.94359739042414, VmCombinedLcg::value(), 1e-10);
        self::assertEqualsWithDelta(0.90831884935795, VmCombinedLcg::value(), 1e-10);
    }
}
