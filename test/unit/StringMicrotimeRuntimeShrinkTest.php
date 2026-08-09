<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MicrotimeJitHelper;
use PHPUnit\Framework\TestCase;

/** StringMicrotime: JitVmHelperLink + NestedJIT gettimeofday leaf (#29405). */
final class StringMicrotimeRuntimeShrinkTest extends TestCase
{
    public function testStringMicrotimeRoutesThroughJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMicrotime.php');
        $this->assertStringContainsString('MicrotimeJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('JitMicrotimeKernel::invokeFloat', $source);
        $this->assertStringNotContainsString("lookupFunction('gettimeofday')", $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertLessThan(150, \substr_count($source, "\n") + 1);
    }

    public function testMicrotimeJitHelperUsesHostMicrotime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/MicrotimeJitHelper.php');
        $this->assertStringContainsString('@\\microtime', $source);
    }

    public function testMicrotimeJitHelperSemanticsMatchHost(): void
    {
        $float = MicrotimeJitHelper::microtimeFloat();
        $this->assertIsFloat($float);
        $this->assertGreaterThan(0.0, $float);

        $string = MicrotimeJitHelper::microtimeString();
        $this->assertIsString($string);
        $this->assertMatchesRegularExpression('/^\d+\.\d+ \d+$/', $string);
    }
}
