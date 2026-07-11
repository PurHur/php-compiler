<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PregJitHelper;
use PHPCompiler\ext\standard\VmPregNative;
use PHPCompiler\ext\standard\VmPregPure;
use PHPUnit\Framework\TestCase;

/** VmPregNative libpcre2 FFI removed — SSOT VmPregPure (#8935, #1492). */
final class VmPregNativeRuntimeShrinkTest extends TestCase
{
    public function testVmPregNativeDelegatesToPureWithoutFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPregNative.php');
        $this->assertStringContainsString('VmPregPure::pregMatch', $source);
        $this->assertStringContainsString('VmPregPure::pregReplace', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('libpcre2', $source);
        $this->assertStringNotContainsString('pcre2_match_8', $source);
    }

    public function testVmPregPureUsesEngineNotFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmPregPure.php');
        $this->assertStringContainsString('VmPregEngine::compile', $source);
        $this->assertStringContainsString('VmPregEngine::match', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('pcre2_match_8', $source);
    }

    public function testPregJitHelperStillRoutesThroughNativeFacade(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PregJitHelper.php');
        $this->assertStringContainsString('VmPregNative::pregMatch', $source);
        $this->assertStringContainsString('VmPregNative::pregReplaceCallbackJit', $source);
    }

    public function testProbeMatchesZendViaPurePath(): void
    {
        $matches = [];
        $expected = \preg_match('/^a+$/', 'aaa', $matches);
        $nativeMatches = [];
        $native = VmPregNative::pregMatch('/^a+$/', 'aaa', $nativeMatches);
        $this->assertSame($expected, $native);
        $this->assertSame($matches, $nativeMatches);

        $expectedReplace = \preg_replace('/a/', 'b', 'aba');
        $this->assertSame($expectedReplace, VmPregPure::pregReplace('/a/', 'b', 'aba'));
        $this->assertSame($expectedReplace, PregJitHelper::replaceArgv('/a/', 'b', 'aba', -1));
    }
}
