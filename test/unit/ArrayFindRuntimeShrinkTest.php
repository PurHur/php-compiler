<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** array_find family string-builtin JIT uses LLVM ArrayFindHelper until nested PHP walk is AOT-safe (#4009). */
final class ArrayFindRuntimeShrinkTest extends TestCase
{
    public function testArrayFindStringBuiltinUsesLlvmNotNestedPhpWalk(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/ArrayFindHelper.php');
        $this->assertStringNotContainsString('ArrayFindRuntime::walk', $helper);
        $this->assertStringContainsString('resolvePredicateHandler', $helper);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ArrayFindRuntime.php');
        $this->assertStringContainsString('ArrayFindJitHelper', $runtime);
        $this->assertStringNotContainsString('LOAD_TYPE_STANDALONE', $runtime);
    }
}
