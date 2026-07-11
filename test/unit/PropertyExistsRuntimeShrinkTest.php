<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** property_exists() JIT routes through PropertyExistsJitHelper PHP not inline LLVM. */
final class PropertyExistsRuntimeShrinkTest extends TestCase
{
    public function testJitPropertyExistsDelegatesToStringPropertyExistsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPropertyExists.php');
        $this->assertStringContainsString('StringPropertyExists::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp')", $source);
        $this->assertStringNotContainsString('existsForClassIdRuntimeProperty', $source);
        $this->assertStringNotContainsString('forClassLiteralRuntimeProperty', $source);
        $this->assertLessThan(270, \substr_count($source, "\n") + 1);
    }

    public function testStringPropertyExistsUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringPropertyExists.php');
        $this->assertStringContainsString('PropertyExistsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp')", $source);
    }

    public function testPropertyExistsJitHelperDelegatesToVmReflection(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PropertyExistsJitHelper.php');
        $this->assertStringContainsString('VmReflection::propertyExists', $source);
        $this->assertStringContainsString('Superglobals::getActiveContext', $source);
    }
}
