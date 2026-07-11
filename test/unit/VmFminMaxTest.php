<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** Issue #11728 — fmin()/fmax() IEEE pair helpers. */
final class VmFminMaxTest extends TestCase
{
    public function testFminPair(): void
    {
        self::assertSame(0.5, VmMath::fminPair(1.5, 0.5));
        self::assertSame(1.0, VmMath::fminPair(1.0, NAN));
        self::assertSame(2.0, VmMath::fminPair(NAN, 2.0));
    }

    public function testFmaxPair(): void
    {
        self::assertSame(3.0, VmMath::fmaxPair(1.5, 3.0));
        self::assertSame(1.0, VmMath::fmaxPair(1.0, NAN));
        self::assertSame(2.0, VmMath::fmaxPair(NAN, 2.0));
    }
}
