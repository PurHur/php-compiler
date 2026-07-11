<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** class_exists()/interface_exists() JIT routes through JitHelper PHP not inline LLVM (#16185). */
final class ClassInterfaceExistsRuntimeShrinkTest extends TestCase
{
    public function testJitClassExistsDelegatesToStringClassExistsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitClassExists.php');
        $this->assertStringContainsString('StringClassExists::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp'", $source);
        $this->assertStringContainsString('stringDataPtr', $source);
        $this->assertLessThan(35, \substr_count($source, "\n") + 1);
    }

    public function testJitInterfaceExistsDelegatesToStringInterfaceExistsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitInterfaceExists.php');
        $this->assertStringContainsString('StringInterfaceExists::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp'", $source);
        $this->assertStringNotContainsString('JitClassExists::stringDataPtr', $source);
        $this->assertLessThan(35, \substr_count($source, "\n") + 1);
    }

    public function testStringClassExistsUsesJitHelperNotStrcasecmpLoop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringClassExists.php');
        $this->assertStringContainsString('ClassExistsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp'", $source);
    }

    public function testStringInterfaceExistsUsesJitHelperNotStrcasecmpLoop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringInterfaceExists.php');
        $this->assertStringContainsString('InterfaceExistsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp'", $source);
    }

    public function testJitHelpersDelegateToVmReflection(): void
    {
        $classHelper = (string) file_get_contents(__DIR__.'/../../ext/standard/ClassExistsJitHelper.php');
        $this->assertStringContainsString('VmReflection::classExists', $classHelper);
        $this->assertStringContainsString('Superglobals::getActiveContext', $classHelper);

        $interfaceHelper = (string) file_get_contents(__DIR__.'/../../ext/standard/InterfaceExistsJitHelper.php');
        $this->assertStringContainsString('VmReflection::interfaceExists', $interfaceHelper);
        $this->assertStringContainsString('Superglobals::getActiveContext', $interfaceHelper);
    }

    public function testSpineBundleIncludesClassInterfaceExistsJitHelpers(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ClassExistsJitHelper.php', $spine);
        $this->assertStringContainsString('InterfaceExistsJitHelper.php', $spine);
        $this->assertStringContainsString('StringClassExists.php', $spine);
        $this->assertStringContainsString('StringInterfaceExists.php', $spine);
    }
}
