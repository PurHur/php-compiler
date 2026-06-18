<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmPregNative;
use PHPUnit\Framework\TestCase;

/** VM-native preg_* via libpcre2 FFI (issue #4874). */
final class VmPregNativeTest extends TestCase
{
    public function testPregMatchMatchesZend(): void
    {
        $matches = [];
        $expected = \preg_match('/^a+$/', 'aaa', $matches);
        $nativeMatches = [];
        $native = VmPregNative::pregMatch('/^a+$/', 'aaa', $nativeMatches);
        $this->assertSame($expected, $native);
        $this->assertSame($matches, $nativeMatches);
    }

    public function testPregMatchNoMatch(): void
    {
        $this->assertSame(0, VmPregNative::pregMatch('/^a+$/', 'b'));
    }

    public function testPregReplaceMatchesZend(): void
    {
        $expected = \preg_replace('/\d+/', 'X', 'id42');
        $actual = VmPregNative::pregReplace('/\d+/', 'X', 'id42');
        $this->assertSame($expected, $actual);
    }

    public function testPregReplaceNumericBackreferencesMatchZend(): void
    {
        $cases = [
            ['/([0-9]+)/', '[$1]', 'x12y'],
            ['/(\d)/', '${1}x', 'a9b'],
            ['/(.) (.)/', '$2$1', 'ab'],
        ];
        foreach ($cases as [$pattern, $replacement, $subject]) {
            $expected = \preg_replace($pattern, $replacement, $subject);
            $actual = VmPregNative::pregReplace($pattern, $replacement, $subject);
            $this->assertSame($expected, $actual, $pattern.' / '.$replacement);
        }
    }

    public function testVmPregDoesNotCallHostPreg(): void
    {
        $root = \dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/ext/standard/VmPreg.php');
        $this->assertDoesNotMatchRegularExpression('/\\\\preg_(match|match_all|replace|split|filter)\s*\(/', $src);
        $this->assertDoesNotMatchRegularExpression('/\\\\preg_last_error\s*\(/', $src);
    }
}
