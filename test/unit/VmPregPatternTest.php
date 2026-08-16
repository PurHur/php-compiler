<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\VmPregCompileWarn;
use PHPCompiler\ext\standard\VmPregNative;
use PHPCompiler\ext\standard\VmPregPattern;
use PHPUnit\Framework\TestCase;

/** VmPregPattern — shared PHP pattern parse for VmPregNative / future VmPregPure (#8935). */
final class VmPregPatternTest extends TestCase
{
    public function testParsePhpPatternExtractsBodyAndFlags(): void
    {
        $parsed = VmPregPattern::parsePhpPattern('/foo/im');
        $this->assertIsArray($parsed);
        $this->assertSame('foo', $parsed[0]);
        $this->assertSame(0x00000008 | 0x00000400, $parsed[1]);
    }

    public function testPatternWarningMessageForEmptyPattern(): void
    {
        $this->assertSame('Empty regular expression', VmPregPattern::patternWarningMessage(''));
    }

    /** Bracket delimiters use matching closer (#31511 / php_pcre.c). */
    public function testPatternWarningMessageForUnclosedBracketDelimiter(): void
    {
        $this->assertSame(
            "No ending matching delimiter ')' found",
            VmPregPattern::patternWarningMessage('(')
        );
        $this->assertSame(
            "No ending matching delimiter ']' found",
            VmPregPattern::patternWarningMessage('[a')
        );
    }

    public function testParsePhpPatternAcceptsBracketDelimiters(): void
    {
        $parsed = VmPregPattern::parsePhpPattern('(a)');
        $this->assertIsArray($parsed);
        $this->assertSame('a', $parsed[0]);
        $nested = VmPregPattern::parsePhpPattern('(a(b)c)');
        $this->assertIsArray($nested);
        $this->assertSame('a(b)c', $nested[0]);
    }

    /** Issue #14880 — PCRE compile failures surface Zend-style warning text. */
    public function testCompileWarningMessageForUnclosedGroup(): void
    {
        $this->assertSame(
            'Compilation failed: missing closing parenthesis at offset 1',
            VmPregCompileWarn::compileWarningMessage('/(/')
        );
    }

    /** Issue #16407 — unclosed character class maps to PCRE compile message (ext/pcre/php_pcre.c). */
    public function testCompileWarningMessageForUnclosedCharacterClass(): void
    {
        $this->assertSame(
            'Compilation failed: missing terminating ] for character class at offset 1',
            VmPregCompileWarn::compileWarningMessage('/[/')
        );
    }

    /** Issue #17584 — duplicate named subpatterns without PCRE2_DUPNAMES. */
    public function testCompileWarningMessageForDuplicateNamedSubpatterns(): void
    {
        $this->assertSame(
            'Compilation failed: two named subpatterns have the same name (PCRE2_DUPNAMES not set) at offset 12',
            VmPregCompileWarn::compileWarningMessage('/(?<x>a)(?<x>b)/')
        );
    }

    public function testPregMatchRejectsDuplicateNamedSubpatterns(): void
    {
        $matches = [];
        $this->assertFalse(VmPregNative::pregMatch('/(?<x>a)(?<x>b)/', 'ab', $matches));
        $this->assertSame(1, VmPregNative::lastError());
        $this->assertSame([], $matches);
    }

    public function testPregMatchAllowsDuplicateNamedSubpatternsWithJModifier(): void
    {
        $matches = [];
        $this->assertSame(1, VmPregNative::pregMatch('/(?<x>a)(?<x>b)/J', 'ab', $matches));
        $this->assertSame(0, VmPregNative::lastError());
        $this->assertSame('b', $matches['x']);
    }

    public function testVmPregPureDelegatesPatternParseToVmPregPattern(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPregPure.php');
        $this->assertStringContainsString('VmPregPattern::parsePhpPattern', $source);
        $this->assertStringContainsString('VmPregCompileWarn::compileWarningMessage', $source);
        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPregNative.php');
        $this->assertStringContainsString('VmPregPure::pregMatch', $native);
    }

    public function testPregMatchStillWorksAfterPatternExtract(): void
    {
        $this->assertSame(1, VmPregNative::pregMatch('/^a+$/', 'aaa'));
        $this->assertSame(0, VmPregNative::pregMatch('/^a+$/', 'b'));
    }
}
