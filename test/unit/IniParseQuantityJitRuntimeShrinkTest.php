<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ini_parse_quantity JIT routes through IniParseQuantityJitHelper PHP (#9237 / #26444).
 *
 * NestedJIT via {@see \PHPCompiler\JIT\JitVmHelperLink::ensureCompiled} (peer #26441 / #25570).
 */
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

    public function testIniParseQuantityRuntimeUsesJitVmHelperLink(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IniParseQuantityRuntime.php');
        $this->assertStringContainsString('IniParseQuantityJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString("lookupFunction('strtoll')", $source);
        $this->assertStringNotContainsString('implementParseQuantity', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $source);
        $this->assertLessThan(130, \substr_count($source, "\n") + 1);
    }

    public function testIniParseQuantityJitHelperSemanticsMatchVmIniQuantity(): void
    {
        $helper = \PHPCompiler\ext\standard\IniParseQuantityJitHelper::class;
        $this->assertSame(1 << 10, $helper::parseQuantity('1K'));
        $this->assertSame(512, $helper::parseQuantity('512'));
        $this->assertSame(-(1 << 20), $helper::parseQuantity('-1M'));
        $this->assertSame(0, $helper::parseQuantity(''));
        // Legacy leading-zero octal (#28763 / zend_ini_parse_quantity)
        $this->assertSame(8, $helper::parseQuantity('010'));
        $this->assertSame(-8, $helper::parseQuantity('-010'));
        $this->assertSame(0, $helper::parseQuantity('08'));
        $this->assertSame(8, $helper::parseQuantity('0o10'));
        $this->assertSame(16, $helper::parseQuantity('0x10'));
    }
}
