<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** method_exists() JIT routes through MethodExistsJitHelper PHP not inline LLVM (#16479). */
final class MethodExistsRuntimeShrinkTest extends TestCase
{
    public function testJitMethodExistsDelegatesToStringMethodExistsBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitMethodExists.php');
        $this->assertStringContainsString('StringMethodExists::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp')", $source);
        $this->assertStringNotContainsString('existsForClassIdRuntimeMethod', $source);
        $this->assertStringNotContainsString('existsForClassIdLiteralMethodDynamic', $source);
        $this->assertStringNotContainsString('instanceMethodExistsDynamic', $source);
        $this->assertLessThan(270, \substr_count($source, "\n") + 1);
    }

    public function testStringMethodExistsUsesJitHelperNotInlineLlvm(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringMethodExists.php');
        $this->assertStringContainsString('MethodExistsJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringNotContainsString("lookupFunction('strcasecmp')", $source);
    }

    public function testMethodExistsJitHelperDelegatesToVmReflection(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/MethodExistsJitHelper.php');
        $this->assertStringContainsString('VmReflection::methodExists', $source);
        $this->assertStringContainsString('Superglobals::getActiveContext', $source);
    }
}
