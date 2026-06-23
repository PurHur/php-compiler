<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MicrotimeJitHelper;
use PHPUnit\Framework\TestCase;

/** StringMicrotime routes through MicrotimeJitHelper PHP not gettimeofday LLVM (#9181). */
final class StringMicrotimeRuntimeShrinkTest extends TestCase
{
    public function testStringMicrotimeRoutesThroughMicrotimeJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMicrotime.php');
        $this->assertStringContainsString('MicrotimeJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('gettimeofday')", $source);
        $this->assertStringNotContainsString('readWallClock', $source);
        $this->assertLessThan(170, \substr_count($source, "\n") + 1);
    }

    public function testMicrotimeJitHelperDelegatesToVmDate(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/MicrotimeJitHelper.php');
        $this->assertStringContainsString('VmDate::microtime', $source);
    }

    public function testMicrotimeJitHelperSemanticsMatchVmDate(): void
    {
        $float = MicrotimeJitHelper::microtimeFloat();
        $this->assertIsFloat($float);
        $this->assertGreaterThan(0.0, $float);

        $string = MicrotimeJitHelper::microtimeString();
        $this->assertIsString($string);
        $this->assertMatchesRegularExpression('/^\d+\.\d+ \d+$/', $string);
    }
}
