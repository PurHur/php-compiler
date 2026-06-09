<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\ParseStrEngine;
use PHPUnit\Framework\TestCase;

/** Issue #6308: native parse_str engine matches Zend without host VM delegation. */
final class ParseStrEngineTest extends TestCase
{
    public function testMatchesZendFlatAndNested(): void
    {
        $encoded = 'a=1&user%5Bname%5D=Ada&tags%5B%5D=a&tags%5B%5D=b';
        $zend = [];
        \parse_str($encoded, $zend);
        $native = ParseStrEngine::parse($encoded);
        $this->assertSame($zend, $native);
    }

    public function testMatchesZendBracketNestingAndListAppend(): void
    {
        $encoded = 'a=1&b[c]=2&b[d][]=3&b[d][]=4';
        $zend = [];
        \parse_str($encoded, $zend);
        $native = ParseStrEngine::parse($encoded);
        $this->assertSame($zend, $native);
    }

    public function testUrlDecodesPlusAndPercent(): void
    {
        $encoded = 'k=a+b%20c';
        $zend = [];
        \parse_str($encoded, $zend);
        $native = ParseStrEngine::parse($encoded);
        $this->assertSame($zend, $native);
    }

    public function testParseDelimitedCookieHeaderMatchesFormForSimplePairs(): void
    {
        $encoded = 'session=abc; path=%2F';
        $native = ParseStrEngine::parseDelimited($encoded, ';', true);
        $this->assertSame(['session' => 'abc', 'path' => '/'], $native);
    }

    public function testParseDelimitedCookieTrimsWhitespace(): void
    {
        $encoded = ' a=1 ; b=2 ';
        $native = ParseStrEngine::parseDelimited($encoded, ';', true);
        $this->assertSame(['a' => '1', 'b' => '2'], $native);
    }
}
