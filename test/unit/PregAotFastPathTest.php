<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PregAotFastPath;
use PHPUnit\Framework\TestCase;

/** Thin AOT preg fast path (#24115). */
final class PregAotFastPathTest extends TestCase
{
    public function testDigitPlusMatch(): void
    {
        $this->assertSame(2, PregAotFastPath::patternKind('/\d+/'));
        $this->assertSame(1, PregAotFastPath::matchCount('/\d+/', 'ab 12 cd', 0));
        $this->assertSame(0, PregAotFastPath::matchCount('/\d+/', 'no-digits', 0));
    }

    public function testDigitCapturePatternKind(): void
    {
        $this->assertSame(3, PregAotFastPath::patternKind('/(\d+)/'));
        $this->assertSame(3, PregAotFastPath::patternKind('#(\d+)#'));
        $this->assertSame(1, PregAotFastPath::matchCount('/(\d+)/', 'ab 12 cd', 0));
        $this->assertSame(2, PregAotFastPath::lastCapCount());
        $this->assertSame('12', PregAotFastPath::lastCap(0));
        $this->assertSame('12', PregAotFastPath::lastCap(1));
    }

    public function testSpacePlusReplace(): void
    {
        $this->assertSame(4, PregAotFastPath::patternKind('/\s+/'));
        $this->assertSame('a_b', PregAotFastPath::replaceOrEmpty('/\s+/', '_', 'a  b', -1));
    }

    public function testLiteralMatch(): void
    {
        $this->assertSame(1, PregAotFastPath::patternKind('/abc/'));
        $this->assertSame(1, PregAotFastPath::matchCount('/abc/', 'xxabcxx', 0));
        $this->assertSame(0, PregAotFastPath::matchCount('/abc/', 'xyz', 0));
    }
}
