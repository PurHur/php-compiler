<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MicrotimeJitHelper;
use PHPUnit\Framework\TestCase;

/** StringMicrotime emits gettimeofday LLVM (NestedJIT SEGV under thin AOT, #26930). */
final class StringMicrotimeRuntimeShrinkTest extends TestCase
{
    public function testStringMicrotimeUsesLibcGettimeofday(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMicrotime.php');
        $this->assertStringContainsString("lookupFunction('gettimeofday')", $source);
        $this->assertStringContainsString('__phpc_microtime_wall_usec', $source);
        $this->assertStringContainsString('tryGetInsertBlock', $source);
        $this->assertStringNotContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertLessThan(320, \substr_count($source, "\n") + 1);
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
