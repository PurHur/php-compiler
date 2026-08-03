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
        // Issue #27181 — int-only find for LLVM concat bridge.
        $this->assertSame(1, PregAotFastPath::replaceFindNext('/a/', 'bab', 0));
        $this->assertSame(1, PregAotFastPath::takeLastReplacePos());
        $this->assertSame(1, PregAotFastPath::takeLastReplaceBodyLen());
        $this->assertSame(0, PregAotFastPath::replaceFindNext('/a/', 'xyz', 0));
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

    public function testMatchAllWordPlusStoresAll(): void
    {
        // Issue #27195 — thin AOT preg_match_all /\w+/ "a b c" → a,b,c
        $this->assertSame(3, PregAotFastPath::matchAllStore('/\w+/', 'a b c', 0, 0));
        $this->assertSame(3, PregAotFastPath::matchAllPartCount());
        $this->assertSame('a', PregAotFastPath::matchAllPart(0));
        $this->assertSame('b', PregAotFastPath::matchAllPart(1));
        $this->assertSame('c', PregAotFastPath::matchAllPart(2));
        $this->assertSame(0, PregAotFastPath::matchAllStore('/\w+/', '   ', 0, 0));
        $this->assertSame(-1, PregAotFastPath::matchAllStore('/(\w+)/', 'a b', 0, 0));
        $this->assertSame(-1, PregAotFastPath::matchAllStore('/\w+/', 'a b', PREG_SET_ORDER, 0));
    }

    public function testMatchAllLiteralStoresAll(): void
    {
        $this->assertSame(2, PregAotFastPath::matchAllStore('/a/', 'aba', 0, 0));
        $this->assertSame('a', PregAotFastPath::matchAllPart(0));
        $this->assertSame('a', PregAotFastPath::matchAllPart(1));
    }

    public function testSplitStoreWhitespaceParts(): void
    {
        // Host-side splitter (thin AOT uses LLVM replaceFindNext bridge, #27208).
        $this->assertSame(3, PregAotFastPath::splitStore('/\s+/', 'a  b c', -1, 0));
        $this->assertSame(3, PregAotFastPath::splitPartCount());
        $this->assertSame('a', PregAotFastPath::splitPart(0));
        $this->assertSame('b', PregAotFastPath::splitPart(1));
        $this->assertSame('c', PregAotFastPath::splitPart(2));
        $this->assertSame(2, PregAotFastPath::splitStore('/\s+/', 'a b', -1, 0));
        $this->assertSame('a', PregAotFastPath::splitPart(0));
        $this->assertSame('b', PregAotFastPath::splitPart(1));
        $this->assertSame(1, PregAotFastPath::splitStore('/\s+/', 'a  b c', 1, 0));
        $this->assertSame('a  b c', PregAotFastPath::splitPart(0));
        $this->assertSame(0, PregAotFastPath::splitStore('/\s+/', '', -1, 0));
        $this->assertSame(-1, PregAotFastPath::splitStore('/a/', 'xay', -1, 0));
    }
}
