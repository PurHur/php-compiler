<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

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

    /** Issue #14880 — PCRE compile failures surface Zend-style warning text. */
    public function testCompileWarningMessageForUnclosedGroup(): void
    {
        $this->assertSame(
            'Compilation failed: missing closing parenthesis at offset 1',
            VmPregPattern::compileWarningMessage('/(/')
        );
    }

    /** Issue #16407 — unclosed character class maps to PCRE compile message (ext/pcre/php_pcre.c). */
    public function testCompileWarningMessageForUnclosedCharacterClass(): void
    {
        $this->assertSame(
            'Compilation failed: missing terminating ] for character class at offset 1',
            VmPregPattern::compileWarningMessage('/[/')
        );
    }

    public function testVmPregPureDelegatesPatternParseToVmPregPattern(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPregPure.php');
        $this->assertStringContainsString('VmPregPattern::parsePhpPattern', $source);
        $this->assertStringContainsString('VmPregPattern::patternWarningMessage', $source);
        $native = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPregNative.php');
        $this->assertStringContainsString('VmPregPure::pregMatch', $native);
    }

    public function testPregMatchStillWorksAfterPatternExtract(): void
    {
        $this->assertSame(1, VmPregNative::pregMatch('/^a+$/', 'aaa'));
        $this->assertSame(0, VmPregNative::pregMatch('/^a+$/', 'b'));
    }
}
