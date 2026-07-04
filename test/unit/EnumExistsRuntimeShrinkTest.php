<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\EnumExistsJitHelper;
use PHPCompiler\ext\standard\UnitEnumExistsJitHelper;
use PHPUnit\Framework\TestCase;

/** enum_exists()/unitenum_exists() JIT routes through PHP helpers not inline LLVM (#16169). */
final class EnumExistsRuntimeShrinkTest extends TestCase
{
    public function testJitEnumExistsDelegatesToStringEnumExistsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitEnumExists.php');
        $this->assertStringContainsString('StringEnumExists::invoke', $source);
        $this->assertStringNotContainsString('strcasecmp', $source);
        $this->assertStringNotContainsString('allDeclaredEnumLowerNames', $source);
        $this->assertLessThan(25, \substr_count($source, "\n") + 1);
    }

    public function testJitUnitEnumExistsDelegatesToStringUnitEnumExistsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitUnitEnumExists.php');
        $this->assertStringContainsString('StringUnitEnumExists::invoke', $source);
        $this->assertStringNotContainsString('strcasecmp', $source);
        $this->assertStringNotContainsString('allDeclaredUnitEnumLowerNames', $source);
        $this->assertLessThan(25, \substr_count($source, "\n") + 1);
    }

    public function testEnumExistsRuntimeUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EnumExistsRuntime.php');
        $this->assertStringContainsString('EnumExistsJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp')", $source);
        $this->assertStringNotContainsString('StringCaseCompare::', $source);
    }

    public function testUnitEnumExistsRuntimeUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UnitEnumExistsRuntime.php');
        $this->assertStringContainsString('UnitEnumExistsJitHelper', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp')", $source);
        $this->assertStringNotContainsString('StringCaseCompare::', $source);
    }

    public function testEnumExistsJitHelperWithoutActiveContextReturnsFalse(): void
    {
        $this->assertFalse(EnumExistsJitHelper::exists('MissingEnum'));
        $this->assertFalse(UnitEnumExistsJitHelper::exists('MissingEnum'));
    }
}
