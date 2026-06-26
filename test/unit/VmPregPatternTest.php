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

    public function testVmPregNativeDelegatesPatternParseToVmPregPattern(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPregNative.php');
        $this->assertStringContainsString('VmPregPattern::parsePhpPattern', $source);
        $this->assertStringContainsString('VmPregPattern::patternWarningMessage', $source);
        $this->assertDoesNotMatchRegularExpression('/private static function parsePhpPattern/', $source);
    }

    public function testPregMatchStillWorksAfterPatternExtract(): void
    {
        $this->assertSame(1, VmPregNative::pregMatch('/^a+$/', 'aaa'));
        $this->assertSame(0, VmPregNative::pregMatch('/^a+$/', 'b'));
    }
}
