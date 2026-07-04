<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** enum_exists()/unitenum_exists() JIT routes through JitHelper PHP not inline LLVM (#16169). */
final class EnumExistsRuntimeShrinkTest extends TestCase
{
    public function testJitEnumExistsDelegatesToStringEnumExistsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitEnumExists.php');
        $this->assertStringContainsString('StringEnumExists::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp'", $source);
        $this->assertStringNotContainsString('BasicBlockHelper', $source);
        $this->assertLessThan(25, \substr_count($source, "\n") + 1);
    }

    public function testJitUnitEnumExistsDelegatesToStringUnitEnumExistsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitUnitEnumExists.php');
        $this->assertStringContainsString('StringUnitEnumExists::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp'", $source);
        $this->assertStringNotContainsString('BasicBlockHelper', $source);
        $this->assertLessThan(25, \substr_count($source, "\n") + 1);
    }

    public function testStringEnumExistsUsesJitHelperNotStrcasecmpLoop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringEnumExists.php');
        $this->assertStringContainsString('EnumExistsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp'", $source);
    }

    public function testEnumExistsJitHelperDelegatesToVmReflection(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/EnumExistsJitHelper.php');
        $this->assertStringContainsString('VmReflection::enumExists', $source);
        $this->assertStringContainsString('Superglobals::getActiveContext', $source);
    }

    public function testUnitEnumExistsJitHelperDelegatesToVmReflection(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/UnitEnumExistsJitHelper.php');
        $this->assertStringContainsString('VmReflection::unitEnumExists', $source);
        $this->assertStringContainsString('Superglobals::getActiveContext', $source);
    }

    public function testSpineBundleIncludesEnumExistsJitHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('EnumExistsJitHelper.php', $spine);
        $this->assertStringContainsString('UnitEnumExistsJitHelper.php', $spine);
        $this->assertStringContainsString('StringEnumExists.php', $spine);
        $this->assertStringContainsString('StringUnitEnumExists.php', $spine);
    }
}
