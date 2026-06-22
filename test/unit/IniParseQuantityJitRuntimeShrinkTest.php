<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ini_parse_quantity JIT routes through IniParseQuantityJitHelper PHP, not strtoll LLVM (#9237). */
final class IniParseQuantityJitRuntimeShrinkTest extends TestCase
{
    public function testIniParseQuantityJitHelperDelegatesToVmIniQuantity(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/IniParseQuantityJitHelper.php');
        $this->assertStringContainsString('VmIniQuantity::parseQuantity', $source);
    }

    public function testJitIniParseQuantityRoutesThroughIniParseQuantityRuntime(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/JitIniParseQuantity.php');
        $this->assertStringContainsString('IniParseQuantityRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('structGep', $source);
    }

    public function testIniParseQuantityRuntimeRoutesThroughIniParseQuantityJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IniParseQuantityRuntime.php');
        $this->assertStringContainsString('IniParseQuantityJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('strtoll')", $source);
        $this->assertStringNotContainsString('implementParseQuantity', $source);
        $this->assertLessThan(150, \substr_count($source, "\n") + 1);
    }

    public function testIniParseQuantityJitHelperSemanticsMatchVmIniQuantity(): void
    {
        $helper = \PHPCompiler\ext\standard\IniParseQuantityJitHelper::class;
        $this->assertSame(1 << 10, $helper::parseQuantity('1K'));
        $this->assertSame(512, $helper::parseQuantity('512'));
        $this->assertSame(-(1 << 20), $helper::parseQuantity('-1M'));
        $this->assertSame(0, $helper::parseQuantity(''));
    }
}
