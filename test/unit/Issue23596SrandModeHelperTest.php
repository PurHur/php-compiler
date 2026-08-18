<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\RandJitHelper;
use PHPCompiler\ext\standard\VmMt19937;
use PHPUnit\Framework\TestCase;

/** Rand NestedJIT helper seedWithMode (#23596). */
final class Issue23596SrandModeHelperTest extends TestCase
{
    public function testSeedWithModeMatchesVmMt19937(): void
    {
        VmMt19937::seed(7, VmMt19937::MT_RAND_PHP);
        $expected = VmMt19937::mtRand31();
        VmMt19937::resetForTests();
        RandJitHelper::seedWithMode(7, VmMt19937::MT_RAND_PHP);
        $this->assertSame($expected, VmMt19937::mtRand31());
    }
}
