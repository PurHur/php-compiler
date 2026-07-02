<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** array_find family string-builtin JIT routes through ArrayFindJitHelper PHP not ArrayFindHelper LLVM (#14842). */
final class ArrayFindRuntimeShrinkTest extends TestCase
{
    public function testArrayFindRuntimeUsesJitHelperNotDirectLlvmMonolith(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayFindHelper.php');
        $this->assertStringContainsString('ArrayFindRuntime::walk', $helper);
        $this->assertStringContainsString('ArrayMapCallbackPolicy::isJitLowerableScalar', $helper);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayFindRuntime.php');
        $this->assertStringContainsString('ArrayFindJitHelper', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
    }
}
