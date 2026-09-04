<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmPregPure;
use PHPUnit\Framework\TestCase;

/**
 * VmPregPure `\b`/`\B` + `[^]…]` first-`]` literal (#36380 Parsedown).
 *
 * php-src: ext/pcre/php_pcre.c + pcre2pattern (`\b`, character class `]` rules).
 */
final class VmPregWordBoundaryAndClassBracket36380Test extends TestCase
{
    public function testWordBoundaryMatchesZend(): void
    {
        $cases = [
            ['/_x_\b/', '_x_', 1],
            ['/_x_\b/us', '_x_', 1],
            ['/abc\b/', 'abc', 1],
            ['/abc\b/u', 'abc', 1],
            ['/\babc/', 'abc', 1],
            ['/\Babc/', 'abc', 0],
            ['/a\Bb/', 'ab', 1],
            ['/_((?:\\\\_|[^_]|__[^_]*__)+?)_(?!_)\b/us', '_underscore_', 1],
        ];
        foreach ($cases as [$pattern, $subject, $expected]) {
            $matches = [];
            $got = VmPregPure::pregMatch($pattern, $subject, $matches);
            $this->assertSame($expected, $got, $pattern.' on '.$subject);
        }
    }

    public function testNegatedClassWithLeadingBracketMatchesZend(): void
    {
        $cases = [
            ['/[^][]+/', 'ab]c', 1, 'ab'],
            ['/[^][]+/', 'a', 1, 'a'],
            ['/\[(?:[^][]++|(?R))*+\]/', '[a]', 1, '[a]'],
            ['/\[(?:[^][]++|(?R))*+\]/', '[a[b]]', 1, '[a[b]]'],
            ['/\[((?:[^][]++|(?R))*+)\]/', '[link](http://example.com)', 1, '[link]'],
        ];
        foreach ($cases as [$pattern, $subject, $expected, $m0]) {
            $matches = [];
            $got = VmPregPure::pregMatch($pattern, $subject, $matches);
            $this->assertSame($expected, $got, $pattern.' on '.$subject);
            if (1 === $expected) {
                $this->assertSame($m0, $matches[0] ?? null, 'm0 '.$pattern);
            }
        }
    }
}
