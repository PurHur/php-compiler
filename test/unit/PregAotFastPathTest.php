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

    public function testSingleCharLiteralReplace(): void
    {
        // Issue #27119 — NestedJIT-safe /a/ (no strrpos/strncmp).
        $this->assertSame(1, PregAotFastPath::patternKind('/a/'));
        $this->assertSame(1, PregAotFastPath::matchCount('/a/', 'bab', 0));
        $this->assertSame('bXb', PregAotFastPath::replaceOrEmpty('/a/', 'X', 'bab', -1));
        $this->assertSame(1, PregAotFastPath::patternKind('#a#'));
        $this->assertSame('bXb', PregAotFastPath::replaceOrEmpty('#a#', 'X', 'bab', -1));
    }

    public function testLiteralCaptureGroups(): void
    {
        $this->assertSame(8, PregAotFastPath::patternKind('/(a)(b)/'));
        $this->assertSame(1, PregAotFastPath::matchCount('/(a)(b)/', 'ab', 0));
        $this->assertSame(3, PregAotFastPath::lastCapCount());
        $this->assertSame('ab', PregAotFastPath::lastCap(0));
        $this->assertSame('a', PregAotFastPath::lastCap(1));
        $this->assertSame('b', PregAotFastPath::lastCap(2));
        $this->assertSame(0, PregAotFastPath::matchCount('/(a)(b)/', 'xy', 0));
    }

    public function testAnchoredLiteralPrefix(): void
    {
        $this->assertSame(9, PregAotFastPath::patternKind('/^b/'));
        $this->assertSame(9, PregAotFastPath::patternKind('#^foo#'));
        $this->assertSame(0, PregAotFastPath::matchCount('/^b/', 'foo', 0));
        $this->assertSame(1, PregAotFastPath::matchCount('/^b/', 'bar', 0));
        $this->assertSame(1, PregAotFastPath::matchCount('/^b/', 'baz', 0));
        $this->assertSame(0, PregAotFastPath::patternKind('/^b$/'));
    }
}
