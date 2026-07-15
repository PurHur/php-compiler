<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPUnit\Framework\TestCase;

/** PregJitHelper trivial literal matchArgv fast path (#16075). */
final class PregJitTrivialMatchTest extends TestCase
{
    public function testLiteralPatternMatchArgv(): void
    {
        $this->assertSame(1, PregJitHelper::matchArgvTrivialUnanchored('/x/', 'x'));
        $this->assertSame(0, PregJitHelper::matchArgvTrivialUnanchored('/x/', 'y'));
        $this->assertNull(PregJitHelper::matchArgvTrivialUnanchored('/(a)/', 'a'));
        $this->assertNull(PregJitHelper::matchArgvTrivialUnanchored('/^x$/', 'x'));
    }

    public function testPregJitHelperMatchArgvUsesTrivialPath(): void
    {
        $this->assertSame(1, PregJitHelper::matchArgv('/x/', 'x'));
        $this->assertSame(0, PregJitHelper::matchArgv('/x/', 'y'));
    }
}
