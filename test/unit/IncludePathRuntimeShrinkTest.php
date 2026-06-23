<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** IncludePathRuntime must route through IncludePathJitHelper PHP, not LLVM globals (#9245). */
final class IncludePathRuntimeShrinkTest extends TestCase
{
    public function testIncludePathRuntimeUsesJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IncludePathRuntime.php');
        $this->assertStringContainsString('IncludePathJitHelper', $source);
        $this->assertStringContainsString('IncludePathResolveJitHelper', $source);
        $this->assertStringNotContainsString("addGlobal(\$i32, 'phpc_include_path_depth')", $source);
        $this->assertStringNotContainsString('phpc_include_path_current', $source);
        $this->assertStringNotContainsString('phpc_include_path_stack', $source);
        $this->assertStringNotContainsString("lookupFunction('access')", $source);
        $this->assertStringNotContainsString("lookupFunction('realpath')", $source);
    }

    public function testVmIncludePathDelegatesToJitHelper(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/VmIncludePath.php');
        $this->assertStringContainsString('IncludePathJitHelper', $source);
        $this->assertStringNotContainsString('private static array $stack', $source);
    }

    public function testJitIncludePathUsesIncludePathRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitIncludePath.php');
        $this->assertStringContainsString('IncludePathRuntime', $source);
    }
}
