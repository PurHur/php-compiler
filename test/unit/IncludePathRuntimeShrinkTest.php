<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** IncludePathRuntime embed uses JIT helper; standalone AOT keeps thin LLVM quarantine (#9245, #12801, #13571). */
final class IncludePathRuntimeShrinkTest extends TestCase
{
    public function testIncludePathRuntimeUsesJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IncludePathRuntime.php');
        $this->assertStringContainsString('IncludePathJitHelper', $source);
        $this->assertStringContainsString('IncludePathResolveJitHelper', $source);
        $this->assertStringContainsString('LOAD_TYPE_STANDALONE', $source);
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/IncludePathStandaloneLlvm.php');
    }
}
